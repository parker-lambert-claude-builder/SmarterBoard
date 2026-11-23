<?php
/**
 * Owned pads: creation, membership, messages, agent tokens, lobby codes, throttle,
 * retention. Same database rules as the other suites (mashpad_test*, tables dropped).
 */
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
$legacy = "$root/data/boards/zz-legacy-taken.json";
register_shutdown_function(function () use ($files, $saved, $legacy) {
    foreach ($files as $f) { @unlink($f); if (isset($saved[$f])) file_put_contents($f, $saved[$f]); }
    @unlink($legacy);
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
require "$root/src/pads.php";

$dylanId = mb_create_human($pdo, 'Dylan', 'dylan', 'd@example.com', 'correct horse battery', true);
$eveId   = mb_create_human($pdo, 'Eve', 'eve', 'e@example.com', 'correct horse battery');
$dylan   = mb_principal($pdo, $dylanId);
$eve     = mb_principal($pdo, $eveId);

// Creating pads
$pad = mb_create_pad($pdo, $dylan, 'Mashpad Dev');
check('human creates a pad; address from the name', $pad && $pad['slug'] === 'mashpad-dev' && (int) $pad['owner_id'] === $dylanId);
check('creator is the owner member', (mb_membership($pdo, (int) $pad['id'], $dylanId)['role'] ?? '') === 'owner');
check('pad knows its address and link', mb_pad_ref($pad) === 'dylan/mashpad-dev' && mb_pad_url($pad) === 'http://localhost/pads/dylan/mashpad-dev');
$again = err(fn () => mb_create_pad($pdo, $dylan, 'mashpad dev'));
check('A2: same owner, same name refused, naming the existing pad\'s full address', str_contains($again, 'Not created') && str_contains($again, 'http://localhost/pads/dylan/mashpad-dev') && str_contains($again, 'dylan/mashpad-dev'));
foreach (MB_RESERVED_PAD_NAMES as $word) {
    if (!str_contains(err(fn () => mb_create_pad($pdo, $dylan, strtoupper($word))), 'keeps for its own pages')) { $bad[] = $word; }
}
check('A3: every reserved name is refused with an explanation', empty($bad) && count(array_intersect(['create', 'manage', 'settings'], MB_RESERVED_PAD_NAMES)) === 3, implode(',', $bad ?? []));
$pages = array_map('basename', array_filter(glob("$root/*"), 'is_dir'));
check('A3: the reserved list covers every top-level folder', array_diff($pages, MB_RESERVED_PAD_NAMES) === [], implode(',', array_diff($pages, MB_RESERVED_PAD_NAMES)));
check('empty name refused', str_contains(err(fn () => mb_create_pad($pdo, $dylan, '   ')), 'Give the pad a name'));

// Privacy
check('owner sees the pad', mb_pad_for($pdo, 'Mashpad Dev', $dylanId) !== null);
check('non-member gets nothing (same as no pad)', mb_pad_for($pdo, 'mashpad-dev', $eveId) === null && mb_pad_for($pdo, 'no-such-pad', $eveId) === null);
check('pad list is per member', count(mb_pads_for($pdo, $dylanId)) === 1 && count(mb_pads_for($pdo, $eveId)) === 0);

// Agents and tokens
[$museId, $museToken] = mb_create_agent($pdo, $dylanId, ['handle' => '@Muse', 'role' => 'QA Tester', 'doing' => 'Acceptance tests', 'platform' => 'ChatGPT']);
check('owner creates an agent; token has the agent prefix', str_starts_with($museToken, 'mp_a_') && strlen($museToken) > 40);
check('only the token hash is stored', !$pdo->query('SELECT COUNT(*) FROM tokens WHERE token_hash = ' . $pdo->quote($museToken))->fetchColumn());
$muse = mb_agent_by_token($pdo, $museToken);
check('token identifies the agent', $muse && $muse['handle'] === 'muse' && (int) $muse['owner_id'] === $dylanId && $muse['role'] === 'QA Tester');
check('garbage token identifies nobody', mb_agent_by_token($pdo, 'mp_a_nope') === null && mb_agent_by_token($pdo, '') === null);
check('second @muse in the same workspace refused', str_contains(err(fn () => mb_create_agent($pdo, $dylanId, ['handle' => 'muse'])), 'already an agent called @muse'));
check('another person may have their own @muse', !str_contains(err(fn () => mb_create_agent($pdo, $eveId, ['handle' => 'muse'])), 'already'));
check('webhook must be https', str_contains(err(fn () => mb_create_agent($pdo, $dylanId, ['handle' => 'hooky', 'webhook_url' => 'http://x.example/'])), 'https://'));
[$hookId] = mb_create_agent($pdo, $dylanId, ['handle' => 'hooky', 'webhook_url' => 'https://x.example/wake']);
check('webhook gets a signing secret', str_starts_with((string) $pdo->query("SELECT webhook_secret FROM agent_profiles WHERE principal_id = $hookId")->fetchColumn(), 'whsec_'));
check('agent not a member cannot see the pad', mb_pad_for($pdo, 'mashpad-dev', $museId) === null);
mb_add_member($pdo, $pad, $museId, 'added');
$padForMuse = mb_pad_for($pdo, 'mashpad-dev', $museId);
check('added agent can see the pad', $padForMuse !== null);

// Posting and the throttle
$m1 = mb_pad_post($pdo, $padForMuse, $muse, 'First from muse.');
check('first post is #1 under the agent handle', $m1['id'] === 1 && $m1['author'] === 'muse');
$t = null;
try { mb_pad_post($pdo, $padForMuse, $muse, 'Too soon.'); } catch (MbThrottled $e) { $t = $e; }
check('second post within a minute is throttled', $t !== null);
check('throttle message says what happened and when to retry', $t && preg_match('/^Not posted: the Free plan allows one post per minute, and your last post was \d+ seconds? ago\. Send this message again in \d+ seconds? \(after \d\d:\d\d:\d\d UTC\)\. Your text was not saved\.$/', $t->getMessage()) === 1, $t ? $t->getMessage() : '');
check('machine-readable retry-after', $t && $t->retryAfter > 50 && $t->retryAfter <= 60);
check('throttled text was not stored', (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 1);
$dPad = mb_pad_for($pdo, 'mashpad-dev', $dylanId);
$m2 = mb_pad_post($pdo, $dPad, $dylan, 'Owner reply.');
check('throttle is per author: owner can post', $m2['id'] === 2);
$pdo->exec('UPDATE messages SET created_at = created_at - INTERVAL 61 SECOND');
$m3 = mb_pad_post($pdo, mb_pad_for($pdo, 'mashpad-dev', $museId), $muse, 'After the wait.');
check('after the interval, posting works again', $m3['id'] === 3);
$pdo->exec("UPDATE plans SET post_interval_seconds = 10 WHERE code = 'plus'");
check('interval wording for 10s', mb_interval_phrase(10) === 'one post every 10 seconds' && mb_interval_phrase(120) === 'one post every 2 minutes');

$r = mb_pad_read($pdo, mb_pad_for($pdo, 'mashpad-dev', $dylanId), null, 50);
check('read returns all, in order, viewer-compatible shape', $r['total'] === 3 && $r['latest_id'] === 3 && array_column($r['messages'], 'id') === [1, 2, 3]
      && $r['messages'][1]['author'] === 'dylan' && is_int($r['messages'][0]['ts']) && $r['is_owner'] === true);
$r2 = mb_pad_read($pdo, mb_pad_for($pdo, 'mashpad-dev', $museId), 2, 50);
check('since_id returns only newer', array_column($r2['messages'], 'id') === [3] && $r2['is_owner'] === false);
check('retention follows the owner plan (30 days)', $r['expires'] - $r['last_activity'] === 30 * 86400);

// Token rotation
$newToken = mb_issue_agent_token($pdo, $museId);
check('regenerated token works, old one does not', mb_agent_by_token($pdo, $newToken) !== null && mb_agent_by_token($pdo, $museToken) === null);
$museToken = $newToken;

// Lobby codes
[$code, $exp] = mb_lobby_create($pdo, $dPad, $dylanId);
check('lobby code format and 10-minute expiry', preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code) === 1 && abs($exp - time() - 600) <= 2, $code);
check('active code listed', count(mb_lobby_active($pdo, (int) $dPad['id'])) === 1);
$url = 'https://mashpad.ai/pads/?b=mashpad-dev';
[$rev, $revToken, $joinedPad] = mb_join_pad($pdo, $url, strtolower(str_replace('-', '', $code)), null,
    ['handle' => 'reviewer', 'role' => 'Designer', 'doing' => 'UI', 'platform' => 'Claude']);
check('new agent joins via pad URL + code (code case and dash ignored)', $rev['handle'] === 'reviewer' && str_starts_with((string) $revToken, 'mp_a_') && $joinedPad['slug'] === 'mashpad-dev');
check('lobby agent belongs to the pad owner, marked self-registered', (int) $rev['owner_id'] === $dylanId && (int) $rev['self_described'] === 1 && $rev['agent_joined_via'] === 'lobby');
check('admitted right away', mb_pad_for($pdo, 'mashpad-dev', (int) $rev['id']) !== null);
[$meta, $metaToken] = mb_join_pad($pdo, 'mashpad-dev', $code, null, ['handle' => 'meta']);
check('same code admits several agents', $meta['handle'] === 'meta' && (int) $pdo->query('SELECT uses FROM lobby_codes')->fetchColumn() === 2);
$before = (int) $pdo->query('SELECT COUNT(*) FROM principals')->fetchColumn();
check('handle already on the pad refused', str_contains(err(fn () => mb_join_pad($pdo, 'mashpad-dev', $code, null, ['handle' => 'dylan'])), 'already goes by @dylan'));
check('refused join leaves no identity behind', (int) $pdo->query('SELECT COUNT(*) FROM principals')->fetchColumn() === $before);
check('wrong code refused', str_contains(err(fn () => mb_join_pad($pdo, 'mashpad-dev', 'AAAA-BBBB', null, ['handle' => 'x1x'])), 'isn\'t valid'));
check('code for another pad refused', str_contains(err(fn () => mb_join_pad($pdo, 'zz-legacy-taken', $code, null, ['handle' => 'x2x'])), 'isn\'t valid'));

// Existing agent joins a second pad with its own token (one identity)
$pad2 = mb_create_pad($pdo, $dylan, 'Second Pad');
[$code2] = mb_lobby_create($pdo, $pad2, $dylanId);
[$again, $noToken] = mb_join_pad($pdo, 'second-pad', $code2, mb_agent_by_token($pdo, $museToken), []);
check('existing agent joins another pad as itself, no new token', (int) $again['id'] === $museId && $noToken === null);
check('one identity across pads', count(mb_pads_for($pdo, $museId)) === 2);
$pdo->exec('UPDATE lobby_codes SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = (SELECT id FROM (SELECT MAX(id) AS id FROM lobby_codes) x)');
check('expired code refused', str_contains(err(fn () => mb_join_pad($pdo, 'second-pad', $code2, null, ['handle' => 'late'])), 'isn\'t valid'));
[$code3] = mb_lobby_create($pdo, $pad2, $dylanId);
$active = mb_lobby_active($pdo, (int) $pad2['id']);
mb_lobby_revoke($pdo, (int) $pad2['id'], (int) $active[0]['id'], $dylanId);
check('revoked code refused', str_contains(err(fn () => mb_join_pad($pdo, 'second-pad', $code3, null, ['handle' => 'late2'])), 'isn\'t valid'));

// Agent-created pads
$museNow = mb_agent_by_token($pdo, $museToken);
check('agent cannot create pads by default', str_contains(err(fn () => mb_create_pad($pdo, $museNow, 'Muse Pad')), 'hasn\'t allowed you'));
$pdo->exec("UPDATE agent_profiles SET can_create_pads = 1 WHERE principal_id = $museId");
$mp = mb_create_pad($pdo, mb_agent_by_token($pdo, $museToken), 'Muse Pad');
check('with permission: pad owned by the responsible human', (int) $mp['owner_id'] === $dylanId && (int) $mp['created_by'] === $museId);
check('creating agent is a member; owner too', mb_pad_for($pdo, 'muse-pad', $museId) && (mb_membership($pdo, (int) $mp['id'], $dylanId)['role'] ?? '') === 'owner');
$list = mb_pads_for($pdo, $dylanId);
$row  = array_values(array_filter($list, fn ($p) => $p['slug'] === 'muse-pad'))[0] ?? null;
check('owner pad list flags who created it', $row && $row['created_by_kind'] === 'agent' && $row['created_by_handle'] === 'muse');

// Who a participant is, as other members may see it
$who = mb_pad_participant($pdo, mb_pad_by_address($pdo, 'dylan', 'mashpad-dev'), '@Muse');
check('participant: an agent\'s role, platform, responsible person and activity on this pad', $who && $who['kind'] === 'agent' && $who['role'] === 'QA Tester' && $who['platform'] === 'ChatGPT'
      && $who['responsible'] === 'dylan' && $who['on_pad'] === true && $who['pad_role'] === 'member' && $who['posts'] === 2 && $who['last_post'] >= $who['first_post'] && $who['joined_via'] === 'added', json_encode($who));
$me2 = mb_pad_participant($pdo, mb_pad_by_address($pdo, 'dylan', 'mashpad-dev'), 'dylan');
mb_pad_event($pdo, mb_pad_by_address($pdo, 'dylan', 'mashpad-dev'), $museId, '@muse did something that is not a post.');
check('participant: pad events are not counted as messages', mb_pad_participant($pdo, mb_pad_by_address($pdo, 'dylan', 'mashpad-dev'), 'muse')['posts'] === 2);
check('participant: a person has no agent fields; the owner is marked', $me2['kind'] === 'human' && $me2['pad_role'] === 'owner' && $me2['is_creator'] === true && !array_key_exists('responsible', $me2) && $me2['display_name'] === 'Dylan');
check('participant: nothing private is included', !array_intersect(['email', 'id', 'owner_id', 'token', 'webhook_url', 'webhook_secret', 'plan_code'], array_keys($who + $me2)));
check('participant: someone never on the pad is nobody, even if they exist elsewhere', mb_pad_participant($pdo, mb_pad_by_address($pdo, 'dylan', 'mashpad-dev'), 'eve') === null
      && mb_pad_participant($pdo, mb_pad_by_address($pdo, 'dylan', 'mashpad-dev'), 'nobody-at-all') === null);
check('participant: the agent that created a pad is marked as its creator', mb_pad_participant($pdo, mb_pad_by_address($pdo, 'dylan', 'muse-pad'), 'muse')['is_creator'] === true);

// Plan pad limit
$pdo->exec("UPDATE plans SET max_owned_pads = 3 WHERE code = 'free'");
check('plan pad limit enforced', str_contains(err(fn () => mb_create_pad($pdo, $dylan, 'Fourth')), 'allows 3 pads'));
$pdo->exec("UPDATE plans SET max_owned_pads = NULL WHERE code = 'free'");

// A code's new agent belongs to whoever made the code
$evePad2 = mb_create_pad($pdo, $eve, 'Code Owners');
mb_add_member($pdo, $evePad2, $dylanId, 'added');
[$ownerCode]  = mb_lobby_create($pdo, $evePad2, $eveId);
[$memberCode] = mb_lobby_create($pdo, $evePad2, $dylanId);
[$a1] = mb_join_pad($pdo, 'eve/code-owners', $ownerCode, null, ['handle' => 'eves-bot']);
[$a2] = mb_join_pad($pdo, 'eve/code-owners', $memberCode, null, ['handle' => 'dylans-bot']);
check('an agent that joins with the owner\'s code belongs to the owner', (int) $a1['owner_id'] === $eveId);
check('an agent that joins with a member\'s code belongs to that member, not the pad\'s owner', (int) $a2['owner_id'] === $dylanId && mb_owned_agent($pdo, $dylanId, (int) $a2['id']) !== null && mb_owned_agent($pdo, $eveId, (int) $a2['id']) === null);
check('both are on the pad', mb_pad_for($pdo, 'eve/code-owners', (int) $a1['id']) !== null && mb_pad_for($pdo, 'eve/code-owners', (int) $a2['id']) !== null);
check('a member sees only the codes they made; the owner sees all', count(mb_lobby_active($pdo, (int) $evePad2['id'], $dylanId)) === 1 && count(mb_lobby_active($pdo, (int) $evePad2['id'])) === 2);
[$deadCode] = mb_lobby_create($pdo, $evePad2, $dylanId);
mb_remove_member($pdo, $evePad2, $dylanId, $eveId);
check('a code stops working the moment its maker is removed from the pad', str_contains(err(fn () => mb_join_pad($pdo, 'eve/code-owners', $deadCode, null, ['handle' => 'too-late'])), 'isn\'t valid')
      && (int) $pdo->query("SELECT COUNT(*) FROM principals WHERE handle = 'too-late'")->fetchColumn() === 0);
check('a removed maker\'s codes are no longer listed as active', array_map('intval', array_column(mb_lobby_active($pdo, (int) $evePad2['id']), 'created_by')) === [$eveId]);

// An agent updates its own profile
$bot = (int) $a1['id'];
check('an agent changes only the profile fields it passes', mb_update_agent_profile($pdo, $bot, ['role' => '  Scheduling   assistant ', 'webhook_url' => 'https://example.org/wake']) === ['role', 'webhook_url']
      && mb_principal($pdo, $bot)['role'] === 'Scheduling assistant' && mb_principal($pdo, $bot)['webhook_url'] === 'https://example.org/wake' && mb_principal($pdo, $bot)['platform'] === null);
$secret = $pdo->query("SELECT webhook_secret FROM agent_profiles WHERE principal_id = $bot")->fetchColumn();
check('a first webhook gets a signing secret, kept when the address changes', str_starts_with((string) $secret, 'whsec_') && mb_update_agent_profile($pdo, $bot, ['webhook_url' => 'https://example.org/wake2']) === ['webhook_url']
      && $pdo->query("SELECT webhook_secret FROM agent_profiles WHERE principal_id = $bot")->fetchColumn() === $secret);
check('an http:// webhook is refused and nothing changes', str_contains(err(fn () => mb_update_agent_profile($pdo, $bot, ['webhook_url' => 'http://x.example/'])), 'https://') && mb_principal($pdo, $bot)['webhook_url'] === 'https://example.org/wake2');
check('an empty string clears a field, and its secret with it', mb_update_agent_profile($pdo, $bot, ['webhook_url' => '', 'role' => '']) === ['role', 'webhook_url'] && mb_principal($pdo, $bot)['webhook_url'] === null
      && mb_principal($pdo, $bot)['role'] === null && $pdo->query("SELECT webhook_secret FROM agent_profiles WHERE principal_id = $bot")->fetchColumn() === null);
$evts = fn () => $pdo->query("SELECT body FROM messages WHERE pad_id = {$evePad2['id']} AND kind = 'event' ORDER BY seq")->fetchAll(PDO::FETCH_COLUMN);
$n0   = count($evts());
check('a profile change is recorded as an event on the pads the agent is on', mb_update_agent_profile($pdo, $bot, ['role' => 'Travel planner', 'doing' => 'Booking flights', 'webhook_url' => 'https://example.org/w']) === ['role', 'doing', 'webhook_url']
      && count($evts()) === $n0 + 1 && end($evts) === null || $evts()[count($evts()) - 1] === '@eves-bot updated its profile: its role is now "Travel planner"; changed what it is doing; set a wake-up webhook.', implode(' | ', $evts()));
check('the event never contains the webhook address', !str_contains(implode(' ', $evts()), 'example.org'));
$n1 = count($evts());
check('passing details that are already current changes nothing and records nothing', mb_update_agent_profile($pdo, $bot, ['role' => 'Travel planner']) === [] && count($evts()) === $n1);
check('only the field that really differs is reported', mb_update_agent_profile($pdo, $bot, ['role' => 'Travel planner', 'platform' => 'Meta AI']) === ['platform'] && $evts()[count($evts()) - 1] === '@eves-bot updated its profile: it now runs on "Meta AI".');
check('unknown keys and no keys change nothing; the handle can\'t be changed this way', mb_update_agent_profile($pdo, $bot, ['handle' => 'renamed', 'can_create_pads' => 1]) === [] && mb_principal($pdo, $bot)['handle'] === 'eves-bot' && (int) mb_principal($pdo, $bot)['can_create_pads'] === 0);
$pdo->prepare('DELETE FROM pads WHERE id = ?')->execute([$evePad2['id']]);

// The older open pads
check('only a site administrator is shown the older open pads', mb_open_pads_visible(['is_admin' => 1]) === true && mb_open_pads_visible(['is_admin' => '1']) === true
      && mb_open_pads_visible(['is_admin' => 0]) === false && mb_open_pads_visible([]) === false && mb_open_pads_visible(null) === false);

// Removal
mb_remove_member($pdo, mb_pad_by_address($pdo, 'dylan', 'mashpad-dev'), (int) $meta['id'], $dylanId);
check('participant: someone removed is still described, as no longer on the pad', (mb_pad_participant($pdo, mb_pad_by_address($pdo, 'dylan', 'mashpad-dev'), $meta['handle'])['on_pad'] ?? null) === false);
check('removed member loses access at once', mb_pad_for($pdo, 'mashpad-dev', (int) $meta['id']) === null);
check('removed member\'s posts stay', true);
check('owner cannot be removed', str_contains(err(fn () => mb_remove_member($pdo, mb_pad_by_address($pdo, 'dylan', 'mashpad-dev'), $dylanId, $dylanId)), 'can\'t be removed'));
mb_add_member($pdo, mb_pad_by_address($pdo, 'dylan', 'mashpad-dev'), (int) $meta['id'], 'added');
check('re-adding restores access', mb_pad_for($pdo, 'mashpad-dev', (int) $meta['id']) !== null);

// Retention
$pdo->exec("UPDATE pads SET last_activity_at = UTC_TIMESTAMP() - INTERVAL 31 DAY WHERE slug = 'second-pad'");
$gone = mb_prune_owned($pdo, true);
check('pads past plan retention are deleted with their messages', $gone === 1 && !mb_pad_by_address($pdo, 'dylan', 'second-pad') && mb_pad_by_address($pdo, 'dylan', 'mashpad-dev'));

// Pad addresses: <owner>/<name>
$parse = fn ($x) => mb_parse_pad_address($x);
check('address forms parse', $parse('https://x.test/message-board/pads/Dylan/Abc-Def/?view=settings#m3') === ['owner' => 'dylan', 'slug' => 'abc-def', 'flat' => 'dylan-abc-def']
      && $parse('https://x.test/pads/?b=abc-def&view=settings') === ['owner' => null, 'slug' => null, 'flat' => 'abc-def']
      && $parse('@Dylan/PixelBrief QA')['slug'] === 'pixelbrief-qa' && $parse('plain')['owner'] === null && $parse('A/B test')['flat'] === 'a-b-test');

$dLaunch = mb_create_pad($pdo, $dylan, 'Launch');
$eLaunch = mb_create_pad($pdo, $eve, 'Launch');
check('A1: two owners each have a pad called launch', mb_pad_ref($dLaunch) === 'dylan/launch' && mb_pad_ref($eLaunch) === 'eve/launch' && (int) $dLaunch['id'] !== (int) $eLaunch['id']);
check('A1: neither owner sees the other\'s, by name or by full address', (int) mb_pad_for($pdo, 'launch', $dylanId)['id'] === (int) $dLaunch['id']
      && (int) mb_pad_for($pdo, 'launch', $eveId)['id'] === (int) $eLaunch['id']
      && mb_pad_for($pdo, 'eve/launch', $dylanId) === null && mb_pad_for($pdo, 'dylan/launch', $eveId) === null
      && !in_array('eve/launch', array_column(mb_pads_for($pdo, $dylanId), 'ref'), true));
check('A10: a pad you can\'t see and a pad that doesn\'t exist are the same answer', mb_pad_for($pdo, 'eve/launch', $dylanId) === mb_pad_for($pdo, 'eve/never-made', $dylanId)
      && mb_not_found('eve/launch')->getMessage() === str_replace('never-made', 'launch', mb_not_found('eve/never-made')->getMessage()));

$muse = mb_agent_by_token($pdo, $museToken);
mb_add_member($pdo, $dLaunch, $museId, 'added');
$post = mb_pad_post($pdo, mb_pad_for($pdo, 'dylan/launch', $museId), $muse, 'By full address.');
$forms = ['dylan/launch', '@dylan/launch', 'Launch', 'https://mashpad.ai/pads/dylan/launch', 'https://mashpad.ai/pads/dylan/launch/?view=settings', 'https://mashpad.ai/pads/?b=launch'];
$ids   = array_map(fn ($f) => (int) (mb_pad_for($pdo, $f, $museId)['id'] ?? 0), $forms);
check('A8: address, bare name and link all reach the same pad and sequence', array_unique($ids) === [(int) $dLaunch['id']]
      && mb_pad_read($pdo, mb_pad_for($pdo, 'launch', $museId), null, 50)['latest_id'] === $post['id'], implode(',', $ids));
check('read result carries the full address', mb_pad_read($pdo, mb_pad_for($pdo, 'launch', $museId), null, 50)['key'] === 'dylan/launch');

[$eCode] = mb_lobby_create($pdo, $eLaunch, $eveId);
[$joined, $none, $jp] = mb_join_pad($pdo, 'https://mashpad.ai/pads/eve/launch', $eCode, $muse, []);
check('existing agent joins another owner\'s pad by its link', (int) $jp['id'] === (int) $eLaunch['id'] && $none === null);
check('a code only opens the pad it was made for', str_contains(err(fn () => mb_join_pad($pdo, 'dylan/launch', $eCode, null, ['handle' => 'stray'])), 'isn\'t valid')
      && !str_contains(err(fn () => mb_join_pad($pdo, 'launch', $eCode, null, ['handle' => 'barejoin'])), 'isn\'t valid'));
$before = (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
$amb = null;
try { mb_pad_for($pdo, 'launch', $museId); } catch (MbAmbiguousPad $e) { $amb = $e; }
check('A9: a bare name on two of the agent\'s pads is refused, listing both addresses', $amb !== null && $amb->refs === ['dylan/launch', 'eve/launch']
      && str_contains($amb->getMessage(), 'dylan/launch and eve/launch') && str_contains($amb->getMessage(), 'Nothing was read or posted')
      && (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === $before);
check('A9: the full address still works for each', (int) mb_pad_for($pdo, 'eve/launch', $museId)['id'] === (int) $eLaunch['id'] && (int) mb_pad_for($pdo, 'dylan/launch', $museId)['id'] === (int) $dLaunch['id']);
check('A12: the pad list gives full addresses', array_intersect(['dylan/launch', 'eve/launch'], array_column(mb_pads_for($pdo, $museId), 'ref')) === ['dylan/launch', 'eve/launch']
      || count(array_intersect(['dylan/launch', 'eve/launch'], array_column(mb_pads_for($pdo, $museId), 'ref'))) === 2);

$slash = mb_create_pad($pdo, $eve, 'A/B test');
check('a name that merely contains a slash is still one pad', $slash['slug'] === 'a-b-test' && (int) mb_pad_for($pdo, 'A/B test', $eveId)['id'] === (int) $slash['id']);

$code = '';
foreach (array_merge(glob("$root/src/*.php"), glob("$root/*/index.php")) as $f) { $code .= file_get_contents($f); }
// The one place a handle is ever rewritten is deleting an agent, which closes its record under "<name>-deleted-<n>".
$del = (string) preg_replace('/function mb_delete_agent\(.*?\n}\n/s', '', $code, 1);
check('A4: nothing in the app can rename a handle (only deleting an agent closes its name)', !preg_match('/UPDATE\s+principals\s+SET[^;]*\bhandle\s*=/i', $del)
      && preg_match_all('/UPDATE\s+principals\s+SET[^;]*\bhandle\s*=/i', $code) === 1);

// Threaded replies: a feature of the owner's plan
$tp     = mb_create_pad($pdo, $eve, 'Thread Test');
$evePad = fn () => mb_pad_for($pdo, 'eve/thread-test', $eveId);
check('a Free pad is not threaded', mb_pad_threaded($evePad()) === false && mb_pad_read($pdo, $evePad(), null, 50)['threaded'] === false);
$a = mb_pad_post($pdo, $evePad(), $eve, 'Top-level on Free.');
$pdo->exec('UPDATE messages SET created_at = created_at - INTERVAL 61 SECOND');
$b = mb_pad_post($pdo, $evePad(), $eve, 'A reply attempted on Free.', $a['id']);
check('on Free, reply_to is accepted but the post lands flat and says so', $b['reply_to'] === null && ($b['unthreaded'] ?? false) === true
      && mb_pad_read($pdo, $evePad(), null, 50)['messages'][1]['reply_to'] === null);
$pdo->exec("UPDATE principals SET plan_code = 'plus' WHERE id = $eveId");
$pdo->exec('UPDATE messages SET created_at = created_at - INTERVAL 61 SECOND');
check('on Plus the pad is threaded', mb_pad_threaded($evePad()) === true && mb_pad_read($pdo, $evePad(), null, 50)['threaded'] === true);
$c = mb_pad_post($pdo, $evePad(), $eve, 'A real reply.', $a['id']);
$pdo->exec('UPDATE messages SET created_at = created_at - INTERVAL 61 SECOND');
$d = mb_pad_post($pdo, $evePad(), $eve, 'A reply to the reply.', $c['id']);
$msgs = array_column(mb_pad_read($pdo, $evePad(), null, 50)['messages'], 'reply_to', 'id');
check('on Plus replies are stored against the message they answer, to any depth', $c['reply_to'] === $a['id'] && !isset($c['unthreaded']) && $msgs === [1 => null, 2 => null, 3 => 1, 4 => 3], json_encode($msgs));
$n = (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
check('a reply to a message that isn\'t there is refused and nothing is saved', str_contains(err(fn () => mb_pad_post($pdo, $evePad(), $eve, 'To nothing.', 99)), 'there is no message #99')
      && (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === $n);
check('a message number from another pad can\'t be replied to', str_contains(err(fn () => mb_pad_post($pdo, $evePad(), $eve, 'Cross-pad.', 5)), 'there is no message #5'));
$ev = mb_pad_event($pdo, $evePad(), $eveId, 'An event.');
check('an event can\'t be replied to', str_contains(err(fn () => mb_pad_post($pdo, $evePad(), $eve, 'To an event.', $ev)), "there is no message #$ev"));
$md = mb_export_markdown(mb_pad_read($pdo, $evePad(), null, 50));
check('the export notes which message a reply answers', (bool) preg_match('/^## #3 from @eve, .* UTC \(reply to #1\)$/m', $md) && (bool) preg_match('/^## #1 from @eve, [^()]*$/m', $md));
$pdo->exec("UPDATE principals SET plan_code = 'free' WHERE id = $eveId");
$back = mb_pad_read($pdo, $evePad(), null, 50);
check('back on Free the pad shows flat again but keeps what each reply answered', $back['threaded'] === false && $back['messages'][2]['reply_to'] === 1);
$pdo->prepare('DELETE FROM pads WHERE id = ?')->execute([$tp['id']]);

// A13: open pads can still be used while they last, but never created
@mkdir("$root/data/boards", 0775, true);
$ghost = 'zz-never-made-' . getmypid();
check('A13: posting to an open pad that isn\'t there is refused and creates nothing', str_contains(err(fn () => mb_post($ghost, 'builder', 'hi', false)), 'open pads can no longer be created') && !is_file(mb_path($ghost)));
check('A13: reading one creates nothing either', mb_read($ghost, null, 50, false) === null && !is_file(mb_path($ghost)));
file_put_contents($legacy, json_encode(['name' => 'zz-legacy-taken', 'slug' => 'zz-legacy-taken', 'created' => time(), 'next_id' => 1, 'messages' => []]));
[, $lm] = mb_post('zz-legacy-taken', 'builder', 'still works', false);
check('A13: an existing open pad still takes posts', $lm['id'] === 1);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
