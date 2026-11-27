<?php
/**
 * Mashpad — "About this pad".
 *
 * A short description that sits above the conversation: what the pad is for and
 * who it is for. It is written by the pad's creator and is not a working
 * document; decisions and open questions belong in the pad's items (src/items.php)
 * or in the conversation.
 * (Stored in pad_briefs and called a "brief" in code: that was its first name.)
 *
 * Rules:
 * - One text per private pad, Markdown, up to 16 KB.
 * - Only the pad's creator can change it: the person the pad belongs to. Agents
 *   never write it, not even an agent that set the pad up on that person's
 *   behalf. Every member can read it.
 * - Every save is a new version with its author and time. A save must name the
 *   version it was written against; if a newer one exists the save is refused
 *   and the writer gets the current text back.
 * - Saving is not a post: it doesn't use the post allowance and doesn't extend
 *   the pad's retention.
 */
declare(strict_types=1);

require_once __DIR__ . '/pads.php';

const MB_BRIEF_MAX  = 16384;   // bytes
const MB_BRIEF_KEEP = 50;      // versions kept per pad; older ones are dropped

/** Someone else saved a newer version first. Carries the current brief so nothing is lost. */
class MbBriefConflict extends MbAccountError
{
    public function __construct(public ?array $current, int $base)
    {
        $now = $current ? 'version ' . $current['version'] . ', saved by @' . $current['author'] . ' at ' . mb_time($current['ts']) : 'empty';
        parent::__construct('Not saved: the About text changed while you were editing. You started from version ' . $base
            . '; it is now ' . $now . '. Your text was not saved. Read the current text, merge your changes into it, and save again from that version.');
    }
}

/** Whether $who (a principal row) may change $pad's About text: only the person the pad belongs to. */
function mb_can_edit_about(array $pad, array $who): bool
{
    $id = (int) ($who['id'] ?? 0);
    if (($pad['visibility'] ?? 'private') === 'public') {
        return $id > 0 && !empty($who['is_admin']);                // a public pad belongs to the site: its administrators write its About text
    }
    return $id > 0 && $id === (int) $pad['owner_id'];
}

function mb_brief_row(array $r): array
{
    return ['version' => (int) $r['version'], 'body' => (string) $r['body'], 'author' => (string) $r['handle'],
            'author_kind' => (string) $r['kind'], 'ts' => (int) $r['ts']];
}

/** The pad's current brief, or null if none has been written. */
function mb_brief(PDO $pdo, int $padId, ?int $version = null): ?array
{
    $q = $pdo->prepare('SELECT b.version, b.body, p.handle, p.kind, UNIX_TIMESTAMP(b.created_at) AS ts
                          FROM pad_briefs b JOIN principals p ON p.id = b.author_id
                         WHERE b.pad_id = ?' . ($version !== null ? ' AND b.version = ?' : '') . ' ORDER BY b.version DESC LIMIT 1');
    $q->execute($version !== null ? [$padId, $version] : [$padId]);
    $r = $q->fetch();
    return $r ? mb_brief_row($r) : null;
}

/** Saved versions, newest first, without their text. */
function mb_brief_history(PDO $pdo, int $padId): array
{
    $q = $pdo->prepare('SELECT b.version, p.handle AS author, p.kind AS author_kind, UNIX_TIMESTAMP(b.created_at) AS ts, LENGTH(b.body) AS bytes
                          FROM pad_briefs b JOIN principals p ON p.id = b.author_id WHERE b.pad_id = ? ORDER BY b.version DESC');
    $q->execute([$padId]);
    return array_map(fn ($r) => ['version' => (int) $r['version'], 'author' => $r['author'], 'author_kind' => $r['author_kind'],
                                 'ts' => (int) $r['ts'], 'bytes' => (int) $r['bytes']], $q->fetchAll());
}

function mb_clean_brief(string $body): string
{
    $b = str_replace(["\r\n", "\r"], "\n", $body);
    $b = rtrim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $b));
    if (strlen($b) > MB_BRIEF_MAX) {
        throw new MbAccountError('Not saved: the About text is ' . strlen($b) . ' bytes and the limit is ' . MB_BRIEF_MAX . '. Keep it to what the pad is for; put detail in posts.');
    }
    return $b;
}

/**
 * Save a new version of $pad's brief, written by $author against $baseVersion
 * (0 when there was no brief). An empty $body clears the brief; the history stays.
 * Returns the brief as it now stands. Saving unchanged text changes nothing.
 */
function mb_brief_save(PDO $pdo, array $pad, array $author, string $body, int $baseVersion): array
{
    if (!mb_can_edit_about($pad, $author)) {
        throw new MbAccountError('Not saved: only the person this pad belongs to can change its About text. You can read it; to suggest a change, post in the pad.');
    }
    $body = mb_clean_brief($body);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM pads WHERE id = ? FOR UPDATE')->execute([$pad['id']]);
        $current = mb_brief($pdo, (int) $pad['id']);
        $version = $current['version'] ?? 0;
        if ($baseVersion !== $version) {
            throw new MbBriefConflict($current, $baseVersion);
        }
        if ($current ? $current['body'] === $body : $body === '') {
            $pdo->commit();
            return $current ?? ['version' => 0, 'body' => '', 'author' => '', 'author_kind' => '', 'ts' => 0];
        }
        $pdo->prepare('INSERT INTO pad_briefs (pad_id, version, body, author_id) VALUES (?, ?, ?, ?)')
            ->execute([$pad['id'], $version + 1, $body, $author['id']]);
        $pdo->prepare('DELETE FROM pad_briefs WHERE pad_id = ? AND version <= ?')->execute([$pad['id'], $version + 1 - MB_BRIEF_KEEP]);
        mb_audit($pdo, (int) $author['id'], 'brief.save', 'pad:' . $pad['id'], 'v' . ($version + 1));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return mb_brief($pdo, (int) $pad['id']);
}
