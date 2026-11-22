<?php
/** Plans: wording helpers, the admin account list, and moving an account between plans. */
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
require "$root/src/pads.php";
require "$root/src/plans.php";

$plans = mb_plans($pdo);
check('plans come from the database, cheapest first', array_column($plans, 'code') === ['free', 'plus']);
check('built-in numbers match the seeded plans', array_map(fn ($p) => array_intersect_key($p, MB_PLAN_DEFAULTS[0]), $plans) == MB_PLAN_DEFAULTS, json_encode(array_map(fn ($p) => array_intersect_key($p, MB_PLAN_DEFAULTS[0]), $plans)));
check('without a database the built-in numbers are used', mb_plans(null) === MB_PLAN_DEFAULTS);
check('price wording', mb_plan_price($plans[0]) === 'Free' && mb_plan_price($plans[1]) === '$5 a month' && mb_plan_price(['price_cents' => 1250, 'currency' => 'USD', 'billing_period' => 'year']) === '$12.50 a year');
check('retention wording', mb_plan_retention($plans[0]) === '30 days' && mb_plan_retention($plans[1]) === '6 months');
check('pace wording', mb_plan_pace($plans[0]) === 'one post a minute' && mb_plan_pace($plans[1]) === 'one post every 10 seconds');

$adminId = mb_create_human($pdo, 'Dylan', 'dylan', 'd@example.com', 'correct horse battery', true);
$eveId   = mb_create_human($pdo, 'Eve', 'eve', 'e@example.com', 'correct horse battery');
$eve     = mb_principal($pdo, $eveId);
$pad     = mb_create_pad($pdo, $eve, 'Notes');
mb_create_agent($pdo, $eveId, ['handle' => 'helper']);
$rows = array_column(mb_admin_accounts($pdo), null, 'handle');
check('the account list shows people only, with plan, pads and agents', array_keys($rows) == ['eve', 'dylan'] || array_keys($rows) == ['dylan', 'eve']);
check('counts and default plan', (int) $rows['eve']['pads'] === 1 && (int) $rows['eve']['agents'] === 1 && $rows['eve']['plan_code'] === 'free' && (int) $rows['dylan']['is_admin'] === 1);

mb_pad_post($pdo, mb_pad_for($pdo, 'eve/notes', $eveId), $eve, 'One.');
check('on Free a second post inside a minute is throttled', str_contains(err(fn () => mb_pad_post($pdo, mb_pad_for($pdo, 'eve/notes', $eveId), $eve, 'Two.')), 'the Free plan allows one post per minute'));
$r = mb_set_plan($pdo, $eveId, 'plus', $adminId);
check('moving to Plus reports the change', $r === ['handle' => 'eve', 'plan' => 'Mashpad Plus', 'changed' => true]);
$onPlus = mb_pad_for($pdo, 'eve/notes', $eveId);
check('the account\'s pads follow the new plan at once', $onPlus['plan_code'] === 'plus' && (int) $onPlus['retention_days'] === 180 && (int) $onPlus['post_interval_seconds'] === 10);
check('Plus pace applies: still inside 10 seconds, so refused with the Plus wording', str_contains(err(fn () => mb_pad_post($pdo, $onPlus, $eve, 'Two.')), 'the Mashpad Plus plan allows one post every 10 seconds'));
$pdo->exec('UPDATE messages SET created_at = created_at - INTERVAL 11 SECOND');
check('and after 10 seconds the post goes through, where Free would still refuse', mb_pad_post($pdo, $onPlus, $eve, 'Two.')['id'] === 2);
check('the change is in the audit log with who made it', $pdo->query("SELECT CONCAT(actor_id, ':', detail) FROM audit_log WHERE action = 'account.plan'")->fetchColumn() === "$adminId:free -> plus");
check('saving the same plan again changes nothing', mb_set_plan($pdo, $eveId, 'plus', $adminId)['changed'] === false && (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'account.plan'")->fetchColumn() === 1);
check('back to Free', mb_set_plan($pdo, $eveId, 'free', $adminId)['changed'] === true && mb_pad_for($pdo, 'eve/notes', $eveId)['plan_code'] === 'free');
check('an unknown plan is refused', str_contains(err(fn () => mb_set_plan($pdo, $eveId, 'gold', $adminId)), 'no plan called "gold"'));
$agentId = (int) $pdo->query("SELECT id FROM principals WHERE kind = 'agent' LIMIT 1")->fetchColumn();
check('an agent can\'t be given a plan', str_contains(err(fn () => mb_set_plan($pdo, $agentId, 'plus', $adminId)), 'doesn\'t exist'));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
