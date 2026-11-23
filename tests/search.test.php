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
require "$root/src/search.php";

$dylanId = mb_create_human($pdo, 'Dylan', 'dylan', 'd@example.com', 'correct horse battery', true);
$dylan   = mb_principal($pdo, $dylanId);
[$museId] = mb_create_agent($pdo, $dylanId, ['handle' => 'muse']);
$pad   = mb_create_pad($pdo, $dylan, 'Launch');
$other = mb_create_pad($pdo, $dylan, 'Other');
mb_add_member($pdo, $pad, $museId, 'added');
/** A message written straight into the table, so a test can write many at once (the post allowance doesn't apply). */
function put(PDO $pdo, array $pad, int $by, string $body, string $kind = 'post', ?string $when = null, ?int $replyToSeq = null): int {
    $pdo->beginTransaction();
    $seq = (int) $pdo->query('SELECT next_seq FROM pads WHERE id = ' . (int) $pad['id'] . ' FOR UPDATE')->fetchColumn();
    $parent = $replyToSeq === null ? null : (int) $pdo->query('SELECT id FROM messages WHERE pad_id = ' . (int) $pad['id'] . ' AND seq = ' . $replyToSeq)->fetchColumn();
    $pdo->prepare('INSERT INTO messages (pad_id, seq, author_id, body, kind, parent_id' . ($when ? ', created_at' : '') . ') VALUES (?, ?, ?, ?, ?, ?' . ($when ? ', ?' : '') . ')')
        ->execute(array_merge([$pad['id'], $seq, $by, $body, $kind, $parent], $when ? [$when] : []));
    $pdo->prepare('UPDATE pads SET next_seq = next_seq + 1 WHERE id = ?')->execute([$pad['id']]);
    $pdo->commit();
    return $seq;
}
$ids = fn (array $r) => array_column($r['results'], 'id');

// The query
check('a query is tidied: whitespace collapsed, trimmed', mb_search_query("  launch \n  date ") === 'launch date');
check('at least three characters and at most two hundred, and it says how many you gave', str_contains(err(fn () => mb_search_query('ab')), 'at least 3') && str_contains(err(fn () => mb_search_query('   ')), 'at least 3')
      && str_contains(err(fn () => mb_search_query(str_repeat('x', 201))), 'Yours is 201') && mb_search_query('abc') === 'abc' && mb_strlen(mb_search_query(str_repeat('é', 200))) === 200);
check('% _ and | are plain characters in a pattern', mb_like_contains('100%') === '%100|%%' && mb_like_contains('a_b') === '%a|_b%' && mb_like_contains('a|b') === '%a||b%');
check('a date is checked, and blank means none', mb_search_date('2026-11-22', 'from') === '2026-11-22' && mb_search_date('', 'from') === null && mb_search_date(null, 'to') === null
      && str_contains(err(fn () => mb_search_date('2026-13-45', 'from')), '"from" should be a date') && str_contains(err(fn () => mb_search_date('yesterday', 'to')), '"to" should be a date'));

// Snippets
check('a short post is its whole text, with no cut marks, and its whitespace collapsed', mb_search_snippet("Launch\n  on a   Tuesday.", 'tuesday') === 'Launch on a Tuesday.');
$long = str_repeat('before words ', 40) . 'the NEEDLE is here ' . str_repeat('after words ', 40);
$sn = mb_search_snippet($long, 'needle');
check('a long one is cut around the first match, with … where it was cut, and stays short', str_starts_with($sn, '…') && str_ends_with($sn, '…') && str_contains($sn, 'NEEDLE') && mb_strlen($sn) <= MB_SNIPPET + 2, $sn);
check('a match at the very start has no leading cut mark', !str_starts_with(mb_search_snippet('needle ' . str_repeat('x ', 300), 'needle'), '…') && str_ends_with(mb_search_snippet('needle ' . str_repeat('x ', 300), 'needle'), '…'));
check('where only the database matched (an accent), the snippet starts at the top', str_starts_with(mb_search_snippet(str_repeat('word ', 80) . 'Café', 'cafe'), 'word word'));

