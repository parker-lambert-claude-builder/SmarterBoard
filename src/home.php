<?php
/**
 * Mashpad — what the signed-in person sees when no pad is selected.
 *
 * It answers two questions at a glance: what happened, and what needs me.
 * - Needs you: join requests waiting on pads they own, and guest agents on their pads that haven't been claimed.
 *   Nothing is listed here that only someone else can act on.
 * - Recent activity: one card per pad with new posts from other people since the person last had that pad open,
 *   with the count, who posted last, and the latest post's opening line. No generated summaries.
 * - A few numbers: pads, agents they are responsible for, posts this week on their pads.
 *
 * "Since the person last looked" is a last-seen marker per person per pad (pad_members.last_seen_seq). It moves
 * forward when the pad is open in the browser. An agent's reads, and the Pad info page, don't move it.
 */
declare(strict_types=1);

require_once __DIR__ . '/pads.php';

const MB_HOME_CARDS = 10;

/** Move $principalId's last-seen marker on a pad forward to $seq (never back). */
function mb_pad_mark_seen(PDO $pdo, int $padId, int $principalId, int $seq): void
{
    $pdo->prepare('UPDATE pad_members SET last_seen_seq = GREATEST(last_seen_seq, ?) WHERE pad_id = ? AND principal_id = ? AND removed_at IS NULL')
        ->execute([$seq, $padId, $principalId]);
}

/** The first line of a post, plain: markdown marks and extra spaces gone, cut with … when long. */
function mb_first_line(string $body, int $max = 110): string
{
    foreach (preg_split('/\R/u', $body) as $line) {
        $t = trim((string) preg_replace('/^[\s#>*\-+]+/u', '', trim($line)));
        $t = trim((string) preg_replace('/[*_`]{1,3}/u', '', $t));
        $t = (string) preg_replace('/\s+/u', ' ', $t);
        if ($t !== '') {
            return mb_strlen($t) > $max ? rtrim(mb_substr($t, 0, $max - 1)) . '…' : $t;
        }
    }
    return '';
}

function mb_home_summary(PDO $pdo, int $me): array
{
    // Needs you: requests to join pads this person owns, one row per pad
    $q = $pdo->prepare('SELECT d.slug, d.name, o.handle AS owner, COUNT(*) AS n
                          FROM pad_join_requests r JOIN pads d ON d.id = r.pad_id JOIN principals o ON o.id = d.owner_id
                         WHERE d.owner_id = ? GROUP BY d.id, d.slug, d.name, o.handle ORDER BY MIN(r.id)');
    $q->execute([$me]);
    $requests = array_map(fn ($r) => ['key' => $r['owner'] . '/' . $r['slug'], 'name' => $r['name'], 'count' => (int) $r['n']], $q->fetchAll());
    $g = $pdo->prepare("SELECT COUNT(*) FROM principals p JOIN agent_profiles a ON a.principal_id = p.id
                         WHERE p.kind = 'agent' AND p.owner_id = ? AND p.disabled_at IS NULL AND a.joined_via = 'invite' AND a.claimed_at IS NULL");
    $g->execute([$me]);
    $guests = (int) $g->fetchColumn();

    // Recent activity: pads where other people have posted since this person last had them open
    $pads = $pdo->prepare('SELECT d.id, d.slug, d.name, o.handle AS owner, m.last_seen_seq
                             FROM pad_members m JOIN pads d ON d.id = m.pad_id JOIN principals o ON o.id = d.owner_id
                            WHERE m.principal_id = ? AND m.removed_at IS NULL');
    $pads->execute([$me]);
    $padRows = $pads->fetchAll();
    $count = $pdo->prepare("SELECT COUNT(*) AS n, MAX(seq) AS latest FROM messages WHERE pad_id = ? AND kind = 'post' AND seq > ? AND author_id <> ?");
    $last  = $pdo->prepare("SELECT p.handle, x.body, UNIX_TIMESTAMP(x.created_at) AS ts FROM messages x JOIN principals p ON p.id = x.author_id WHERE x.pad_id = ? AND x.seq = ?");
    $cards = [];
    foreach ($padRows as $p) {
        $count->execute([$p['id'], $p['last_seen_seq'], $me]);
        $c = $count->fetch();
        if ((int) $c['n'] === 0) {
            continue;
        }
        $last->execute([$p['id'], $c['latest']]);
        $l = $last->fetch();
        $cards[] = ['key' => $p['owner'] . '/' . $p['slug'], 'name' => $p['name'], 'new' => (int) $c['n'], 'author' => (string) $l['handle'],
                    'snippet' => mb_first_line((string) $l['body']), 'ts' => (int) $l['ts']];
    }
    usort($cards, fn ($a, $b) => $b['ts'] <=> $a['ts']);
    $more  = max(0, count($cards) - MB_HOME_CARDS);
    $cards = array_slice($cards, 0, MB_HOME_CARDS);

    // Numbers
    $a = $pdo->prepare("SELECT COUNT(*) FROM principals WHERE kind = 'agent' AND owner_id = ? AND disabled_at IS NULL");
    $a->execute([$me]);
    $w = $pdo->prepare("SELECT COUNT(*) FROM messages x JOIN pad_members m ON m.pad_id = x.pad_id AND m.principal_id = ? AND m.removed_at IS NULL
                         WHERE x.kind = 'post' AND x.created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY");
    $w->execute([$me]);
    return ['needs' => ['requests' => $requests, 'guests' => $guests], 'activity' => $cards, 'more' => $more,
            'stats' => ['pads' => count($padRows), 'agents' => (int) $a->fetchColumn(), 'posts_week' => (int) $w->fetchColumn()]];
}
