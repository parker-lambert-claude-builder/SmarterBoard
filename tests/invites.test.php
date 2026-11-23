<?php
/** Permanent invite links: joining, approval, guest agents, reset and off, events, and who may do what. */
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
    try { $fn(); } catch (Throwable $t) { return $t->getMessage(); }
    return '(no error)';
}
require "$root/src/db.php";
$pdo = mb_db_connect($db);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec("DROP TABLE `$t`"); }
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
mb_migrate($pdo);
$pdo->exec('UPDATE plans SET max_human_seats = NULL, max_agents = NULL');      // this suite isn't about plan limits: tests/limits.test.php is
mb_write_config($db);
require "$root/src/invites.php";

$dylanId = mb_create_human($pdo, 'Dylan', 'dylan', 'd@example.com', 'correct horse battery', true);
$samId   = mb_create_human($pdo, 'Sam Rivera', 'sam', 's@example.com', 'correct horse battery');
$dylan   = mb_principal($pdo, $dylanId);
$sam     = mb_principal($pdo, $samId);
$pad     = mb_create_pad($pdo, $dylan, 'My Schedule');
$padId   = (int) $pad['id'];
$events  = fn () => $pdo->query("SELECT body FROM messages WHERE pad_id = $padId AND kind = 'event' ORDER BY seq")->fetchAll(PDO::FETCH_COLUMN);

check('a pad has no invite link until its owner makes one', mb_invite($pdo, $padId) === null);
$inv = mb_invite_make($pdo, $pad, $dylanId);
$url = mb_invite_url($inv);
check('the link is a long random token, not the pad address', (bool) preg_match('~^http://localhost/join/[A-Za-z0-9]{32}$~', $url) && !str_contains($url, 'schedule') && !str_contains($url, 'dylan'));
check('new links ask the owner before anyone joins', (int) $inv['require_approval'] === 1 && (int) $inv['enabled'] === 1);
check('making it again returns the same link', mb_invite_make($pdo, $pad, $dylanId)['token'] === $inv['token']);
check('the token is read out of a link in any of its forms', mb_invite_token_from($url) === $inv['token'] && mb_invite_token_from($url . '/') === $inv['token'] && mb_invite_token_from("  $url?x=1 ") === $inv['token']
      && mb_invite_token_from('http://localhost/pads/dylan/my-schedule') === null && mb_invite_token_from('dylan/my-schedule') === null);
[$i2, $p2] = mb_invite_by_token($pdo, $inv['token']);
check('a live token finds its pad', (int) $p2['id'] === $padId);
check('a wrong, malformed or empty token finds nothing', mb_invite_by_token($pdo, str_repeat('A', 32)) === null && mb_invite_by_token($pdo, "x' OR 1=1 --") === null && mb_invite_by_token($pdo, '') === null);

// A person asks to join; the owner approves
check('with approval on, a person who uses the link is asked to wait', mb_invite_use($pdo, $inv, $pad, $sam) === 'requested' && mb_pad_for($pdo, 'dylan/my-schedule', $samId) === null);
check('using it again does not create a second request', mb_invite_use($pdo, $inv, $pad, $sam) === 'requested' && count(mb_join_requests($pdo, $padId)) === 1 && mb_join_pending($pdo, $padId, $samId));
check('the request is recorded in the pad as an event', $events() === ['@sam asked to join through the invite link. The owner can approve or decline here, or on the pad\'s Manage access page.']);
$req = mb_join_requests($pdo, $padId)[0];
check('the owner sees who is asking', $req['handle'] === 'sam' && $req['kind'] === 'human' && $req['display_name'] === 'Sam Rivera');
check('approving admits them and says so', mb_join_decide($pdo, $pad, (int) $req['request_id'], true, $dylanId) === '@sam is now on this pad.' && mb_pad_for($pdo, 'dylan/my-schedule', $samId) !== null);
check('they are marked as having joined with the invite link', mb_membership($pdo, $padId, $samId)['joined_via'] === 'invite' && mb_join_requests($pdo, $padId) === [] && $events()[1] === '@sam joined through the invite link.');
check('a member who uses the link again is simply told they are on the pad', mb_invite_use($pdo, $inv, $pad, $sam) === 'member');
check('answering a request twice is refused', str_contains(err(fn () => mb_join_decide($pdo, $pad, (int) $req['request_id'], true, $dylanId)), 'no longer waiting'));

