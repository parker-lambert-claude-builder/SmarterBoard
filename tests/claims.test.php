<?php
/** Claiming a guest agent: who can be claimed, by whom, and what changes. */
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
require "$root/src/claims.php";

$dylanId = mb_create_human($pdo, 'Dylan', 'dylan', 'd@example.com', 'correct horse battery', true);
$leeId   = mb_create_human($pdo, 'Lee Park', 'lee', 'l@example.com', 'correct horse battery');
$malId   = mb_create_human($pdo, 'Mallory', 'mallory', 'm@example.com', 'correct horse battery');
$dylan   = mb_principal($pdo, $dylanId);
$pad     = mb_create_pad($pdo, $dylan, 'My Schedule');
$pad2    = mb_create_pad($pdo, $dylan, 'Second');
$padId   = (int) $pad['id'];
$inv     = mb_invite_make($pdo, $pad, $dylanId);
mb_invite_set($pdo, $pad, $dylanId, null, false);
$inv     = mb_invite($pdo, $padId);

// A guest joins on its own; Dylan also has an agent he set up, and one that joined with his code
[$guest, $guestToken] = mb_invite_guest_agent($pdo, $pad, ['handle' => 'lee-assistant', 'acts_for' => 'Lee Park']);
mb_invite_use($pdo, $inv, $pad, $guest);
mb_add_member($pdo, $pad2, (int) $guest['id'], 'added');
[$museId, $museToken] = mb_create_agent($pdo, $dylanId, ['handle' => 'muse']);
mb_add_member($pdo, $pad, $museId, 'added');
[$lobbyCode] = mb_lobby_create($pdo, $pad, $dylanId);
[$lobbyBot]  = mb_join_pad($pdo, 'dylan/my-schedule', $lobbyCode, null, ['handle' => 'codebot']);
$fresh = fn (int $id) => mb_principal($pdo, $id);

check('a guest is a guest; agents someone set up or invited by code are not', mb_is_guest_agent($fresh((int) $guest['id'])) && !mb_is_guest_agent($fresh($museId)) && !mb_is_guest_agent($fresh((int) $lobbyBot['id'])) && !mb_is_guest_agent($dylan));

[$code, $exp] = mb_claim_create($pdo, $leeId);
check('a claim code looks like a lobby code and lasts ten minutes', (bool) preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code) && abs($exp - time() - 600) < 5 && mb_claim_pending($pdo, $leeId) !== null);
check('only its hash is stored', !$pdo->query('SELECT COUNT(*) FROM agent_claims WHERE code_hash = ' . $pdo->quote($code))->fetchColumn());

// The agents that must not be claimable
$m = err(fn () => mb_claim_accept($pdo, $fresh($museId), $code));
check('an agent its owner set up refuses the code and says why', str_contains($m, 'you already have a responsible person on Mashpad (@dylan)') && str_contains($m, 'nothing has changed'), $m);
check('an agent that joined with its owner\'s ten-minute code refuses too', str_contains(err(fn () => mb_claim_accept($pdo, $fresh((int) $lobbyBot['id']), $code)), 'already have a responsible person'));
check('neither moved, and the code was not used up by the attempts', (int) $fresh($museId)['owner_id'] === $dylanId && (int) $fresh((int) $lobbyBot['id'])['owner_id'] === $dylanId
      && (int) $pdo->query('SELECT COUNT(*) FROM agent_claims WHERE used_at IS NULL')->fetchColumn() === 1);
check('a wrong code is refused', str_contains(err(fn () => mb_claim_accept($pdo, $fresh((int) $guest['id']), 'AAAA-BBBB')), 'isn\'t valid') && (int) $fresh((int) $guest['id'])['owner_id'] === $dylanId);

