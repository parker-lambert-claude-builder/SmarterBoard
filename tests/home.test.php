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
$pdo->exec('UPDATE plans SET max_human_seats = NULL, max_agents = NULL');      // this suite isn't about plan limits: tests/limits.test.php is
mb_write_config($db);
require "$root/src/home.php";
require "$root/src/invites.php";

$dylanId = mb_create_human($pdo, 'Dylan', 'dylan', 'd@example.com', 'correct horse battery', true);
$samId   = mb_create_human($pdo, 'Sam', 'sam', 's@example.com', 'correct horse battery');
$dylan   = mb_principal($pdo, $dylanId);
$sam     = mb_principal($pdo, $samId);
/** A post, written straight into the table so a test can write many at once (the post allowance doesn't apply). */
function post(PDO $pdo, array $pad, int $by, string $body, string $kind = 'post', ?string $when = null): int {
    $pdo->beginTransaction();
    $seq = (int) $pdo->query('SELECT next_seq FROM pads WHERE id = ' . (int) $pad['id'] . ' FOR UPDATE')->fetchColumn();
    $pdo->prepare('INSERT INTO messages (pad_id, seq, author_id, body, kind' . ($when ? ', created_at' : '') . ') VALUES (?, ?, ?, ?, ?' . ($when ? ', ?' : '') . ')')
        ->execute(array_merge([$pad['id'], $seq, $by, $body, $kind], $when ? [$when] : []));
    $pdo->prepare('UPDATE pads SET next_seq = next_seq + 1 WHERE id = ?')->execute([$pad['id']]);
    $pdo->commit();
    return $seq;
}
$home = fn (int $id) => mb_home_summary($pdo, $id);

// First lines
check('the first line of a post is plain: markdown marks gone, spaces collapsed', mb_first_line("## Heading  with   *bold*\nmore") === 'Heading with bold' && mb_first_line("\n\n- a `bullet` point") === 'a bullet point' && mb_first_line("> quoted") === 'quoted' && mb_first_line("  \n ") === '');
check('and it is cut with … when long', mb_first_line(str_repeat('word ', 60)) !== str_repeat('word ', 60) && str_ends_with(mb_first_line(str_repeat('word ', 60)), '…') && mb_strlen(mb_first_line(str_repeat('word ', 60))) <= 110);

// A pad with a member who joins late
$pad = mb_create_pad($pdo, $dylan, 'Launch');
[$museId] = mb_create_agent($pdo, $dylanId, ['handle' => 'muse']);
mb_add_member($pdo, $pad, $museId, 'added');
post($pdo, $pad, $dylanId, 'Before Sam arrived.');
post($pdo, $pad, $museId, 'Also before.');
mb_add_member($pdo, $pad, $samId, 'added');
check('someone who has just joined has nothing new: what came before them is not new to them', $home($samId)['activity'] === [] && $home($samId)['stats']['pads'] === 1);
post($pdo, $pad, $museId, "## QA for the build: PASS\nDetails below.");
post($pdo, $pad, $dylanId, 'Thanks, shipping.');
post($pdo, $pad, $samId, 'My own post is never new to me.');
$s = $home($samId)['activity'];
check('new posts from other people appear as one card, with the count, who posted last, and the opening line', count($s) === 1 && $s[0]['key'] === 'dylan/launch' && $s[0]['name'] === 'Launch' && $s[0]['new'] === 2 && $s[0]['author'] === 'dylan' && $s[0]['snippet'] === 'Thanks, shipping.', json_encode($s));
$d = $home($dylanId)['activity'];
check('what is new differs per person: Dylan\'s own posts don\'t count for him, but Muse\'s and Sam\'s do', count($d) === 1 && $d[0]['new'] === 3 && $d[0]['author'] === 'sam', json_encode($d));
mb_pad_event($pdo, $pad, $museId, '@muse updated its profile.');
check('events are not posts, so they never make a card or a count', $home($samId)['activity'][0]['new'] === 2);
$latest = (int) $pdo->query("SELECT next_seq - 1 FROM pads WHERE id = {$pad['id']}")->fetchColumn();
mb_pad_mark_seen($pdo, (int) $pad['id'], $samId, $latest);
check('opening the pad clears its card', $home($samId)['activity'] === [] && count($home($dylanId)['activity']) === 1);
mb_pad_mark_seen($pdo, (int) $pad['id'], $samId, 1);
check('the marker only ever moves forward', $home($samId)['activity'] === []);
post($pdo, $pad, $museId, 'A fresh one.');
check('and a later post makes a card again', $home($samId)['activity'][0]['new'] === 1 && $home($samId)['activity'][0]['snippet'] === 'A fresh one.');
mb_pad_mark_seen($pdo, (int) $pad['id'], $dylanId, 9999);
check('Dylan opening the pad leaves Sam\'s card', $home($samId)['activity'][0]['new'] === 1 && $home($dylanId)['activity'] === []);
mb_remove_member($pdo, $pad, $samId, $dylanId);
check('someone removed from a pad sees no card and no count for it', $home($samId)['activity'] === [] && $home($samId)['stats']['pads'] === 0);
mb_add_member($pdo, $pad, $samId, 'added');
check('and when added back they start up to date again', $home($samId)['activity'] === []);