// Searching
$a = put($pdo, $pad, $dylanId, 'We should pick the launch date soon.');
$b = put($pdo, $pad, $museId, 'Launch date: Tuesday 9 December.', 'post', null, $a);
$e = put($pdo, $pad, $museId, '@muse updated item #2: launch date moved.', 'event');
$c = put($pdo, $pad, $dylanId, 'Unrelated chatter about lunch.');
$d = put($pdo, $pad, $dylanId, 'Is the LAUNCH DATE still Tuesday?');
$r = mb_search_posts($pdo, $pad, 'launch date');
check('it finds the posts that contain the text, newest first, ignoring case', $ids($r) === [$d, $b, $a] && $r['total'] === 3 && $r['next_before_id'] === null && $r['query'] === 'launch date', json_encode($ids($r)));
check('each hit has its number, author, kind, time, the post it replies to, and a snippet', $r['results'][1] === ['id' => $b, 'author' => 'muse', 'author_kind' => 'agent', 'ts' => $r['results'][1]['ts'], 'reply_to' => $a, 'snippet' => 'Launch date: Tuesday 9 December.'] && $r['results'][1]['ts'] >= time() - 10 && $r['results'][0]['reply_to'] === null);
check('events are not posts: the join notice and item change that mention it are not found', !in_array($e, $ids($r), true));
put($pdo, $pad, $dylanId, 'Meet at the Café on Friday.'); put($pdo, $pad, $dylanId, 'We need 100% of the budget, not 1000 items.'); put($pdo, $pad, $dylanId, 'Use snake_case; never snakeXcase.');
check('accents are ignored: "cafe" finds "Café"', count($ids(mb_search_posts($pdo, $pad, 'cafe'))) === 1 && count($ids(mb_search_posts($pdo, $pad, 'CAFÉ'))) === 1);
check('% is a plain character: "100%" finds the post that says it, not "1000"', count($ids(mb_search_posts($pdo, $pad, '100%'))) === 1 && mb_search_posts($pdo, $pad, '100%')['results'][0]['snippet'] === 'We need 100% of the budget, not 1000 items.' && mb_search_posts($pdo, $pad, '100%')['total'] === 1);
check('_ is a plain character: "e_c" finds snake_case and not snakeXcase', count($ids(mb_search_posts($pdo, $pad, 'e_c'))) === 1 && mb_search_posts($pdo, $pad, 'case; never snakeX')['total'] === 1);
check('a search that matches nothing says so, with a zero total', mb_search_posts($pdo, $pad, 'zebra') === ['query' => 'zebra', 'total' => 0, 'results' => [], 'next_before_id' => null]);
put($pdo, $other, $dylanId, 'The launch date is secret on this other pad.');
check('another pad\'s posts are never found, though their numbers may match', !str_contains(json_encode(mb_search_posts($pdo, $pad, 'launch date')), 'secret') && mb_search_posts($pdo, $pad, 'launch date')['total'] === 3
      && mb_search_posts($pdo, $other, 'launch date')['total'] === 1 && str_contains(json_encode(mb_search_posts($pdo, $other, 'launch date')), 'secret'));

// Paging
$pg = mb_create_pad($pdo, $dylan, 'Paging');
$made = []; for ($i = 1; $i <= 25; $i++) { $made[] = put($pdo, $pg, $dylanId, "Item $i mentions the quokka."); }
$p1 = mb_search_posts($pdo, $pg, 'quokka', ['limit' => 10]);
check('a page of ten, newest first, with the total of all and the number for the next batch', count($p1['results']) === 10 && $p1['total'] === 25 && $ids($p1)[0] === $made[24] && $p1['next_before_id'] === $made[15], json_encode([count($p1['results']), $p1['total'], $p1['next_before_id']]));
$p2 = mb_search_posts($pdo, $pg, 'quokka', ['limit' => 10, 'before_id' => $p1['next_before_id']]);
$p3 = mb_search_posts($pdo, $pg, 'quokka', ['limit' => 10, 'before_id' => $p2['next_before_id']]);
check('the next batches continue without a gap or a repeat, the total holds, and the last has no next', $ids($p2)[0] === $made[14] && $p2['total'] === 25 && count($p3['results']) === 5 && $p3['next_before_id'] === null && count(array_unique(array_merge($ids($p1), $ids($p2), $ids($p3)))) === 25);
check('the default is 20, and the most a search returns is 50, whatever is asked', count(mb_search_posts($pdo, $pg, 'quokka')['results']) === 20 && count(mb_search_posts($pdo, $pg, 'quokka', ['limit' => 500])['results']) === 25 && mb_search_posts($pdo, $pg, 'quokka', ['limit' => 0])['results'] !== [] && MB_SEARCH_LIMIT === 50);
for ($i = 0; $i < 40; $i++) { put($pdo, $pg, $dylanId, "More $i about the quokka."); }
check('with sixty-five matches a request for 500 gets 50', count(mb_search_posts($pdo, $pg, 'quokka', ['limit' => 500])['results']) === 50 && mb_search_posts($pdo, $pg, 'quokka')['total'] === 65);

