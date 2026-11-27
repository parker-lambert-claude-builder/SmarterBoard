<?php
/** Pad items: types, values, versions, permissions, limits, events. */
declare(strict_types=1);

$root = dirname(__DIR__);
$env  = fn ($k, $d = '') => getenv($k) !== false ? (string) getenv($k) : $d;
$db   = ['host' => $env('MASHPAD_TEST_DB_HOST', '127.0.0.1'), 'port' => (int) $env('MASHPAD_TEST_DB_PORT', '3306'),
         'name' => $env('MASHPAD_TEST_DB_NAME'), 'user' => $env('MASHPAD_TEST_DB_USER'), 'pass' => $env('MASHPAD_TEST_DB_PASS')];
if (!str_starts_with($db['name'], 'mashpad_test')) {
    fwrite(STDERR, "skipped: set MASHPAD_TEST_DB_NAME to a disposable database named mashpad_test*\n");
    exit(0);
}
$files = ["$root/data/config.php", "$root/data/schema-version", "$root/data/.last-pad-prune"];
$saved = [];
foreach ($files as $f) { if (is_file($f)) { $saved[$f] = file_get_contents($f); unlink($f); } }
register_shutdown_function(function () use ($files, $saved) {
    foreach ($files as $f) { @unlink($f); if (isset($saved[$f])) file_put_contents($f, $saved[$f]); }
});
$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail; $ok ? $pass++ : $fail++;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
function err(callable $fn): string {
    try { $fn(); } catch (Throwable $t) { return get_class($t) . ': ' . $t->getMessage(); }
    return '(no error)';
}
require "$root/src/db.php";
$pdo = mb_db_connect($db);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec("DROP TABLE `$t`"); }
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
mb_migrate($pdo);
mb_write_config($db);
require_once "$root/src/invites.php";
require_once "$root/src/publicpads.php";
$pdo->exec('UPDATE plans SET max_human_seats = 1, max_agents = 3, post_interval_seconds = 0');
$mk = fn (string $h, bool $admin = false) => mb_create_human($pdo, ucfirst($h), $h, "$h@example.com", 'correct horse battery', $admin);
$dylanId = $mk('dylan', true); $samId = $mk('sam'); $leeId = $mk('lee');
$P = fn (int $id) => mb_principal($pdo, $id);
$dylan = $P($dylanId); $sam = $P($samId); $lee = $P($leeId);
$cnt = fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();
$agent = fn (int $owner, string $h) => mb_create_agent($pdo, $owner, ['handle' => $h])[0];
$muse = $agent($dylanId, 'muse'); $samBot = $agent($samId, 'sams-bot');

// ---- the account that owns every public pad
$sys = mb_public_owner($pdo);
check('one system account owns public pads: handle "public", no email, no password, so no one can sign in as it', $cnt("SELECT COUNT(*) FROM principals WHERE handle = 'public'") === 1 && $pdo->query("SELECT email IS NULL AND password_hash IS NULL AND kind = 'human' FROM principals WHERE id = $sys")->fetchColumn() == 1);
foreach (['public', 'admin', 'system', 'mashpad'] as $h) {
    check("nobody can take the handle $h", str_contains(err(fn () => mb_validate_handle($h)), 'a name Mashpad keeps for its own use'));
}
check('it is not listed among the people in the administrator\'s accounts', !in_array('public', array_column(mb_admin_accounts($pdo), 'handle'), true) && in_array('sam', array_column(mb_admin_accounts($pdo), 'handle'), true));
check('"public" and "public-pads" are names no private pad can take', in_array('public', MB_RESERVED_PAD_NAMES, true) && in_array('public-pads', MB_RESERVED_PAD_NAMES, true));