// A member brings their own agent: it belongs to them, not to the pad's owner
[$samBotId] = mb_create_agent($pdo, $samId, ['handle' => 'sambot', 'role' => 'Scheduler']);
mb_add_member($pdo, $pad, $samBotId, 'added');
$samBot = mb_principal($pdo, $samBotId);
check('a member\'s own agent joins under the member\'s account', (int) $samBot['owner_id'] === $samId && mb_pad_for($pdo, 'dylan/my-schedule', $samBotId) !== null
      && mb_owned_agent($pdo, $samId, $samBotId) !== null && mb_owned_agent($pdo, $dylanId, $samBotId) === null);

// An agent joins on its own, without its person having an account
check('a guest agent must say who it acts for', str_contains(err(fn () => mb_invite_guest_agent($pdo, $pad, ['handle' => 'lee-assistant'])), 'Say who you act for'));
check('and nothing is left behind by the refused attempt', (int) $pdo->query("SELECT COUNT(*) FROM principals WHERE handle = 'lee-assistant'")->fetchColumn() === 0);
[$guest, $guestToken] = mb_invite_guest_agent($pdo, $pad, ['handle' => 'Lee-Assistant', 'acts_for' => '  Lee   Park ', 'platform' => 'ChatGPT']);
check('a guest agent gets its own token and is registered under the pad owner', str_starts_with($guestToken, 'mp_a_') && (int) $guest['owner_id'] === $dylanId && $guest['handle'] === 'lee-assistant'
      && $guest['acts_for'] === 'Lee Park' && $guest['agent_joined_via'] === 'invite' && (int) $guest['self_described'] === 1 && (int) $guest['can_create_pads'] === 0);
check('its request waits for the owner, and the event says who it acts for', mb_invite_use($pdo, $inv, $pad, $guest) === 'requested' && mb_pad_for($pdo, 'dylan/my-schedule', (int) $guest['id']) === null
      && str_starts_with($events()[2], '@lee-assistant (an agent acting for Lee Park) asked to join'));
check('a guest can\'t take a handle already on the pad', str_contains(err(fn () => mb_invite_guest_agent($pdo, $pad, ['handle' => 'sambot', 'acts_for' => 'X'])), 'already goes by @sambot'));
check('a guest\'s participant details carry who it says it acts for', (mb_pad_participant($pdo, $pad, 'lee-assistant') ?? ['acts_for' => 'not on pad yet'])['acts_for'] === 'not on pad yet');
$greq = mb_join_requests($pdo, $padId)[0];
check('declining removes the request and admits nobody', mb_join_decide($pdo, $pad, (int) $greq['request_id'], false, $dylanId) === '@lee-assistant\'s request was declined.'
      && mb_pad_for($pdo, 'dylan/my-schedule', (int) $guest['id']) === null && mb_join_requests($pdo, $padId) === []);

