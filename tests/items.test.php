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
require "$root/src/items.php";

$dylanId = mb_create_human($pdo, 'Dylan', 'dylan', 'd@example.com', 'correct horse battery', true);
$samId   = mb_create_human($pdo, 'Sam', 'sam', 's@example.com', 'correct horse battery');
$dylan   = mb_principal($pdo, $dylanId);
$pad     = mb_create_pad($pdo, $dylan, 'Launch');
$padId   = (int) $pad['id'];
[$museId] = mb_create_agent($pdo, $dylanId, ['handle' => 'muse']);
[$buildId] = mb_create_agent($pdo, $dylanId, ['handle' => 'builder']);
mb_add_member($pdo, $pad, $museId, 'added');
mb_add_member($pdo, $pad, $buildId, 'added');
$muse = mb_principal($pdo, $museId); $builder = mb_principal($pdo, $buildId); $sam = mb_principal($pdo, $samId);
$events = fn () => $pdo->query("SELECT body FROM messages WHERE pad_id = $padId AND kind = 'event' ORDER BY seq")->fetchAll(PDO::FETCH_COLUMN);
$lastActive = fn () => $pdo->query("SELECT last_activity_at FROM pads WHERE id = $padId")->fetchColumn();

// Types and values
check('a type is tidied: lowercase, single spaces', mb_item_type('  Open   QUESTION ') === 'open question' && mb_item_type('Décision') === 'décision' && mb_item_type('go-live_date') === 'go-live_date');
foreach (['' => 'empty', '   ' => 'blank', '<b>' => 'markup', '-x' => 'starting with a hyphen', 'a/b' => 'a slash', str_repeat('a', 41) => 'over forty characters', "a\nb" => 'a line break kept inside'] as $bad => $why) {
    $e = err(fn () => mb_item_type($bad));
    check("a type that is $why is " . ($why === 'a line break kept inside' ? 'folded to one space' : 'refused'), $why === 'a line break kept inside' ? mb_item_type($bad) === 'a b' : str_contains($e, 'MbAccountError'), $e);
}
check('an empty or blank value is refused, and a value over 2,000 characters says how long it is', str_contains(err(fn () => mb_item_value("  \n ")), 'needs a value') && str_contains(err(fn () => mb_item_value(str_repeat('x', 2001))), 'Yours is 2001') && mb_item_value(str_repeat('é', 2000)) !== '');

// Adding
$a = mb_item_add($pdo, $pad, $muse, 'Decision', 'Free plan first, Plus later.');
check('an item gets the next number on the pad, its tidied type, and who added it and when', $a['n'] === 1 && $a['type'] === 'decision' && $a['value'] === 'Free plan first, Plus later.' && $a['version'] === 1
      && $a['added_by'] === 'muse' && $a['updated_by'] === 'muse' && $a['updated_ts'] >= time() - 5);
$b = mb_item_add($pdo, $pad, $builder, 'question', 'Which date for the launch?');
$c = mb_item_add($pdo, $pad, $dylan, 'decision', 'Launch on a Tuesday.');
check('numbers run 1, 2, 3 within the pad', [$a['n'], $b['n'], $c['n']] === [1, 2, 3]);
check('the pad\'s conversation records each addition, naming the type and number', $events() === ['@muse added a decision (#1): Free plan first, Plus later.', '@builder added a question (#2): Which date for the launch?', '@dylan added a decision (#3): Launch on a Tuesday.']);
check('"an" before a vowel', mb_item_a('open question') === 'an open question' && mb_item_a('decision') === 'a decision');
$before = $lastActive();
mb_item_add($pdo, $pad, $muse, 'risk', 'The mail provider may rewrite links.');
check('items and their events leave last_activity_at alone, so they don\'t extend a pad\'s life', $lastActive() === $before);
check('a non-member can\'t add, update or remove', str_contains(err(fn () => mb_item_add($pdo, $pad, $sam, 'decision', 'x')), 'Only members') && str_contains(err(fn () => mb_item_update($pdo, $pad, $sam, 1, null, 'x', 1)), 'Only members')
      && str_contains(err(fn () => mb_item_remove($pdo, $pad, $sam, 1)), 'Only members') && count(mb_items($pdo, $padId)) === 4);

// Listing
$all = mb_items($pdo, $padId);
check('the list is in item order, and a type narrows it', array_column($all, 'n') === [1, 2, 3, 4] && array_column(mb_items($pdo, $padId, 'decision'), 'n') === [1, 3] && mb_items($pdo, $padId, 'nothing') === []);
check('types in use come with counts, most used first, so agents can reuse a word', mb_item_types($pdo, $padId) === ['decision' => 2, 'question' => 1, 'risk' => 1]);
$g = mb_items_grouped($all);
check('items group by type in the order the types first appeared', array_column($g, 'type') === ['decision', 'question', 'risk'] && count($g[0]['items']) === 2);