// ---- only an administrator creates and removes them
check('a person who is not an administrator can\'t create one, and nothing is made', str_contains(err(fn () => mb_public_create($pdo, $sam, 'Lounge')), 'Only an administrator can do that') && $cnt("SELECT COUNT(*) FROM pads WHERE visibility = 'public'") === 0);
$news = mb_public_create($pdo, $dylan, 'News and Announcements', "# What this is\n\nAnnouncements from Mashpad. Read it to know what changed.", true, true);
check('an administrator creates one, named public/<name>, owned by the system account', mb_pad_ref($news) === 'public/news-and-announcements' && (int) $news['owner_id'] === $sys && $news['visibility'] === 'public' && (int) $news['humans_can_join'] === 1 && (int) $news['read_only'] === 1);
check('it can be found by its address, and only as a public pad', mb_public_pad($pdo, 'news-and-announcements')['id'] == $news['id'] && mb_public_pad_from_ref($pdo, 'public/news-and-announcements') !== null && mb_public_pad_from_ref($pdo, 'https://mashpad.ai/pads/public/news-and-announcements')['id'] == $news['id'] && mb_public_pad_from_ref($pdo, 'dylan/news-and-announcements') === null);
check('its About text is saved, written by the administrator', ($b = mb_brief($pdo, (int) $news['id'])) && str_contains($b['body'], 'Announcements from Mashpad') && $b['author'] === 'dylan');
check('it is recorded', $cnt("SELECT COUNT(*) FROM audit_log WHERE action = 'public_pad.create' AND actor_id = $dylanId") === 1);
check('a name must be given, not be reserved, and not already be taken', str_contains(err(fn () => mb_public_create($pdo, $dylan, '  ')), 'Give the pad a name') && str_contains(err(fn () => mb_public_create($pdo, $dylan, 'Public')), 'a name Mashpad keeps') && str_contains(err(fn () => mb_public_create($pdo, $dylan, 'News and announcements')), 'already a public pad called public/news-and-announcements'));
$agentsOnly = mb_public_create($pdo, $dylan, 'Agent Lounge', 'For agents to talk.', false, false);
$open = mb_public_create($pdo, $dylan, 'Open Floor');
check('a pad can be for agents only, and by default people can join and anyone who has joined can post', (int) $agentsOnly['humans_can_join'] === 0 && (int) $open['humans_can_join'] === 1 && (int) $open['read_only'] === 0);
for ($i = 1; $i <= 12; $i++) { mb_public_create($pdo, $dylan, "Filler $i"); }
check('they count against no one\'s plan: an administrator on Free can have more of them than a Free account may own', $cnt("SELECT COUNT(*) FROM pads WHERE visibility = 'public'") === 15);

// ---- joining: an account is needed, there is no code, no approval and no seat limit
$sp = fn (array $who, array $pad) => mb_public_join($pdo, $who, $pad);
[$j1, $already] = $sp($sam, $news);
check('a person with an account joins in one step, with no code', $already === false && mb_membership($pdo, (int) $news['id'], $samId) !== null && $pdo->query("SELECT joined_via FROM pad_members WHERE pad_id = {$news['id']} AND principal_id = $samId")->fetchColumn() === 'public');
check('joining again is harmless', $sp($sam, $news)[1] === true && $cnt("SELECT COUNT(*) FROM pad_members WHERE pad_id = {$news['id']} AND principal_id = $samId") === 1);
$sp($lee, $news); $more = array_map(fn ($h) => $mk($h), ['pat', 'kim', 'joe']); foreach ($more as $m) { $sp($P($m), $news); }
check('there is no seat limit on people, though the owner\'s plan would allow one', $cnt("SELECT COUNT(*) FROM pad_members WHERE pad_id = {$news['id']} AND removed_at IS NULL") === 6);
check('an agent with an account joins too', $sp($P($muse), $news)[1] === false && $sp($P($samBot), $news)[1] === false);
$guest = mb_invite_guest_agent($pdo, mb_create_pad($pdo, $sam, 'Sams Launch'), ['handle' => 'stranger', 'acts_for' => 'Lee'], false)[0];
check('an agent that joined on its own and isn\'t claimed can\'t', str_contains(err(fn () => $sp($P((int) $guest['id']), $news)), 'hasn\'t been claimed'));
$gone = $mk('gone-person'); $pdo->exec("UPDATE principals SET disabled_at = UTC_TIMESTAMP() WHERE id = $gone");
check('a disabled account can\'t', str_contains(err(fn () => $sp($P($gone), $news)), 'You need an account'));
check('a pad for agents only refuses people, and welcomes agents', str_contains(err(fn () => $sp($sam, $agentsOnly)), 'is for agents only') && $sp($P($muse), $agentsOnly)[1] === false && $sp($P($samBot), $agentsOnly)[1] === false);
check('an administrator may join it anyway', $sp($dylan, $agentsOnly)[1] === true);
check('a private pad is not joinable this way', str_contains(err(fn () => mb_public_join($pdo, $sam, mb_create_pad($pdo, $lee, 'Lees Pad'))), 'isn\'t a public pad'));
$twin = $agent($leeId, 'muse');
check('a name already on the pad is refused, since names must be unique on a pad', str_contains(err(fn () => $sp($P($twin), $news)), 'already goes by @muse'));
check('no "joined" notice is posted on a public pad', $cnt("SELECT COUNT(*) FROM messages WHERE pad_id = {$news['id']} AND kind = 'event'") === 0);

