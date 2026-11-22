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
check('the request is recorded in the pad as an event', $events() === ['@sam asked to join through the invite link. The owner can approve or decline on the members page.']);
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
check('events take message numbers but are not posts: the first real post is not throttled by them', $m['id'] === 5);
$r = mb_pad_read($pdo, mb_pad_for($pdo, 'dylan/my-schedule', $dylanId), null, 50);
check('a read marks events apart from posts', array_column($r['messages'], 'kind') === ['event', 'event', 'event', 'event', 'post']);
$before = $pdo->query("SELECT last_activity_at FROM pads WHERE id = $padId")->fetchColumn();
$pdo->exec("UPDATE pads SET last_activity_at = last_activity_at - INTERVAL 3 DAY WHERE id = $padId");
mb_pad_event($pdo, $pad, $dylanId, 'Test event.');
check('an event does not extend the pad\'s retention', $pdo->query("SELECT last_activity_at FROM pads WHERE id = $padId")->fetchColumn() < $before);

// Off, on, reset
mb_invite_set($pdo, $pad, $dylanId, false, null);
check('switched off, the link finds nothing; members stay', mb_invite_by_token($pdo, $inv['token']) === null && mb_pad_for($pdo, 'dylan/my-schedule', $samId) !== null);
mb_invite_set($pdo, $pad, $dylanId, true, null);
check('switched back on, the same link works', mb_invite_by_token($pdo, $inv['token']) !== null);
$new = mb_invite_make($pdo, $pad, $dylanId, true);
check('reset gives a new link and kills the old one', $new['token'] !== $inv['token'] && mb_invite_by_token($pdo, $inv['token']) === null && mb_invite_by_token($pdo, $new['token']) !== null
      && (int) $new['require_approval'] === 0);
$actions = $pdo->query("SELECT action FROM audit_log WHERE action LIKE 'invite.%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
check('link changes and decisions are in the audit log', array_intersect(['invite.create', 'invite.request', 'invite.approve', 'invite.decline', 'invite.approval', 'invite.disable', 'invite.enable', 'invite.reset'], $actions)
      == ['invite.create', 'invite.request', 'invite.approve', 'invite.decline', 'invite.approval', 'invite.disable', 'invite.enable', 'invite.reset'], implode(',', $actions));
$pdo->prepare('DELETE FROM pads WHERE id = ?')->execute([$padId]);
check('deleting a pad deletes its link and requests', (int) $pdo->query('SELECT (SELECT COUNT(*) FROM pad_invites) + (SELECT COUNT(*) FROM pad_join_requests)')->fetchColumn() === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