// Updating
$stamp = mb_items_stamp($pdo, $padId);
$u = mb_item_update($pdo, $pad, $builder, 2, null, 'Launch date: Tuesday 9 December.', 1);
check('an update changes the value, bumps the version, and names the person who made it, keeping who added it', $u['value'] === 'Launch date: Tuesday 9 December.' && $u['version'] === 2 && $u['updated_by'] === 'builder' && $u['added_by'] === 'builder' && $u['type'] === 'question');
$u2 = mb_item_update($pdo, $pad, $muse, 2, 'decision', 'Launch date: Tuesday 9 December.', 2);
check('an update can change the type too: a question answered becomes a decision', $u2['type'] === 'decision' && $u2['version'] === 3 && $u2['updated_by'] === 'muse' && $u2['added_by'] === 'builder');
$ev = $events();
check('the conversation records an update with the new and the old text', str_contains($ev[4], '@builder updated a question (#2): Launch date: Tuesday 9 December. (was: Which date for the launch?)'), $ev[4]);
check('and a change of type says so', str_contains($ev[5], '@muse updated item #2 (now a decision)'), $ev[5]);
$n = count($events());
$same = mb_item_update($pdo, $pad, $muse, 2, null, 'Launch date: Tuesday 9 December.', 3);
check('saving the same text again changes nothing: no new version, no event', $same['version'] === 3 && count($events()) === $n);
$stale = err(fn () => mb_item_update($pdo, $pad, $builder, 2, null, 'My version', 1));
check('an update from an old version is refused, and says what the item is now', str_contains($stale, 'MbItemConflict') && str_contains($stale, 'You had version 1; it is now version 3, updated by @muse') && mb_item($pdo, $padId, 2)['value'] === 'Launch date: Tuesday 9 December.', $stale);
try { mb_item_update($pdo, $pad, $builder, 2, null, 'My version', 1); } catch (MbItemConflict $x) { $cur = $x->current; }
check('the conflict carries the current item so nothing is lost', ($cur['version'] ?? 0) === 3 && ($cur['value'] ?? '') === 'Launch date: Tuesday 9 December.');
check('updating an item that does not exist says it was removed', str_contains(err(fn () => mb_item_update($pdo, $pad, $muse, 99, null, 'x', 1)), 'no longer exists'));
check('the stamp changes when items change', mb_items_stamp($pdo, $padId) !== $stamp);

// Removing
check('a member can\'t remove an item somebody else added, and is told who can', str_contains(err(fn () => mb_item_remove($pdo, $pad, $builder, 1)), 'Only @muse, who added item 1, or the pad\'s owner can remove it'));
$gone = mb_item_remove($pdo, $pad, $muse, 1);
check('whoever added it can remove it', $gone['n'] === 1 && mb_item($pdo, $padId, 1) === null && str_contains($events()[count($events()) - 1], '@muse removed a decision (#1): Free plan first, Plus later.'));
$ownerGone = mb_item_remove($pdo, $pad, $dylan, 2);
check('the pad\'s owner can remove anyone\'s', $ownerGone['n'] === 2 && mb_item($pdo, $padId, 2) === null);
$next = mb_item_add($pdo, $pad, $muse, 'link', 'https://example.org/spec');
check('a number is never reused after a removal', $next['n'] === 5);
check('removing one that is already gone says so', str_contains(err(fn () => mb_item_remove($pdo, $pad, $dylan, 1)), 'no item 1'));

// Limits
$pdo->exec("DELETE FROM audit_log WHERE action LIKE 'item.%'");
for ($i = 0; $i < MB_ITEM_WRITES; $i++) { mb_audit($pdo, $museId, 'item.add', 'pad:' . $padId, 'x'); }
check('thirty changes an hour is the limit for one person', str_contains(err(fn () => mb_item_add($pdo, $pad, $muse, 'decision', 'one too many')), 'Too many item changes: 30 an hour') && mb_item_add($pdo, $pad, $builder, 'decision', 'fine')['n'] > 0);
$same = mb_item($pdo, $padId, $next['n']);
check('at the limit a save that changes nothing is not refused, while a real change is', mb_item_update($pdo, $pad, $muse, $same['n'], null, $same['value'], $same['version'])['version'] === $same['version']
      && str_contains(err(fn () => mb_item_update($pdo, $pad, $muse, $same['n'], null, 'a real change', $same['version'])), 'Too many item changes'));
check('the type error names underscores as allowed', str_contains(err(fn () => mb_item_type('a/b')), 'hyphens and underscores'));
$pdo->exec("DELETE FROM audit_log WHERE action LIKE 'item.%'");
$count = (int) $pdo->query("SELECT COUNT(*) FROM pad_items WHERE pad_id = $padId")->fetchColumn();
$ins = $pdo->prepare('INSERT INTO pad_items (pad_id, n, type, value, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?)');
for ($i = 0; $i < MB_ITEMS_PER_PAD - $count; $i++) { $ins->execute([$padId, 1000 + $i, 'filler', 'x', $dylanId, $dylanId]); }
check('a pad holds at most 200 items', str_contains(err(fn () => mb_item_add($pdo, $pad, $muse, 'decision', 'the 201st')), 'already has 200 items'));
$pdo->prepare('DELETE FROM pad_items WHERE pad_id = ? AND type = ?')->execute([$padId, 'filler']);

// Pads and items
$pad2 = mb_create_pad($pdo, $dylan, 'Other');
$o = mb_item_add($pdo, $pad2, $dylan, 'decision', 'Separate numbering per pad.');
check('each pad numbers its own items from 1, and sees only its own', $o['n'] === 1 && count(mb_items($pdo, (int) $pad2['id'])) === 1 && array_column(mb_items($pdo, $padId), 'value') !== array_column(mb_items($pdo, (int) $pad2['id']), 'value'));
$pdo->prepare('DELETE FROM pads WHERE id = ?')->execute([$pad2['id']]);
check('deleting a pad deletes its items', (int) $pdo->query('SELECT COUNT(*) FROM pad_items WHERE pad_id = ' . (int) $pad2['id'])->fetchColumn() === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