// ---- reading needs an account and being on it
check('someone who has not joined can\'t read it, by address or by name', mb_pad_for($pdo, 'public/open-floor', $leeId) === null && mb_pad_for($pdo, 'public/open-floor', $samId) === null);
check('and once they join they can', $sp($lee, $open) && mb_pad_for($pdo, 'public/open-floor', $leeId)['id'] == $open['id']);

// ---- posting, and a read-only pad
$sp($sam, $open); mb_pad_post($pdo, $open, $sam, 'Hello everyone');
check('a member can post on a pad that is not read-only', $cnt("SELECT COUNT(*) FROM messages WHERE pad_id = {$open['id']} AND kind = 'post' AND body = 'Hello everyone'") === 1);
$e = err(fn () => mb_pad_post($pdo, $news, $sam, 'Can I say something?'));
check('on a read-only pad a member can read but not post, in plain words, and nothing is saved', str_contains($e, 'this is a read-only public pad, for announcements. Only Mashpad\'s administrators can post here. Your text was not saved.') && $cnt("SELECT COUNT(*) FROM messages WHERE pad_id = {$news['id']} AND kind = 'post'") === 0, $e);
check('an agent can\'t either', str_contains(err(fn () => mb_pad_post($pdo, $news, $P($muse), 'hello')), 'read-only public pad'));
mb_pad_post($pdo, $news, $dylan, 'Mashpad 1.2 is out.');
check('an administrator can post', $cnt("SELECT COUNT(*) FROM messages WHERE pad_id = {$news['id']} AND kind = 'post'") === 1);
$read = mb_pad_read($pdo, mb_pad_for($pdo, 'public/news-and-announcements', $samId), null, 50);
check('members read the announcement, and the data says the pad is public and read-only', count(array_filter($read['messages'] ?? [], fn ($m) => $m['body'] === 'Mashpad 1.2 is out.')) === 1 && $read['public'] === true && $read['read_only'] === true);
check('the About text of a public pad is written by an administrator, not by anyone else, and not by its system owner', mb_can_edit_about($news, $dylan) === true && mb_can_edit_about($news, $sam) === false && mb_can_edit_about($news, $P($sys)) === false && mb_can_edit_about(mb_create_pad($pdo, $lee, 'Lees Own'), $lee) === true);

// ---- they don't expire
$pdo->exec("UPDATE pads SET last_activity_at = UTC_TIMESTAMP() - INTERVAL 400 DAY WHERE id IN ({$news['id']}, " . mb_pad_by_address($pdo, 'lee', 'lees-own')['id'] . ")");
mb_prune_owned($pdo, true);
check('an old private pad expires, a public pad does not', $cnt("SELECT COUNT(*) FROM pads WHERE id = {$news['id']}") === 1 && mb_pad_by_address($pdo, 'lee', 'lees-own') === null);

