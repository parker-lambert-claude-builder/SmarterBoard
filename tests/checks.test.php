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
require "$root/src/home.php";
require "$root/src/invites.php";

// Saying the interval
check('an interval in words', mb_interval_words(900) === '15 minutes' && mb_interval_words(3600) === '1 hour' && mb_interval_words(7200) === '2 hours' && mb_interval_words(60) === '1 minute' && mb_interval_words(90) === '90 seconds' && mb_interval_words(1800) === '30 minutes');
check('the interval is 15 minutes unless the plan says otherwise, and is kept to something sensible', mb_check_interval([]) === 900 && mb_check_interval(['check_interval_seconds' => null]) === 900 && mb_check_interval(['check_interval_seconds' => 0]) === 900
      && mb_check_interval(['check_interval_seconds' => 30]) === 60 && mb_check_interval(['check_interval_seconds' => 999999]) === 86400 && mb_check_interval(['check_interval_seconds' => '1800']) === 1800);
check('both plans start at 900, in the table and in the fallback list', array_unique(array_map('mb_check_interval', mb_plans($pdo))) === [900] && array_unique(array_column(MB_PLAN_DEFAULTS, 'check_interval_seconds')) === [900]);

// How long ago, and the line about it
check('how long ago, in words', mb_ago_words(10) === 'just now' && mb_ago_words(120) === '2 minutes ago' && mb_ago_words(3600) === '1 hour ago' && mb_ago_words(7200) === '2 hours ago' && mb_ago_words(172800) === '2 days ago' && mb_ago_words(-5) === 'just now');
$now = 1_800_000_000; $iv = 900;
check('an agent that just joined has not read yet, and that is not late', mb_last_read_line(null, $now - 120, $iv, $now) === ['ts' => null, 'text' => 'Hasn\'t read this pad yet.', 'overdue' => false]);
check('one that joined more than two intervals ago and never read is late, and says what that means', mb_last_read_line(null, $now - 2 * $iv - 1, $iv, $now) === ['ts' => null, 'text' => 'Hasn\'t read this pad yet. Its check may not be set up.', 'overdue' => true]
      && mb_last_read_line(null, $now - 2 * $iv, $iv, $now)['overdue'] === false);
check('a recent read is plain', mb_last_read_line($now - 300, $now - 9999, $iv, $now) === ['ts' => $now - 300, 'text' => 'Last read 5 minutes ago.', 'overdue' => false]);
check('four intervals is the edge: past it, the read is late and says its check may have stopped', mb_last_read_line($now - 4 * $iv, 0, $iv, $now)['overdue'] === false
      && mb_last_read_line($now - 4 * $iv - 1, 0, $iv, $now) === ['ts' => $now - 4 * $iv - 1, 'text' => 'Last read 1 hour ago. Its check may have stopped.', 'overdue' => true]);
check('what counts as late follows the pad\'s own interval', mb_last_read_line($now - 5400, 0, 3600, $now)['overdue'] === false && mb_last_read_line($now - 5400, 0, 60, $now)['overdue'] === true);

// Recording a read
$dylanId = mb_create_human($pdo, 'Dylan', 'dylan', 'd@example.com', 'correct horse battery', true);
$dylan   = mb_principal($pdo, $dylanId);
$pad     = mb_create_pad($pdo, $dylan, 'Launch');
$other   = mb_create_pad($pdo, $dylan, 'Other');
[$museId] = mb_create_agent($pdo, $dylanId, ['handle' => 'muse']);
[$buildId] = mb_create_agent($pdo, $dylanId, ['handle' => 'builder']);
foreach ([$pad, $other] as $p) { mb_add_member($pdo, $p, $museId, 'added'); }
mb_add_member($pdo, $pad, $buildId, 'added');
$lr = fn (array $p, int $who) => $pdo->query("SELECT last_read_at FROM pad_members WHERE pad_id = {$p['id']} AND principal_id = $who")->fetchColumn();
check('nobody has read anything yet', $lr($pad, $museId) === null && $lr($pad, $buildId) === null && $lr($pad, $dylanId) === null);
mb_pad_mark_read($pdo, (int) $pad['id'], $museId);
check('a read marks that agent on that pad and no one else, and no other pad', $lr($pad, $museId) !== null && abs(strtotime($lr($pad, $museId) . ' UTC') - time()) < 5 && $lr($pad, $buildId) === null && $lr($other, $museId) === null && $lr($pad, $dylanId) === null);
mb_pad_mark_seen($pdo, (int) $pad['id'], $buildId, 5);
check('a browser\'s last-seen marker is a different thing: it doesn\'t record a read', $lr($pad, $buildId) === null);
$before = $lr($pad, $museId);
mb_pad_mark_read($pdo, (int) $other['id'], $buildId);
check('and a read by someone who isn\'t a member records nothing', ($lr($other, $buildId) ?: null) === null && $lr($pad, $museId) === $before);
mb_remove_member($pdo, $pad, $museId, $dylanId);
$pdo->exec("UPDATE pad_members SET last_read_at = NULL WHERE pad_id = {$pad['id']}");
mb_pad_mark_read($pdo, (int) $pad['id'], $museId);
check('a member who has been removed isn\'t marked', $lr($pad, $museId) === null);
mb_add_member($pdo, $pad, $museId, 'added');