// The guest accepts Lee's code
$before = (int) $pdo->query('SELECT COUNT(*) FROM messages WHERE kind = \'event\'')->fetchColumn();
$r = mb_claim_accept($pdo, $fresh((int) $guest['id']), strtolower(str_replace('-', ' ', $code)));
$g = $fresh((int) $guest['id']);
check('the guest accepts (code typed loosely) and now belongs to the person who made the code', $r['owner'] === 'lee' && (int) $g['owner_id'] === $leeId && mb_owned_agent($pdo, $leeId, (int) $g['id']) !== null && mb_owned_agent($pdo, $dylanId, (int) $g['id']) === null);
check('it keeps its handle, its token and both its pads', $g['handle'] === 'lee-assistant' && (int) mb_agent_by_token($pdo, $guestToken)['id'] === (int) $g['id']
      && $r['pads'] == ['dylan/second', 'dylan/my-schedule'] || $r['pads'] == ['dylan/my-schedule', 'dylan/second']);
check('it is no longer a guest, and its self-declared "acts for" is dropped', !mb_is_guest_agent($g) && $g['acts_for'] === null && $g['claimed_at'] !== null);
$events = $pdo->query("SELECT body FROM messages WHERE kind = 'event' ORDER BY id DESC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
check('every pad it is on records the change', (int) $pdo->query('SELECT COUNT(*) FROM messages WHERE kind = \'event\'')->fetchColumn() === $before + 2
      && $events[0] === '@lee-assistant is now managed by @lee, who claimed it. Until now it was a guest on this pad.');
check('the claim is in the audit log with who it came from', $pdo->query("SELECT detail FROM audit_log WHERE action = 'claim.accept'")->fetchColumn() === "from principal:$dylanId");
check('the pad\'s owner can still remove it from their pad', (function () use ($pdo, $pad, $g, $dylanId) { mb_remove_member($pdo, $pad, (int) $g['id'], $dylanId); return mb_pad_for($pdo, 'dylan/my-schedule', (int) $g['id']) === null; })());

// Once only
check('a used code can\'t be used again', str_contains(err(fn () => mb_claim_accept($pdo, $fresh($museId), $code)), 'already have a responsible person'));
[$mcode] = mb_claim_create($pdo, $malId);
check('a claimed agent can\'t be claimed away by someone else', str_contains(err(fn () => mb_claim_accept($pdo, $fresh((int) $g['id']), $mcode)), 'you already have a responsible person on Mashpad (@lee)') && (int) $fresh((int) $g['id'])['owner_id'] === $leeId);

// Expiry, replacement, name clash
[$guest2] = mb_invite_guest_agent($pdo, $pad, ['handle' => 'helper', 'acts_for' => 'Mallory']);
mb_invite_use($pdo, $inv, $pad, $guest2);
[$old] = mb_claim_create($pdo, $malId);
[$new] = mb_claim_create($pdo, $malId);
check('asking for a new code cancels the person\'s earlier unused one', str_contains(err(fn () => mb_claim_accept($pdo, $fresh((int) $guest2['id']), $old)), 'isn\'t valid'));
$pdo->exec('UPDATE agent_claims SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE used_at IS NULL');
check('an expired code is refused', str_contains(err(fn () => mb_claim_accept($pdo, $fresh((int) $guest2['id']), $new)), 'isn\'t valid') && mb_claim_pending($pdo, $malId) === null);
mb_create_agent($pdo, $malId, ['handle' => 'helper']);
[$clashCode] = mb_claim_create($pdo, $malId);
$c = err(fn () => mb_claim_accept($pdo, $fresh((int) $guest2['id']), $clashCode));
check('a claim that would give one person two agents of the same name is refused, unchanged', str_contains($c, '@mallory already has an agent called @helper') && (int) $fresh((int) $guest2['id'])['owner_id'] === $dylanId
      && (int) $pdo->query('SELECT COUNT(*) FROM agent_claims WHERE used_at IS NULL')->fetchColumn() === 1, $c);
check('a person can\'t be "claimed"', str_contains(err(fn () => mb_claim_accept($pdo, $fresh($leeId), $clashCode)), 'isn\'t valid'));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
