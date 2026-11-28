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
putenv('MASHPAD_SECRET_KEY=' . base64_encode(str_repeat('k', 32)));
require_once "$root/src/cron.php";
@mkdir("$root/data", 0775, true);
$GLOBALS['mb_wake_no_defer'] = true; $GLOBALS['mb_messages_no_random_prune'] = true;
$sent = [];
$GLOBALS['mb_cimd_resolver'] = fn ($h) => ['93.184.216.34'];
$GLOBALS['mb_wake_http']     = function ($m, $u, $h, $b, $host, $ip) use (&$sent) { $sent[] = compact('m', 'u', 'b'); return ['status' => 200, 'error' => null, 'transient' => false]; };
$pdo->exec('UPDATE plans SET max_human_seats = NULL, max_agents = NULL, post_interval_seconds = 0');
$adminId = mb_create_human($pdo, 'Dylan', 'dylan', 'dylan@example.com', 'correct horse battery', true);
$cnt = fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();

// ---- the key
check('there is no key to begin with, and nothing is accepted', !mb_cron_key_exists($pdo) && !mb_cron_key_ok($pdo, 'anything') && !mb_cron_key_ok($pdo, ''));
$key = mb_cron_key_create($pdo, $adminId);
check('a key is made, long and random, and only its fingerprint is kept', preg_match('/^mpc_[0-9a-f]{48}$/', $key) === 1 && mb_cron_key_exists($pdo) && mb_setting($pdo, MB_CRON_KEY_SETTING) === hash('sha256', $key) && !str_contains(json_encode($pdo->query('SELECT name, value FROM settings')->fetchAll()), $key));
check('it is accepted, and nothing near it is', mb_cron_key_ok($pdo, $key) && !mb_cron_key_ok($pdo, $key . 'x') && !mb_cron_key_ok($pdo, substr($key, 0, -1)) && !mb_cron_key_ok($pdo, strtoupper($key)) && !mb_cron_key_ok($pdo, ' ' . $key) && !mb_cron_key_ok($pdo, str_repeat('a', 500)) && !mb_cron_key_ok($pdo, hash('sha256', $key)));
check('making the key is recorded, without the key', $cnt("SELECT COUNT(*) FROM audit_log WHERE action = 'cron.key' AND actor_id = $adminId") === 1 && !str_contains(json_encode($pdo->query('SELECT * FROM audit_log')->fetchAll()), $key));
$key2 = mb_cron_key_create($pdo, $adminId);
check('a new key replaces the old one, which stops working', $key2 !== $key && mb_cron_key_ok($pdo, $key2) && !mb_cron_key_ok($pdo, $key));

// ---- how a request carries it
check('a Bearer header, in any case, carries it', mb_cron_key_from_request(['HTTP_AUTHORIZATION' => "Bearer $key2"], []) === $key2 && mb_cron_key_from_request(['HTTP_AUTHORIZATION' => "bearer $key2"], []) === $key2 && mb_cron_key_from_request(['REDIRECT_HTTP_AUTHORIZATION' => "Bearer $key2"], []) === $key2);
check('so does an X-Cron-Key header, and so does ?key=', mb_cron_key_from_request(['HTTP_X_CRON_KEY' => " $key2 "], []) === $key2 && mb_cron_key_from_request([], ['key' => $key2]) === $key2);
check('the header wins over the address, and nonsense gives nothing', mb_cron_key_from_request(['HTTP_AUTHORIZATION' => "Bearer $key2"], ['key' => 'other']) === $key2 && mb_cron_key_from_request([], ['key' => ['a']]) === '' && mb_cron_key_from_request(['HTTP_AUTHORIZATION' => 'Basic abc'], []) === '' && mb_cron_key_from_request([], []) === '');

// ---- over what it may be called
check('https is accepted, directly or behind a proxy, and so is a call from this machine', mb_cron_transport_ok(['HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.9']) && mb_cron_transport_ok(['HTTP_X_FORWARDED_PROTO' => 'HTTPS', 'REMOTE_ADDR' => '203.0.113.9']) && mb_cron_transport_ok(['REMOTE_ADDR' => '127.0.0.1']) && mb_cron_transport_ok(['REMOTE_ADDR' => '::1']));
check('plain http from anywhere else is not, nor is https switched off', !mb_cron_transport_ok(['REMOTE_ADDR' => '203.0.113.9']) && !mb_cron_transport_ok(['HTTPS' => 'off', 'REMOTE_ADDR' => '203.0.113.9']) && !mb_cron_transport_ok(['HTTP_X_FORWARDED_PROTO' => 'http', 'REMOTE_ADDR' => '203.0.113.9']) && !mb_cron_transport_ok([]));

// ---- the job
$r = mb_cron_run($pdo, 'web');
check('with nothing to do the job reports zeros, and how long it took', $r['ok'] === true && $r['sent'] === 0 && $r['pruned'] === 0 && $r['messages_pruned'] === 0 && isset($r['ms']) && $r['ran_at'] >= time() - 5);
$st = mb_cron_status($pdo);
check('it records when it ran, how, and what it did, for the admin page', $st !== null && $st['via'] === 'web' && $st['ok'] === true && $st['ran_at'] === $r['ran_at']);
check('it never ran before, so no status is none', (function () use ($pdo) { $pdo->exec("DELETE FROM settings WHERE name = 'cron_last_run'"); return mb_cron_status($pdo) === null; })());
$agent = mb_create_agent($pdo, $adminId, ['handle' => 'muse'])[0];
mb_wake_target_save($pdo, $agent, $adminId, ['kind' => 'webhook', 'url' => 'https://hooks.example.com/wake/x']);
$pdo->exec("INSERT INTO wake_log (agent_id, reason, status, next_try_at) VALUES ($agent, 'test', 'pending', UTC_TIMESTAMP())");
$r = mb_cron_run($pdo, 'cron');
check('a wake-up that is due is sent, and the job says so', $r['sent'] === 1 && count($sent) === 1 && $cnt("SELECT COUNT(*) FROM wake_log WHERE status = 'sent'") === 1 && mb_cron_status($pdo)['via'] === 'cron');
$bot = mb_create_agent($pdo, $adminId, ['handle' => 'qa-bot'])[0];
$pdo->exec("UPDATE agent_profiles SET can_message_agents = 1 WHERE principal_id IN ($agent, $bot)");
mb_message_send($pdo, mb_principal($pdo, $agent), 'qa-bot', 'old news'); $pdo->exec('UPDATE agent_messages SET created_at = UTC_TIMESTAMP() - INTERVAL 40 DAY');
check('private messages older than the plan keeps them are deleted, and counted', mb_cron_run($pdo, 'cron')['messages_pruned'] === 1 && $cnt('SELECT COUNT(*) FROM agent_messages') === 0);

// ---- overlapping runs
$lockFile = "$root/data/cron.lock"; $held = fopen($lockFile, 'c'); flock($held, LOCK_EX);
$skipped = mb_cron_run($pdo, 'web');
check('a run that starts while another is going does nothing and says so', ($skipped['skipped'] ?? '') === 'already running' && !isset($skipped['sent']));
flock($held, LOCK_UN); fclose($held);
check('and the next run, once it has finished, works', isset(mb_cron_run($pdo, 'web')['sent']));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