// Ordering and the cap
$pads = [];
for ($i = 1; $i <= 12; $i++) {
    $p = mb_create_pad($pdo, $dylan, "Pad $i");
    mb_add_member($pdo, $p, $samId, 'added');
    $pads[$i] = $p;
}
foreach ($pads as $i => $p) { post($pdo, $p, $dylanId, "Hello from pad $i", 'post', gmdate('Y-m-d H:i:s', time() - 3600 + $i * 60)); }
$cards = $home($samId);
check('at most ten cards, newest first, and the rest are counted', count($cards['activity']) === 10 && $cards['more'] === 2 && $cards['activity'][0]['name'] === 'Pad 12' && $cards['activity'][9]['name'] === 'Pad 3', json_encode(array_column($cards['activity'], 'name')) . ' more=' . $cards['more']);

// Needs you
$need = mb_create_pad($pdo, $dylan, 'Needs');
$inv  = mb_invite_make($pdo, $need, $dylanId);
$inv2 = mb_invite_make($pdo, $pads[1], $dylanId);
[$g1] = mb_invite_guest_agent($pdo, $need, ['handle' => 'guest-one', 'acts_for' => 'A'], false);
[$g2] = mb_invite_guest_agent($pdo, $need, ['handle' => 'guest-two', 'acts_for' => 'B'], false);
[$g3] = mb_invite_guest_agent($pdo, $pads[1], ['handle' => 'guest-three', 'acts_for' => 'C'], false);
mb_invite_use($pdo, $inv, $need, $g1); mb_invite_use($pdo, $inv, $need, $g2); mb_invite_use($pdo, $inv2, $pads[1], $g3);
$n = $home($dylanId)['needs'];
check('join requests are counted per pad the person owns, with the pad\'s address, oldest first', count($n['requests']) === 2 && $n['requests'][0] === ['key' => 'dylan/needs', 'name' => 'Needs', 'count' => 2] && $n['requests'][1] === ['key' => 'dylan/pad-1', 'name' => 'Pad 1', 'count' => 1], json_encode($n));
check('and only for pads they own: Sam, who owns none, has none', $home($samId)['needs']['requests'] === [] && $home($samId)['needs']['guests'] === 0);
check('guest agents on their pads that nobody has claimed are counted', $n['guests'] === 3);
[$claimer] = [mb_create_human($pdo, 'Lee', 'lee', 'l@example.com', 'correct horse battery')];
$pdo->prepare('UPDATE agent_profiles SET claimed_at = UTC_TIMESTAMP() WHERE principal_id = ?')->execute([$g1['id']]);
check('a claimed guest is no longer waiting', $home($dylanId)['needs']['guests'] === 2);
mb_delete_agent($pdo, $dylanId, (int) $g2['id']);
check('a removed agent isn\'t counted', $home($dylanId)['needs']['guests'] === 1);
check('an agent set up by the person is not a guest waiting to be claimed', $home($dylanId)['needs']['guests'] === 1 && mb_principal($pdo, $museId)['kind'] === 'agent');

// Numbers
$pdo->exec("UPDATE messages SET created_at = UTC_TIMESTAMP() - INTERVAL 8 DAY WHERE pad_id = {$pad['id']} AND seq = 1");
$st = $home($dylanId)['stats'];
$expected = (int) $pdo->query("SELECT COUNT(*) FROM messages x JOIN pad_members m ON m.pad_id = x.pad_id AND m.principal_id = $dylanId AND m.removed_at IS NULL WHERE x.kind = 'post' AND x.created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY")->fetchColumn();
check('the strip counts pads, the agents they are responsible for (guests on their pads included), and posts in the last seven days (not events, not older posts)', $st['pads'] === 14 && $st['agents'] === 3 && $st['posts_week'] === $expected && $expected > 12, json_encode($st) . " expected $expected");
$pdo->exec("INSERT INTO agent_wake_targets (principal_id, kind, url, failures, paused_at, pause_reason, created_by) VALUES ($museId, 'webhook', 'https://hooks.example.com/w', 5, UTC_TIMESTAMP(), 'five failures', $dylanId)");
$wk = $home($dylanId)['needs']['wakes'];
check('a wake-up paused by failures is something that needs the person', count($wk) === 1 && isset($wk[0]['id'], $wk[0]['handle']) && $home($claimer)['needs']['wakes'] === []);
$pdo->exec("UPDATE agent_wake_targets SET paused_at = UTC_TIMESTAMP(), failures = 0, pause_reason = 'Paused by you.'");
check('one the person paused by hand is not', $home($dylanId)['needs']['wakes'] === []);
$pdo->exec('DELETE FROM agent_wake_targets');
check('a person with no pads has zeros and no cards', $home($claimer) === ['needs' => ['requests' => [], 'guests' => 0, 'wakes' => []], 'activity' => [], 'more' => 0, 'stats' => ['pads' => 0, 'agents' => 0, 'posts_week' => 0]]);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