// Filters
$fp = mb_create_pad($pdo, $dylan, 'Filters'); mb_add_member($pdo, $fp, $museId, 'added');
$f1 = put($pdo, $fp, $dylanId, 'okapi from Dylan, old', 'post', '2026-11-01 10:00:00');
$f2 = put($pdo, $fp, $museId, 'okapi from Muse, middle', 'post', '2026-11-10 23:59:59');
$f3 = put($pdo, $fp, $museId, 'okapi from Muse, late', 'post', '2026-11-11 00:00:00');
check('by author, with or without @, and an author who is not there finds nothing', $ids(mb_search_posts($pdo, $fp, 'okapi', ['author' => 'muse'])) === [$f3, $f2] && $ids(mb_search_posts($pdo, $fp, 'okapi', ['author' => '@Dylan'])) === [$f1] && mb_search_posts($pdo, $fp, 'okapi', ['author' => 'nobody'])['total'] === 0);
check('from a date on, and to a date inclusive of that whole day', $ids(mb_search_posts($pdo, $fp, 'okapi', ['from' => '2026-11-10'])) === [$f3, $f2] && $ids(mb_search_posts($pdo, $fp, 'okapi', ['to' => '2026-11-10'])) === [$f2, $f1] && $ids(mb_search_posts($pdo, $fp, 'okapi', ['from' => '2026-11-10', 'to' => '2026-11-10'])) === [$f2]);
check('the total follows the filters but not the page', mb_search_posts($pdo, $fp, 'okapi', ['author' => 'muse', 'before_id' => $f3])['total'] === 2 && $ids(mb_search_posts($pdo, $fp, 'okapi', ['author' => 'muse', 'before_id' => $f3])) === [$f2]);
check('a bad date or a short query is refused', str_contains(err(fn () => mb_search_posts($pdo, $fp, 'okapi', ['from' => 'soon'])), '"from"') && str_contains(err(fn () => mb_search_posts($pdo, $fp, 'ok')), 'at least 3'));

// A post in context
$cp = mb_create_pad($pdo, $dylan, 'Context'); $seqs = []; for ($i = 1; $i <= 12; $i++) { $seqs[] = put($pdo, $cp, $dylanId, "Post $i", $i === 6 ? 'event' : 'post'); }
$ar = fn (int $id, int $ctx = 3) => array_column(mb_pad_around($pdo, $cp, $id, $ctx), 'id');
check('a post with three either side by default, events included', $ar(7) === [4, 5, 6, 7, 8, 9, 10]);
check('context 0 is the post alone, and it carries the body', $ar(7, 0) === [7] && mb_pad_around($pdo, $cp, 7, 0)[0]['body'] === 'Post 7' && mb_pad_around($pdo, $cp, 7, 0)[0]['author'] === 'dylan');
check('near either end it just stops, and the most either side is ten', $ar(1) === [1, 2, 3, 4] && $ar(12) === [9, 10, 11, 12] && $ar(1, 99) === range(1, 11));
check('a post that is not there is refused, saying why', str_contains(err(fn () => mb_pad_around($pdo, $cp, 99)), 'There is no post #99') && str_contains(err(fn () => mb_pad_around($pdo, $cp, 0)), 'There is no post #0'));
check('it only reads the pad it is given: the other pad has one post, so post 12 is not there and post 1 is its own', str_contains(err(fn () => mb_pad_around($pdo, $other, 12)), 'There is no post #12') && array_column(mb_pad_around($pdo, $other, 1, 3), 'body') === ['The launch date is secret on this other pad.']);
check('a reply says which post it answers', ($rp = put($pdo, $cp, $museId, 'Answering post 7.', 'post', null, 7)) > 0 && array_column(mb_pad_around($pdo, $cp, $rp, 0), 'reply_to') === [7]);

// Items: contains
$padItem = mb_create_pad($pdo, $dylan, 'Things'); $dyl = mb_principal($pdo, $dylanId);
mb_item_add($pdo, $padItem, $dyl, 'decision', 'Launch on a Tuesday.'); mb_item_add($pdo, $padItem, $dyl, 'question', 'Which launch venue?'); mb_item_add($pdo, $padItem, $dyl, 'decision', 'Budget is 100% approved.'); mb_item_add($pdo, $padItem, $dyl, 'risk', 'Snake_case names clash.');
$items = fn (?string $type, ?string $has) => array_column(mb_items($pdo, (int) $padItem['id'], $type, $has), 'n');
check('items whose value contains the text, ignoring case', $items(null, 'LAUNCH') === [1, 2] && $items(null, 'tuesday') === [1] && $items(null, 'zebra') === [] && $items(null, null) === [1, 2, 3, 4] && $items(null, '') === [1, 2, 3, 4]);
check('and narrowed by type', $items('decision', 'launch') === [1] && $items('question', 'launch') === [2] && $items('risk', 'launch') === []);
check('% and _ are plain characters there too', $items(null, '100%') === [3] && $items(null, 'e_c') === [4] && $items(null, '0%') === [3]);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