// Approval off: straight in
mb_invite_set($pdo, $pad, $dylanId, null, false);
$inv = mb_invite($pdo, $padId);
check('with approval off, the same guest joins straight away', mb_invite_use($pdo, $inv, $pad, $guest) === 'joined' && mb_pad_for($pdo, 'dylan/my-schedule', (int) $guest['id']) !== null && (int) mb_invite($pdo, $padId)['uses'] === 2);
$gp = mb_pad_participant($pdo, $pad, 'lee-assistant');
check('once on the pad, a guest\'s page says who it acts for, who is responsible, and counts no messages for its join events', $gp['acts_for'] === 'Lee Park' && $gp['responsible'] === 'dylan' && $gp['posts'] === 0 && $gp['joined_via'] === 'invite');
$m = mb_pad_post($pdo, mb_pad_for($pdo, 'dylan/my-schedule', $samId), $sam, 'Hello.');
check('events take message numbers but are not posts: the first real post is not throttled by them', $m['id'] === 6);
$r = mb_pad_read($pdo, mb_pad_for($pdo, 'dylan/my-schedule', $dylanId), null, 50);
check('a read marks events apart from posts', array_column($r['messages'], 'kind') === ['event', 'event', 'event', 'event', 'event', 'post']);
check('declining is recorded in the pad too, so everyone sees the outcome', in_array('@lee-assistant (an agent acting for Lee Park)\'s request to join was declined by @dylan.', $events(), true));
$before = $pdo->query("SELECT last_activity_at FROM pads WHERE id = $padId")->fetchColumn();
$pdo->exec("UPDATE pads SET last_activity_at = last_activity_at - INTERVAL 3 DAY WHERE id = $padId");
mb_pad_event($pdo, $pad, $dylanId, 'Test event.');
check('an event does not extend the pad\'s retention', $pdo->query("SELECT last_activity_at FROM pads WHERE id = $padId")->fetchColumn() < $before);

// Request codes: a new agent that must wait gets a code, not a token
$rpad = mb_create_pad($pdo, $dylan, 'Receipts');
$rinv = mb_invite_make($pdo, $rpad, $dylanId);
$rid  = (int) $rpad['id'];
[$wait, $noToken] = mb_invite_guest_agent($pdo, $rpad, ['handle' => 'phone-agent', 'acts_for' => 'Dylan'], false);
check('where the owner approves first, a new guest is created with no token at all', $noToken === null && !mb_agent_has_token($pdo, (int) $wait['id'])
      && (int) $pdo->query("SELECT COUNT(*) FROM tokens WHERE principal_id = {$wait['id']} AND revoked_at IS NULL")->fetchColumn() === 0);
mb_invite_use($pdo, $rinv, $rpad, $wait);
$code1 = mb_receipt_issue($pdo, $rid, (int) $wait['id']);
check('it is given a request code; only the hash is stored', (bool) preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code1) && !$pdo->query('SELECT COUNT(*) FROM join_receipts WHERE code_hash = ' . $pdo->quote($code1))->fetchColumn());
$w = mb_receipt_collect($pdo, $code1);
check('presenting the code before approval says still waiting, hands over nothing, and keeps the code good', $w['state'] === 'waiting' && !isset($w['token']) && !mb_agent_has_token($pdo, (int) $wait['id']) && mb_receipt_collect($pdo, $code1)['state'] === 'waiting');
check('a wrong or malformed code is invalid', mb_receipt_collect($pdo, 'AAAA-BBBB-CCCC')['state'] === 'invalid' && mb_receipt_collect($pdo, '')['state'] === 'invalid' && mb_receipt_collect($pdo, "x' OR 1=1")['state'] === 'invalid');
$code2 = mb_receipt_issue($pdo, $rid, (int) $wait['id']);
check('the owner can issue a new code; the lost one stops working', $code2 !== $code1 && mb_receipt_collect($pdo, $code1)['state'] === 'invalid' && mb_receipt_collect($pdo, $code2)['state'] === 'waiting');
mb_join_decide($pdo, $rpad, (int) mb_join_requests($pdo, $rid)[0]['request_id'], true, $dylanId);
$got = mb_receipt_collect($pdo, strtolower(str_replace('-', '', $code2)));
check('after approval the code (typed loosely) collects a working token for that agent', $got['state'] === 'ready' && str_starts_with($got['token'], 'mp_a_') && (int) mb_agent_by_token($pdo, $got['token'])['id'] === (int) $wait['id']
      && mb_pad_for($pdo, 'dylan/receipts', (int) $wait['id']) !== null);
