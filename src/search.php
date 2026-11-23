<?php
/**
 * Mashpad — searching a pad's posts, and reading one in context.
 *
 * - A search is literal text: what is typed is looked for as typed, ignoring case and accents. "100%" finds
 *   "100%", not "1000". At least 3 characters, at most 200.
 * - It looks at posts only. Join notices and item-change events would only repeat what the items already say.
 * - Results come newest first, 20 by default and 50 at most, with a snippet around the match, the total number of
 *   matches, and the number to pass as before_id for the next batch. Optional filters: author, and from and to dates.
 * - Only the pad it is asked about, and only for a member: the callers check that, exactly as for reading. Nothing
 *   is reachable that reading didn't already reach; search just makes finding it cheap.
 * - mb_pad_around() returns a few posts either side of one, so a hit can be read in context. (Reading "since #N"
 *   returns everything after N, and there was no way to read a single post.)
 */
declare(strict_types=1);

require_once __DIR__ . '/pads.php';

const MB_SEARCH_MIN     = 3;
const MB_SEARCH_MAX     = 200;
const MB_SEARCH_DEFAULT = 20;
const MB_SEARCH_LIMIT   = 50;
const MB_SNIPPET        = 220;       // characters, around the first match
const MB_AROUND_MAX     = 10;        // posts either side of the one asked for

/** Tidy and check a search string. */
function mb_search_query(string $q): string
{
    $q = trim((string) preg_replace('/\s+/u', ' ', $q));
    $n = mb_strlen($q);
    if ($n < MB_SEARCH_MIN) {
        throw new MbAccountError('Search for at least ' . MB_SEARCH_MIN . ' characters.');
    }
    if ($n > MB_SEARCH_MAX) {
        throw new MbAccountError('A search is up to ' . MB_SEARCH_MAX . ' characters. Yours is ' . $n . '.');
    }
    return $q;
}

/** A LIKE pattern that finds $q as written: % and _ are plain characters. Use with ESCAPE '|'. */
function mb_like_contains(string $q): string
{
    return '%' . strtr($q, ['|' => '||', '%' => '|%', '_' => '|_']) . '%';
}

function mb_search_date(?string $d, string $what): ?string
{
    if ($d === null || trim($d) === '') {
        return null;
    }
    $d = trim($d);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        throw new MbAccountError("\"$what\" should be a date like 2026-11-22.");
    }
    return $d;
}

/** A short piece of the post around the first match, whitespace collapsed, with … where it was cut. */
function mb_search_snippet(string $body, string $q): string
{
    $flat = trim((string) preg_replace('/\s+/u', ' ', $body));
    $pos  = mb_stripos($flat, $q);            // may miss where the database matched an accent-insensitively: then start at the top
    $from = $pos === false ? 0 : max(0, $pos - 70);
    $cut  = mb_substr($flat, $from, MB_SNIPPET);
    return ($from > 0 ? '…' : '') . $cut . ($from + MB_SNIPPET < mb_strlen($flat) ? '…' : '');
}

/**
 * Posts on $pad that contain $query. $o may hold: author (a handle), before_id, from, to (dates, UTC, inclusive), limit.
 * Returns ['query', 'total', 'results' => [['id','author','author_kind','ts','reply_to','snippet'], …], 'next_before_id' => int|null].
 */
function mb_search_posts(PDO $pdo, array $pad, string $query, array $o = []): array
{
    $q     = mb_search_query($query);
    $limit = max(1, min(MB_SEARCH_LIMIT, (int) ($o['limit'] ?? MB_SEARCH_DEFAULT)));
    $from  = mb_search_date($o['from'] ?? null, 'from');
    $to    = mb_search_date($o['to'] ?? null, 'to');
    $where = "m.pad_id = ? AND m.kind = 'post' AND m.body LIKE ? ESCAPE '|'";
    $args  = [$pad['id'], mb_like_contains($q)];
    if (isset($o['author']) && trim((string) $o['author']) !== '') {
        $where .= ' AND p.handle = ?';
        $args[] = strtolower(ltrim(trim((string) $o['author']), '@'));
    }
    if ($from !== null) {
        $where .= ' AND m.created_at >= ?';
        $args[] = $from . ' 00:00:00';
    }
    if ($to !== null) {
        $where .= ' AND m.created_at < ?';
        $args[] = gmdate('Y-m-d', strtotime($to . ' UTC') + 86400) . ' 00:00:00';
    }
    $join  = 'FROM messages m JOIN principals p ON p.id = m.author_id LEFT JOIN messages pm ON pm.id = m.parent_id';
    $count = $pdo->prepare("SELECT COUNT(*) $join WHERE $where");
    $count->execute($args);
    $total = (int) $count->fetchColumn();
    $page  = $where;
    $pargs = $args;
    if (isset($o['before_id']) && (int) $o['before_id'] > 0) {
        $page  .= ' AND m.seq < ?';
        $pargs[] = (int) $o['before_id'];
    }
    $st = $pdo->prepare("SELECT m.seq AS id, p.handle AS author, p.kind AS author_kind, m.body, UNIX_TIMESTAMP(m.created_at) AS ts, pm.seq AS reply_to
                           $join WHERE $page ORDER BY m.seq DESC LIMIT " . ($limit + 1));
    $st->execute($pargs);
    $rows = $st->fetchAll();
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $results = array_map(fn ($r) => ['id' => (int) $r['id'], 'author' => (string) $r['author'], 'author_kind' => (string) $r['author_kind'], 'ts' => (int) $r['ts'],
                                     'reply_to' => $r['reply_to'] === null ? null : (int) $r['reply_to'], 'snippet' => mb_search_snippet((string) $r['body'], $q)], $rows);
    return ['query' => $q, 'total' => $total, 'results' => $results, 'next_before_id' => $more && $results ? end($results)['id'] : null];
}

/** Post $id with up to $context posts either side of it (events included: they are part of the thread). */
function mb_pad_around(PDO $pdo, array $pad, int $id, int $context = 3): array
{
    $context = max(0, min(MB_AROUND_MAX, $context));
    $has = $pdo->prepare('SELECT 1 FROM messages WHERE pad_id = ? AND seq = ?');
    $has->execute([$pad['id'], $id]);
    if (!$has->fetchColumn()) {
        throw new MbAccountError('There is no post #' . $id . ' on this pad. It may be newer than the latest, or the pad may have been trimmed.');
    }
    $q = $pdo->prepare('SELECT m.seq AS id, p.handle AS author, m.body, UNIX_TIMESTAMP(m.created_at) AS ts, p.kind AS author_kind, m.kind, pm.seq AS reply_to
                          FROM messages m JOIN principals p ON p.id = m.author_id LEFT JOIN messages pm ON pm.id = m.parent_id
                         WHERE m.pad_id = ? AND m.seq BETWEEN ? AND ? ORDER BY m.seq');
    $q->execute([$pad['id'], max(1, $id - $context), $id + $context]);
    $msgs = $q->fetchAll();
    foreach ($msgs as &$m) {
        $m['id'] = (int) $m['id'];
        $m['ts'] = (int) $m['ts'];
        $m['reply_to'] = $m['reply_to'] === null ? null : (int) $m['reply_to'];
    }
    return $msgs;
}
