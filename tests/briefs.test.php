<?php
/**
 * Pinned pad brief: versions, authorship, the stale-save refusal, limits, history, export.
 * Same database rules as the other suites (mashpad_test*, tables dropped).
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
require "$root/src/briefs.php";

$dylanId = mb_create_human($pdo, 'Dylan', 'dylan', 'd@example.com', 'correct horse battery', true);
$dylan   = mb_principal($pdo, $dylanId);
// The pad is set up by an agent for its person. Only the person may write the About text.
[$museId] = mb_create_agent($pdo, $dylanId, ['handle' => 'muse'], 'owner', true);
$muse    = mb_principal($pdo, $museId);
$pad     = mb_create_pad($pdo, $muse, 'Launch');
[$helperId] = mb_create_agent($pdo, $dylanId, ['handle' => 'helper']);
$eveId   = mb_create_human($pdo, 'Eve', 'eve', 'e@example.com', 'correct horse battery');
mb_add_member($pdo, $pad, $helperId, 'added');
mb_add_member($pdo, $pad, $eveId, 'added');
$padId   = (int) $pad['id'];

check('a new pad has no brief', mb_brief($pdo, $padId) === null && mb_brief_history($pdo, $padId) === []);
$v1 = mb_brief_save($pdo, $pad, $dylan, "## Objective\r\nShip it.\n\n", 0);
check('first save is version 1 with its author; line endings normalised, trailing space trimmed', $v1['version'] === 1 && $v1['author'] === 'dylan' && $v1['body'] === "## Objective\nShip it." && $v1['ts'] > 0);
$agentTry = err(fn () => mb_brief_save($pdo, $pad, $muse, 'Written by the agent that created the pad.', 1));
check('the agent that created the pad for its person can\'t write the About text either', str_contains($agentTry, 'only the person this pad belongs to can change its About text') && mb_brief($pdo, $padId)['version'] === 1, $agentTry);
$v2 = mb_brief_save($pdo, $pad, $dylan, "## Objective\nShip it.\n\n## Decisions\n- Free plan first (#4)", 1);
check('the owner saves version 2', $v2['version'] === 2 && $v2['author'] === 'dylan' && $v2['author_kind'] === 'human');
check('the current brief is the newest version', mb_brief($pdo, $padId)['version'] === 2);
check('earlier versions stay readable', mb_brief($pdo, $padId, 1)['body'] === "## Objective\nShip it." && mb_brief($pdo, $padId, 9) === null);

$c = null;
try { mb_brief_save($pdo, $pad, $dylan, 'Written against the old one.', 1); } catch (MbBriefConflict $e) { $c = $e; }
check('a save against an older version is refused', $c !== null && mb_brief($pdo, $padId)['version'] === 2);
check('the refusal says what happened and returns the current brief', $c && str_contains($c->getMessage(), 'You started from version 1; it is now version 2, saved by @dylan')
      && str_contains($c->getMessage(), 'Your text was not saved') && $c->current['version'] === 2 && str_contains($c->current['body'], 'Free plan first'));
check('a save with no base on a pad that has a brief is refused too', str_contains(err(fn () => mb_brief_save($pdo, $pad, $dylan, 'x', 0)), 'changed while you were editing'));

foreach (['helper' => $helperId, 'eve' => $eveId] as $name => $id) {
    $msg = err(fn () => mb_brief_save($pdo, $pad, mb_principal($pdo, $id), 'Mine now.', 2));
    check("a member who didn't create the pad (@$name) can't change the About text", str_contains($msg, 'only the person this pad belongs to can change its About text'), $msg);
}
check('nothing was saved by them, and they can still read it', mb_brief($pdo, $padId)['version'] === 2 && mb_can_edit_about($pad, mb_principal($pdo, $eveId)) === false
      && mb_can_edit_about($pad, $dylan) && !mb_can_edit_about($pad, $muse) && !mb_can_edit_about($pad, []));
$same = mb_brief_save($pdo, $pad, $dylan, $v2['body'] . "\n", 2);
check('saving unchanged text makes no new version', $same['version'] === 2 && count(mb_brief_history($pdo, $padId)) === 2);
check('the size limit is enforced with the numbers', str_contains(err(fn () => mb_brief_save($pdo, $pad, $dylan, str_repeat('a', MB_BRIEF_MAX + 1), 2)), (MB_BRIEF_MAX + 1) . ' bytes and the limit is ' . MB_BRIEF_MAX));
$v3 = mb_brief_save($pdo, $pad, $dylan, '', 2);
check('an empty save clears the brief as a new version; history remains', $v3['version'] === 3 && $v3['body'] === '' && count(mb_brief_history($pdo, $padId)) === 3);
$h = mb_brief_history($pdo, $padId);
check('history is newest first with author, time and size, no text', array_column($h, 'version') === [3, 2, 1] && $h[1]['author'] === 'dylan' && $h[0]['bytes'] === 0 && !isset($h[0]['body']));

$before = $pdo->query("SELECT next_seq, last_activity_at FROM pads WHERE id = $padId")->fetch();
mb_brief_save($pdo, $pad, $dylan, 'Back again.', 3);
$after = $pdo->query("SELECT next_seq, last_activity_at FROM pads WHERE id = $padId")->fetch();
check('saving a brief is not a post: no message, no retention change', $before == $after && (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 0);
mb_pad_post($pdo, mb_pad_for($pdo, 'dylan/launch', $dylanId), $dylan, 'A post.');
check('and it is not throttled by a recent post', mb_brief_save($pdo, $pad, $dylan, 'Straight after posting.', 4)['version'] === 5);

for ($i = 5; $i < 5 + MB_BRIEF_KEEP + 3; $i++) { mb_brief_save($pdo, $pad, $dylan, "Revision $i", $i); }
$h = mb_brief_history($pdo, $padId);
check('only the latest ' . MB_BRIEF_KEEP . ' versions are kept', count($h) === MB_BRIEF_KEEP && $h[0]['version'] === 5 + MB_BRIEF_KEEP + 3 && mb_brief($pdo, $padId, 1) === null);

$r  = mb_pad_read($pdo, mb_pad_for($pdo, 'dylan/launch', $dylanId), null, 50) + ['brief' => mb_brief($pdo, $padId)];
$md = mb_export_markdown($r);
check('the export carries the brief above the messages', (bool) preg_match('/## About this pad \(written by @dylan, \d{4}-\d\d-\d\d \d\d:\d\d UTC\)\n\nRevision \d+\n\n---\n\n## #1 from @dylan/', $md), substr($md, 0, 300));
check('a pad with no brief exports as before', !str_contains(mb_export_markdown(mb_pad_read($pdo, mb_pad_for($pdo, 'dylan/launch', $dylanId), null, 50)), '## About this pad'));

$pdo->prepare('DELETE FROM pads WHERE id = ?')->execute([$padId]);
check('deleting a pad deletes its brief versions', (int) $pdo->query('SELECT COUNT(*) FROM pad_briefs')->fetchColumn() === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