check('the code hands over a token once only', mb_receipt_collect($pdo, $code2)['state'] === 'invalid' && (int) mb_agent_by_token($pdo, $got['token'])['id'] === (int) $wait['id']);
[$no] = mb_invite_guest_agent($pdo, $rpad, ['handle' => 'unwanted', 'acts_for' => 'Nobody'], false);
mb_invite_use($pdo, $rinv, $rpad, $no);
$dcode = mb_receipt_issue($pdo, $rid, (int) $no['id']);
mb_join_decide($pdo, $rpad, (int) mb_join_requests($pdo, $rid)[0]['request_id'], false, $dylanId);
check('a declined request\'s code is dead and no token ever existed', mb_receipt_collect($pdo, $dcode)['state'] === 'invalid' && !mb_agent_has_token($pdo, (int) $no['id']));
[$gone] = mb_invite_guest_agent($pdo, $rpad, ['handle' => 'removed-later', 'acts_for' => 'X'], false);
mb_invite_use($pdo, $rinv, $rpad, $gone);
$gcode = mb_receipt_issue($pdo, $rid, (int) $gone['id']);
mb_join_decide($pdo, $rpad, (int) mb_join_requests($pdo, $rid)[0]['request_id'], true, $dylanId);
mb_remove_member($pdo, $rpad, (int) $gone['id'], $dylanId);
check('approved and then removed before collecting: the code gives nothing', mb_receipt_collect($pdo, $gcode)['state'] === 'invalid' && !mb_agent_has_token($pdo, (int) $gone['id']));
$pdo->prepare('DELETE FROM pads WHERE id = ?')->execute([$rid]);
check('deleting the pad deletes its request codes', (int) $pdo->query('SELECT COUNT(*) FROM join_receipts')->fetchColumn() === 0);

// Starting over: delete an agent from the workspace so its name can be used again
$dpad = mb_create_pad($pdo, $dylan, 'Start Over');
$dinv = mb_invite_make($pdo, $dpad, $dylanId);
mb_invite_set($pdo, $dpad, $dylanId, null, false);
$dinv = mb_invite($pdo, (int) $dpad['id']);
[$bodhi, $bodhiToken] = mb_invite_guest_agent($pdo, $dpad, ['handle' => 'bodhi', 'acts_for' => 'Dylan']);
mb_invite_use($pdo, $dinv, $dpad, $bodhi);
mb_pad_post($pdo, mb_pad_for($pdo, 'dylan/start-over', (int) $bodhi['id']), $bodhi, 'Hello from the first bodhi.');
mb_revoke_agent_tokens($pdo, (int) $bodhi['id']);
mb_remove_member($pdo, $dpad, (int) $bodhi['id'], $dylanId);
$stuck = err(fn () => mb_invite_guest_agent($pdo, $dpad, ['handle' => 'bodhi', 'acts_for' => 'Dylan']));
check('removed from the pad with a dead token, the same name still can\'t register, and the refusal says how to get unstuck', str_contains($stuck, 'already an agent called @bodhi')
      && str_contains($stuck, 'issue a new one') && str_contains($stuck, 'remove the old agent'), $stuck);
check('only its responsible person can delete it', str_contains(err(fn () => mb_delete_agent($pdo, $samId, (int) $bodhi['id'])), 'isn\'t one of your agents') && mb_principal($pdo, (int) $bodhi['id'])['handle'] === 'bodhi');
mb_add_member($pdo, $dpad, (int) $bodhi['id'], 'added');
$gone = mb_delete_agent($pdo, $dylanId, (int) $bodhi['id']);
$old  = mb_principal($pdo, (int) $bodhi['id']);
check('deleting takes it off its pads, kills its token and frees the name', $gone['handle'] === 'bodhi' && $gone['pads'] === ['dylan/start-over'] && mb_pad_for($pdo, 'dylan/start-over', (int) $bodhi['id']) === null
      && mb_agent_by_token($pdo, $bodhiToken) === null && !mb_agent_has_token($pdo, (int) $bodhi['id']) && $old['disabled_at'] !== null && $old['handle'] === 'bodhi-removed-' . $bodhi['id']);
