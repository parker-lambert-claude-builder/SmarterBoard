<?php
/**
 * Database foundation tests. Needs a disposable MariaDB/MySQL database whose
 * name starts with "mashpad_test"; every table in it is dropped first.
 *
 *   MASHPAD_TEST_DB_HOST=127.0.0.1 MASHPAD_TEST_DB_NAME=mashpad_test \
 *   MASHPAD_TEST_DB_USER=... MASHPAD_TEST_DB_PASS=... php tests/db-foundation.test.php
 *
 * It writes data/config.php, data/schema-version and data/setup-code.txt in this
 * checkout while running and restores whatever was there before when it ends.
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

$files = ["$root/data/config.php", "$root/data/schema-version", "$root/data/setup-code.txt"];
$saved = [];
foreach ($files as $f) { if (is_file($f)) { $saved[$f] = file_get_contents($f); unlink($f); } }
register_shutdown_function(function () use ($files, $saved) {
    foreach ($files as $f) { @unlink($f); if (isset($saved[$f])) file_put_contents($f, $saved[$f]); }
});

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
function throws(callable $fn, string $needle): bool {
    try { $fn(); } catch (Throwable $t) { return stripos($t->getMessage(), $needle) !== false; }
    return false;
}
/** Run code in a fresh PHP process so static caches start empty. */
function fresh(string $code): string {
    global $root;
    $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg("require '$root/src/setup.php'; $code");
    return trim((string) shell_exec($cmd . ' 2>&1'));
}

require "$root/src/setup.php";

// Clean slate.
$admin = mb_db_connect($db);
$admin->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($admin->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) { $admin->exec("DROP TABLE `$t`"); }
$admin->exec('SET FOREIGN_KEY_CHECKS = 1');

check('no config: no connection', mb_db() === null);
check('no config: stage is database', fresh('echo mb_setup_stage();') === 'database');

$code = mb_setup_code();
check('setup code written', is_file("$root/data/setup-code.txt") && preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code) === 1, $code);
check('setup code is stable', mb_setup_code() === $code);
check('wrong setup code rejected', !mb_setup_code_ok('AAAA-BBBB-CCCC'));
check('code accepted without dashes or case', mb_setup_code_ok(strtolower(str_replace('-', '', $code))));
check('empty code rejected', !mb_setup_code_ok(''));

check('bad password explained', throws(fn () => mb_setup_database(['host' => $db['host'], 'port' => $db['port'], 'name' => $db['name'], 'user' => $db['user'], 'pass' => 'nope']), 'refused'));
check('missing fields explained', throws(fn () => mb_setup_database(['host' => '', 'name' => '', 'user' => '']), 'required'));
check('no config written after failures', !is_file("$root/data/config.php"));

$label = mb_setup_database($db + ['pass' => $db['pass']]);
check('connects and reports server', (bool) preg_match('/^(MariaDB|MySQL) \d+\.\d+\.\d+$/', $label), $label);
check('config written', is_file("$root/data/config.php"));
check('config is private (0600)', (fileperms("$root/data/config.php") & 0777) === 0600, decoct(fileperms("$root/data/config.php") & 0777));
check('schema version cached', trim((string) file_get_contents("$root/data/schema-version")) === mb_latest_migration());

$plans = $admin->query('SELECT * FROM plans ORDER BY sort_order')->fetchAll(PDO::FETCH_ASSOC);
check('two plans seeded', count($plans) === 2);
check('free plan: $0, 30 days, 60s, 1 seat', $plans[0]['code'] === 'free' && (int) $plans[0]['price_cents'] === 0 && (int) $plans[0]['retention_days'] === 30 && (int) $plans[0]['post_interval_seconds'] === 60 && (int) $plans[0]['max_human_seats'] === 1);
check('plus plan: $5, 180 days, 10s, threading and uploads', $plans[1]['code'] === 'plus' && (int) $plans[1]['price_cents'] === 500 && (int) $plans[1]['retention_days'] === 180 && (int) $plans[1]['post_interval_seconds'] === 10 && (int) $plans[1]['threading'] === 1 && (int) $plans[1]['uploads'] === 1);
check('migrations are idempotent', mb_migrate($admin) === []);
check('stage moves to admin', fresh('echo mb_setup_stage();') === 'admin');

$pdo = $admin;
check('password mismatch rejected', throws(fn () => fresh_admin(['password' => 'correct horse battery', 'password2' => 'different words here']), 'match'));
function fresh_admin(array $in): string {
    $base = ['display_name' => 'Dylan', 'handle' => 'dylan', 'email' => 'Dylan@Example.com', 'password' => 'correct horse battery', 'password2' => 'correct horse battery'];
    $out = fresh('try { echo "id=" . mb_setup_admin(' . var_export($in + $base, true) . '); } catch (Throwable $t) { echo "error=" . $t->getMessage(); }');
    if (str_starts_with($out, 'error=')) throw new RuntimeException(substr($out, 6));
    return $out;
}
check('short password rejected', throws(fn () => fresh_admin(['password' => 'short', 'password2' => 'short']), 'at least 12'));
check('bad handle rejected', throws(fn () => fresh_admin(['handle' => 'no spaces!']), 'Handles are'));
check('bad email rejected', throws(fn () => fresh_admin(['email' => 'not-an-email']), 'valid email'));
check('nothing created by rejected attempts', (int) $pdo->query('SELECT COUNT(*) FROM principals')->fetchColumn() === 0);

$out = fresh_admin([]);
check('admin created', str_starts_with($out, 'id='), $out);
$row = $pdo->query("SELECT * FROM principals WHERE handle = 'dylan'")->fetch(PDO::FETCH_ASSOC);
check('admin is human, admin, free plan', $row && $row['kind'] === 'human' && (int) $row['is_admin'] === 1 && $row['plan_code'] === 'free');
check('email normalised', $row['email_normalized'] === 'dylan@example.com');
check('password hashed, verifies', $row['password_hash'] !== 'correct horse battery' && password_verify('correct horse battery', $row['password_hash']));
check('wrong password does not verify', !password_verify('correct horse battery!', $row['password_hash']));
check('setup code retired', !is_file("$root/data/setup-code.txt"));
check('stage is done', fresh('echo mb_setup_stage();') === 'done');
check('second admin refused', throws(fn () => fresh_admin(['handle' => 'other', 'email' => 'o@example.com']), 'not at the account step'));
check('admin creation audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'account.create_admin'")->fetchColumn() === 1);
check('duplicate email refused', throws(fn () => mb_create_human($pdo, 'X', 'someoneelse', 'DYLAN@example.com', 'correct horse battery'), 'already in use'));
check('duplicate handle refused', throws(fn () => mb_create_human($pdo, 'X', 'dylan', 'x@example.com', 'correct horse battery'), 'already in use'));
check('unicode name length counted in characters', mb_ulen('Zoë 🌱') === 5);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