// ---- the lists
$joined = mb_public_list($pdo, $samId, true);
check('a person\'s list is the public pads they are on, and shows the reference', in_array('public/news-and-announcements', array_column($joined, 'ref'), true) && in_array('public/open-floor', array_column($joined, 'ref'), true) && !in_array('public/agent-lounge', array_column($joined, 'ref'), true));
$byRef = array_column($joined, null, 'ref');
check('with its read-only flag, the member count and the About text cut to a line', (int) $byRef['public/news-and-announcements']['read_only'] === 1 && (int) $byRef['public/news-and-announcements']['members'] >= 8 && str_starts_with($byRef['public/news-and-announcements']['about_line'], 'What this is Announcements from Mashpad'));
$unjoined = mb_public_list($pdo, $samId, false);
$m = array_map('intval', array_column($unjoined, 'members')); $sorted = $m; rsort($sorted);
check('the others are the ones not joined yet, most members first', !in_array('public/news-and-announcements', array_column($unjoined, 'ref'), true) && !in_array('public/open-floor', array_column($unjoined, 'ref'), true) && count($unjoined) >= 1 && $m === $sorted);
check('a long list is capped', count(mb_public_list($pdo, $samId, false, 3)) === 3 && count(mb_public_list($pdo, 0, false, 200)) === 15);
check('the pad list a person has carries which are public', ($pl = array_column(mb_pads_for($pdo, $samId), null, 'ref')) && $pl['public/news-and-announcements']['visibility'] === 'public' && $pl['sam/sams-launch']['visibility'] === 'private');
check('the about line strips markdown and is cut with an ellipsis', mb_public_about_line("# T\n\n**bold** and `code`") === 'T bold and code' && mb_strlen(mb_public_about_line(str_repeat('word ', 80))) <= 160 && str_ends_with(mb_public_about_line(str_repeat('word ', 80)), '…'));

// ---- leaving
mb_public_leave($pdo, $sam, $open);
check('a person can leave, and then can\'t read it, and it is back among those to join', mb_membership($pdo, (int) $open['id'], $samId) === null && mb_pad_for($pdo, 'public/open-floor', $samId) === null && in_array('public/open-floor', array_column(mb_public_list($pdo, $samId, false), 'ref'), true));
check('and can rejoin', $sp($sam, $open)[1] === false && mb_membership($pdo, (int) $open['id'], $samId) !== null);

// ---- an administrator looks after them
check('settings are only for an administrator, and each change is recorded', str_contains(err(fn () => mb_public_settings($pdo, $sam, (int) $open['id'], false, true)), 'Only an administrator') && mb_public_settings($pdo, $dylan, (int) $open['id'], false, true) == ['humans_can_join' => false, 'read_only' => true] && mb_public_settings($pdo, $dylan, (int) $open['id'], false, true) === [] && $cnt("SELECT COUNT(*) FROM audit_log WHERE action = 'public_pad.settings'") === 2);
mb_public_settings($pdo, $dylan, (int) $open['id'], true, false);
mb_public_remove_member($pdo, $dylan, (int) $news['id'], $samId);
check('an administrator can take someone off, and only an administrator', mb_membership($pdo, (int) $news['id'], $samId) === null && str_contains(err(fn () => mb_public_remove_member($pdo, $sam, (int) $news['id'], $leeId)), 'Only an administrator') && mb_membership($pdo, (int) $news['id'], $leeId) !== null);
check('a pad that isn\'t public can\'t be changed or removed through these', str_contains(err(fn () => mb_public_delete($pdo, $dylan, (int) mb_pad_by_address($pdo, 'sam', 'sams-launch')['id'])), 'isn\'t a public pad') && str_contains(err(fn () => mb_public_settings($pdo, $dylan, (int) mb_pad_by_address($pdo, 'sam', 'sams-launch')['id'], true, true)), 'isn\'t a public pad'));
check('only an administrator removes one', str_contains(err(fn () => mb_public_delete($pdo, $sam, (int) $open['id'])), 'Only an administrator') && $cnt("SELECT COUNT(*) FROM pads WHERE id = {$open['id']}") === 1);
mb_pad_post($pdo, $open, $dylan, 'This goes too.');
check('removing one deletes it with its messages, members and About text, and is recorded', mb_public_delete($pdo, $dylan, (int) $open['id']) === 'open-floor' && $cnt("SELECT COUNT(*) FROM pads WHERE id = {$open['id']}") === 0 && $cnt("SELECT COUNT(*) FROM messages WHERE pad_id = {$open['id']}") === 0 && $cnt("SELECT COUNT(*) FROM pad_members WHERE pad_id = {$open['id']}") === 0 && $cnt("SELECT COUNT(*) FROM audit_log WHERE action = 'public_pad.delete'") === 1);
check('the name is free to use again', mb_pad_ref(mb_public_create($pdo, $dylan, 'Open Floor')) === 'public/open-floor');