check('it is gone from its person\'s agents and can\'t be acted on again', !in_array((int) $bodhi['id'], array_map('intval', array_column(mb_agents_of($pdo, $dylanId), 'id')), true)
      && mb_owned_agent($pdo, $dylanId, (int) $bodhi['id']) === null && str_contains(err(fn () => mb_delete_agent($pdo, $dylanId, (int) $bodhi['id'])), 'isn\'t one of your agents'));
$dr = mb_pad_read($pdo, mb_pad_for($pdo, 'dylan/start-over', $dylanId), null, 50);
$post = array_values(array_filter($dr['messages'], fn ($m) => $m['body'] === 'Hello from the first bodhi.'))[0];
check('its earlier message stays, now signed with the closed name', $post['author'] === 'bodhi-removed-' . $bodhi['id']);
check('the pad records the deletion', str_contains(end($dr['messages'])['body'], '@bodhi was removed from Mashpad by @dylan and is no longer on this pad. Its earlier messages now show as @bodhi-removed-'));
[$bodhi2, $token2] = mb_invite_guest_agent($pdo, $dpad, ['handle' => 'bodhi', 'acts_for' => 'Dylan']);
check('a brand-new @bodhi can now register and join from square zero, as a different agent', (int) $bodhi2['id'] !== (int) $bodhi['id'] && mb_invite_use($pdo, $dinv, $dpad, $bodhi2) === 'joined'
      && (int) mb_agent_by_token($pdo, $token2)['id'] === (int) $bodhi2['id'] && mb_pad_participant($pdo, $dpad, 'bodhi')['posts'] === 0);
$long = str_repeat('a', 40);
[$longId] = mb_create_agent($pdo, $dylanId, ['handle' => $long]);
$lg = mb_delete_agent($pdo, $dylanId, $longId);
check('a 40-character name still fits once closed', strlen($lg['now']) <= 40 && str_ends_with($lg['now'], '-removed-' . $longId) && (bool) preg_match('/^[a-z0-9][a-z0-9_-]{1,38}[a-z0-9]$/', $lg['now']));
$pdo->prepare('DELETE FROM pads WHERE id = ?')->execute([$dpad['id']]);

// Answering from the conversation: each waiting request is tied to the event that announced it
$epad = mb_create_pad($pdo, $dylan, 'Events');
$einv = mb_invite_make($pdo, $epad, $dylanId);
[$ea] = mb_invite_guest_agent($pdo, $epad, ['handle' => 'first-asker', 'acts_for' => 'A'], false);
[$eb] = mb_invite_guest_agent($pdo, $epad, ['handle' => 'second-asker', 'acts_for' => 'B'], false);
mb_invite_use($pdo, $einv, $epad, $ea);
mb_invite_use($pdo, $einv, $epad, $eb);
$map  = mb_join_request_events($pdo, (int) $epad['id']);
$reqs = array_column(mb_join_requests($pdo, (int) $epad['id']), 'request_id', 'handle');
check('each waiting request is keyed by the number of its own event', $map === [1 => (int) $reqs['first-asker'], 2 => (int) $reqs['second-asker']], json_encode($map));
mb_invite_use($pdo, $einv, $epad, $ea);
check('asking again doesn\'t move a request to a different event', mb_join_request_events($pdo, (int) $epad['id']) === $map);
mb_join_decide($pdo, $epad, (int) $reqs['first-asker'], true, $dylanId);
check('an answered request is no longer offered; the other still is', mb_join_request_events($pdo, (int) $epad['id']) === [2 => (int) $reqs['second-asker']]);
mb_join_decide($pdo, $epad, (int) $reqs['second-asker'], false, $dylanId);
check('once all are answered nothing is offered', mb_join_request_events($pdo, (int) $epad['id']) === []);
$pdo->prepare('DELETE FROM pads WHERE id = ?')->execute([$epad['id']]);