// Where it is shown
$pdo->exec("UPDATE pad_members SET joined_at = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE pad_id = {$pad['id']}");
$members = array_column(mb_pad_members($pdo, (int) $pad['id']), null, 'handle');
check('the member list carries each member\'s last read', array_key_exists('last_read_at', $members['muse']) && $members['muse']['last_read_at'] === null);
$part = mb_pad_participant($pdo, mb_pad_by_address($pdo, 'dylan', 'launch'), 'builder');
check('an agent\'s participant record says it has not read and is late, since it joined a day ago', $part['last_read']['overdue'] === true && $part['last_read']['ts'] === null && str_contains($part['last_read']['text'], 'Its check may not be set up'), json_encode($part['last_read'] ?? null));
mb_pad_mark_read($pdo, (int) $pad['id'], $buildId);
$part = mb_pad_participant($pdo, mb_pad_by_address($pdo, 'dylan', 'launch'), 'builder');
check('after a read it says so', $part['last_read']['overdue'] === false && $part['last_read']['text'] === 'Last read just now.', json_encode($part['last_read']));
check('a person has no last-read line', !array_key_exists('last_read', mb_pad_participant($pdo, mb_pad_by_address($pdo, 'dylan', 'launch'), 'dylan')));

// An agent that can't run a recurring check can say so
check('an agent that says it only reads in sessions is never late, and the line says what it said', mb_last_read_line(null, $now - 99999999, $iv, $now, 'sessions') === ['ts' => null, 'text' => 'Reads only when its person starts a session. Hasn\'t read this pad yet.', 'overdue' => false]
      && mb_last_read_line($now - 5 * 86400, 0, $iv, $now, 'sessions') === ['ts' => $now - 5 * 86400, 'text' => 'Reads only when its person starts a session. Last read 5 days ago.', 'overdue' => false]);
check('one that says recurring, or says nothing, is judged the same: late is late', mb_last_read_line($now - 5 * $iv, 0, $iv, $now, 'recurring')['overdue'] === true && mb_last_read_line($now - 5 * $iv, 0, $iv, $now, null)['overdue'] === true
      && mb_last_read_line($now - 5 * $iv, 0, $iv, $now, 'recurring') === mb_last_read_line($now - 5 * $iv, 0, $iv, $now));
$eventsOf = fn (array $p) => $pdo->query("SELECT body FROM messages WHERE pad_id = {$p['id']} AND kind = 'event' ORDER BY seq")->fetchAll(PDO::FETCH_COLUMN);
$lastEvent = fn (array $p) => array_slice($eventsOf($p), -1)[0] ?? '';
check('an agent has not said how it checks until it does', mb_principal($pdo, $museId)['checks'] === null);
check('saying it reads in sessions is a profile change, with an event on every pad it is on', mb_update_agent_profile($pdo, $museId, ['checks' => 'sessions']) === ['checks'] && mb_principal($pdo, $museId)['checks'] === 'sessions'
      && $lastEvent($pad) === '@muse updated its profile: says it reads its pads only when its person starts a session.' && $lastEvent($other) === $lastEvent($pad));
$n = count($eventsOf($pad));
check('saying the same again changes nothing and adds no event', mb_update_agent_profile($pdo, $museId, ['checks' => ' Sessions ']) === [] && count($eventsOf($pad)) === $n);
check('a value that is neither is refused, naming the two', str_contains(err(fn () => mb_update_agent_profile($pdo, $museId, ['checks' => 'sometimes'])), '"recurring"') && mb_principal($pdo, $museId)['checks'] === 'sessions');
check('changing other details leaves it alone', mb_update_agent_profile($pdo, $museId, ['role' => 'QA']) === ['role'] && mb_principal($pdo, $museId)['checks'] === 'sessions');
$part = mb_pad_participant($pdo, mb_pad_by_address($pdo, 'dylan', 'launch'), 'muse');
check('the participant record carries it, and its last-read line says so and is never late', $part['checks'] === 'sessions' && str_starts_with($part['last_read']['text'], 'Reads only when its person starts a session.') && $part['last_read']['overdue'] === false);
check('and so does the member list', array_column(mb_pad_members($pdo, (int) $pad['id']), 'checks', 'handle')['muse'] === 'sessions');
check('saying recurring is recorded and announced', mb_update_agent_profile($pdo, $museId, ['checks' => 'recurring']) === ['checks'] && $lastEvent($pad) === '@muse updated its profile: says it checks its pads on a recurring schedule.');
check('an empty value clears it', mb_update_agent_profile($pdo, $museId, ['checks' => '']) === ['checks'] && mb_principal($pdo, $museId)['checks'] === null && str_contains($lastEvent($pad), 'cleared how it checks its pads'));

// The plan sets the interval
$padRow = mb_pad_by_address($pdo, 'dylan', 'launch');
check('a pad carries its owner\'s plan interval', mb_check_interval($padRow) === 900 && mb_check_interval(mb_pads_for($pdo, $museId)[0]) === 900);
$pdo->exec("UPDATE plans SET check_interval_seconds = 3600 WHERE code = 'free'");
check('changing the plan\'s value changes it for the pad, for an agent\'s pad list, and for the handshake', mb_check_interval(mb_pad_by_address($pdo, 'dylan', 'launch')) === 3600 && mb_check_interval(mb_pads_for($pdo, $museId)[0]) === 3600 && mb_default_check_interval($pdo) === 3600);
check('and with no database the fallback is 15 minutes', mb_default_check_interval(null) === 900);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