// ---- "New accounts auto-join"
$pdo->exec('UPDATE plans SET max_agents = NULL');
$existingHuman = $P($mk('already-here')); $existingAgent = $agent($leeId, 'already-bot');
$welcome = mb_public_create($pdo, $dylan, 'Welcome', 'Read this first.', true, true, true);
$botsOnly = mb_public_create($pdo, $dylan, 'Bots Only', '', false, false, true);
$optIn = mb_public_create($pdo, $dylan, 'Opt In');
check('the option is off unless it is asked for, and set when it is', (int) $optIn['auto_join'] === 0 && (int) $welcome['auto_join'] === 1 && (int) $botsOnly['auto_join'] === 1);
check('accounts that already exist are not added when it is switched on', mb_membership($pdo, (int) $welcome['id'], (int) $existingHuman['id']) === null && mb_membership($pdo, (int) $welcome['id'], $existingAgent) === null);
$newHuman = $mk('newcomer'); $newBot = $agent($leeId, 'new-bot');
$on = fn (int $id, array $pad) => mb_membership($pdo, (int) $pad['id'], $id) !== null;
check('a new person joins the pads that take new accounts and let people join, and not the others', $on($newHuman, $welcome) && !$on($newHuman, $botsOnly) && !$on($newHuman, $optIn));
check('a new agent joins every pad that takes new accounts, whether or not people can join', $on($newBot, $welcome) && $on($newBot, $botsOnly) && !$on($newBot, $optIn));
check('they joined as public pad members, with no notice posted', $pdo->query("SELECT joined_via FROM pad_members WHERE pad_id = {$welcome['id']} AND principal_id = $newHuman")->fetchColumn() === 'public' && $cnt("SELECT COUNT(*) FROM messages WHERE pad_id = {$welcome['id']} AND kind = 'event'") === 0);
check('the account that owns public pads is never added to one', !$on($sys, $welcome));
$g2 = mb_invite_guest_agent($pdo, mb_create_pad($pdo, $sam, 'Sams Second'), ['handle' => 'guest-two', 'acts_for' => 'Lee'], false)[0];
check('a guest agent that joined on its own is not added until it is claimed', !$on((int) $g2['id'], $welcome) && !$on((int) $g2['id'], $botsOnly));
require_once "$root/src/claims.php";
[$claimCode] = mb_claim_create($pdo, $leeId); mb_claim_accept($pdo, $P((int) $g2['id']), $claimCode);
check('and is added when it is claimed, now that it has an account', $on((int) $g2['id'], $welcome) && $on((int) $g2['id'], $botsOnly));
$dupeFirst = $agent($samId, 'dupe-bot'); mb_public_remove_member($pdo, $dylan, (int) $botsOnly['id'], $dupeFirst);      // on Welcome only
$dupeSecond = $agent($leeId, 'dupe-bot');
check('a name already on a pad skips that pad without stopping the account being made', $dupeSecond > 0 && !$on($dupeSecond, $welcome) && $on($dupeSecond, $botsOnly));
check('the changes are recorded', mb_public_settings($pdo, $dylan, (int) $optIn['id'], true, false, true) == ['auto_join' => true] && $cnt("SELECT COUNT(*) FROM audit_log WHERE action = 'public_pad.settings' AND detail = 'auto_join=1'") === 1 && mb_public_settings($pdo, $dylan, (int) $optIn['id'], true, false) === [] && (int) mb_pad_by_id($pdo, (int) $optIn['id'])['auto_join'] === 1);
check('switched on, the next new person joins it too; switched off, they no longer do', $on($mk('after-on'), $optIn) && mb_public_settings($pdo, $dylan, (int) $optIn['id'], true, false, false) == ['auto_join' => false] && !$on($mk('after-off'), $optIn));
check('only an administrator can change it', str_contains(err(fn () => mb_public_settings($pdo, $sam, (int) $welcome['id'], true, true, false)), 'Only an administrator') && (int) mb_pad_by_id($pdo, (int) $welcome['id'])['auto_join'] === 1);
check('a pad that is read-only can take new accounts, which can then read it but not post', str_contains(err(fn () => mb_pad_post($pdo, mb_pad_by_id($pdo, (int) $welcome['id']), $P($newHuman), 'hi')), 'read-only public pad'));
check('the function can be called for anyone and does nothing for the wrong kind of account', mb_public_auto_join($pdo, []) === [] && mb_public_auto_join($pdo, $P($sys)) === [] && mb_public_auto_join($pdo, $P($newHuman)) === []);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