// The rail's "N agents, N humans" counts current members only
$cpad = mb_create_pad($pdo, $dylan, 'Counts');
$cinv = mb_invite_make($pdo, $cpad, $dylanId);
[$cA] = mb_create_agent($pdo, $dylanId, ['handle' => 'count-a']);
[$cB] = mb_create_agent($pdo, $dylanId, ['handle' => 'count-b']);
mb_add_member($pdo, $cpad, $cA, 'added');
mb_add_member($pdo, $cpad, $cB, 'added');
mb_remove_member($pdo, $cpad, $cB, $dylanId);
[$cW] = mb_invite_guest_agent($pdo, $cpad, ['handle' => 'count-wait', 'acts_for' => 'W'], false);
mb_invite_use($pdo, $cinv, $cpad, $cW);
$cRow = array_values(array_filter(mb_pads_for($pdo, $dylanId), fn ($p) => $p['slug'] === 'counts'))[0];
check('the counts include the owner and a current agent, and not a removed agent or one still waiting for approval', $cRow['agents'] === 1 && $cRow['humans'] === 1 && count($cRow['members']) === 2, json_encode([$cRow['agents'], $cRow['humans'], $cRow['members']]));
mb_join_decide($pdo, $cpad, (int) mb_join_requests($pdo, (int) $cpad['id'])[0]['request_id'], true, $dylanId);
$cRow = array_values(array_filter(mb_pads_for($pdo, $dylanId), fn ($p) => $p['slug'] === 'counts'))[0];
check('once the waiting agent is approved it is counted', $cRow['agents'] === 2 && $cRow['humans'] === 1);
$pdo->prepare('DELETE FROM pads WHERE id = ?')->execute([$cpad['id']]);

// Off, on, reset
mb_invite_set($pdo, $pad, $dylanId, false, null);
check('switched off, the link finds nothing; members stay', mb_invite_by_token($pdo, $inv['token']) === null && mb_pad_for($pdo, 'dylan/my-schedule', $samId) !== null);
mb_invite_set($pdo, $pad, $dylanId, true, null);
check('switched back on, the same link works', mb_invite_by_token($pdo, $inv['token']) !== null);
$new = mb_invite_make($pdo, $pad, $dylanId, true);
check('reset gives a new link and kills the old one', $new['token'] !== $inv['token'] && mb_invite_by_token($pdo, $inv['token']) === null && mb_invite_by_token($pdo, $new['token']) !== null
      && (int) $new['require_approval'] === 0);
mb_invite_set($pdo, $pad, $dylanId, false, null);
$offReset = mb_invite_make($pdo, $pad, $dylanId, true);
check('resetting a link that is switched off leaves the new one off', (int) $offReset['enabled'] === 0 && $offReset['token'] !== $new['token'] && mb_invite_by_token($pdo, $offReset['token']) === null);
mb_invite_set($pdo, $pad, $dylanId, true, null);
check('and it works once switched on', mb_invite_by_token($pdo, $offReset['token']) !== null);
$actions = $pdo->query("SELECT action FROM audit_log WHERE action LIKE 'invite.%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
check('link changes and decisions are in the audit log', array_intersect(['invite.create', 'invite.request', 'invite.approve', 'invite.decline', 'invite.approval', 'invite.disable', 'invite.enable', 'invite.reset'], $actions)
      == ['invite.create', 'invite.request', 'invite.approve', 'invite.decline', 'invite.approval', 'invite.disable', 'invite.enable', 'invite.reset'], implode(',', $actions));
$pdo->prepare('DELETE FROM pads WHERE id = ?')->execute([$padId]);
check('deleting a pad deletes its link and requests', (int) $pdo->query('SELECT (SELECT COUNT(*) FROM pad_invites) + (SELECT COUNT(*) FROM pad_join_requests)')->fetchColumn() === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
