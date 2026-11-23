<?php
/**
 * Mashpad — pad items.
 *
 * A pad keeps a short list of items: things worth finding without reading the whole thread. Each has a type
 * chosen by whoever adds it (decision, question, requirement, risk, link: examples, not a fixed set), a value,
 * who last changed it, and when.
 *
 * Rules:
 * - Any member can add an item or change one. Removing is for whoever added it, or the pad's owner.
 * - Types are free text, tidied so "Decision" and "decision " are one type: lowercase, single spaces, up to 40
 *   characters of letters, numbers, spaces, hyphens and underscores.
 * - A value is up to 2,000 characters. A pad holds up to 200 items.
 * - A change must name the version of the item it was written against. If someone changed it since, the change
 *   is refused and the writer gets the current item back. Agents work at the same time as each other.
 * - Every change appears in the conversation as an event, with the old and new text for an update. There is no
 *   version history: the events are the trail.
 * - Changing an item is not a post. It doesn't use the post allowance and doesn't extend the pad's retention
 *   (the same as events and the About text). It is limited to 30 changes an hour per person.
 */
declare(strict_types=1);

require_once __DIR__ . '/pads.php';

const MB_ITEM_VALUE_MAX = 2000;     // characters
const MB_ITEM_TYPE_MAX  = 40;       // characters
const MB_ITEMS_PER_PAD  = 200;
const MB_ITEM_WRITES    = 30;       // changes per person per hour

/** Someone changed the item first. Carries the current item so nothing is lost. */
class MbItemConflict extends MbAccountError
{
    public function __construct(public ?array $current, int $base)
    {
        parent::__construct($current
            ? 'Not saved: item ' . $current['n'] . ' changed since you read it. You had version ' . $base . '; it is now version ' . $current['version']
              . ', updated by @' . $current['updated_by'] . ' at ' . mb_time($current['updated_ts']) . '. Read it again, merge your change into the current text, and save from that version.'
            : 'Not saved: that item no longer exists. It was removed since you read it.');
    }
}

/** Tidy a type: lowercase, single spaces. Throws if it is empty, too long, or has other characters. */
function mb_item_type(string $type): string
{
    $t = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $type)));
    if ($t === '') {
        throw new MbAccountError('Give the item a type, such as decision, question or requirement.');
    }
    if (mb_strlen($t) > MB_ITEM_TYPE_MAX || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _-]*$/u', $t)) {
        throw new MbAccountError('A type is up to ' . MB_ITEM_TYPE_MAX . ' characters of letters, numbers, spaces, hyphens and underscores, such as "decision" or "open question".');
    }
    return $t;
}

function mb_item_value(string $value): string
{
    $v = trim(str_replace("\r\n", "\n", $value));
    if ($v === '') {
        throw new MbAccountError('The item needs a value: what was decided, asked, or required.');
    }
    if (mb_strlen($v) > MB_ITEM_VALUE_MAX) {
        throw new MbAccountError('An item\'s value is up to ' . MB_ITEM_VALUE_MAX . ' characters. Yours is ' . mb_strlen($v) . '. Put the detail in a post and keep the item short.');
    }
    return $v;
}

function mb_item_row(array $r): array
{
    return ['n' => (int) $r['n'], 'type' => (string) $r['type'], 'value' => (string) $r['value'], 'version' => (int) $r['version'],
            'added_by' => (string) $r['creator'], 'added_ts' => (int) $r['created_ts'],
            'updated_by' => (string) $r['updater'], 'updated_ts' => (int) $r['updated_ts'], 'creator_id' => (int) $r['created_by']];
}

const MB_ITEM_SELECT = 'SELECT i.n, i.type, i.value, i.version, i.created_by, c.handle AS creator, u.handle AS updater,
                               UNIX_TIMESTAMP(i.created_at) AS created_ts, UNIX_TIMESTAMP(i.updated_at) AS updated_ts
                          FROM pad_items i JOIN principals c ON c.id = i.created_by JOIN principals u ON u.id = i.updated_by';

/** The pad's items, grouped by type in the order the types first appeared; $type narrows to one type. */
function mb_items(PDO $pdo, int $padId, ?string $type = null): array
{
    $q = $pdo->prepare(MB_ITEM_SELECT . ' WHERE i.pad_id = ?' . ($type !== null ? ' AND i.type = ?' : '') . ' ORDER BY i.n');
    $q->execute($type !== null ? [$padId, $type] : [$padId]);
    return array_map('mb_item_row', $q->fetchAll());
}

function mb_item(PDO $pdo, int $padId, int $n): ?array
{
    $q = $pdo->prepare(MB_ITEM_SELECT . ' WHERE i.pad_id = ? AND i.n = ?');
    $q->execute([$padId, $n]);
    $r = $q->fetch();
    return $r ? mb_item_row($r) : null;
}

/** The types in use, with how many items each has, most used first: ['decision' => 3, 'question' => 1]. */
function mb_item_types(PDO $pdo, int $padId): array
{
    $q = $pdo->prepare('SELECT type, COUNT(*) AS c FROM pad_items WHERE pad_id = ? GROUP BY type ORDER BY c DESC, type');
    $q->execute([$padId]);
    return array_map('intval', array_column($q->fetchAll(), 'c', 'type'));
}

/** A short stamp that changes whenever any item is added, changed or removed, so a viewer knows when to reload them. */
function mb_items_stamp(PDO $pdo, int $padId): string
{
    $q = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(MAX(UNIX_TIMESTAMP(updated_at)), 0) AS t, COALESCE(SUM(version), 0) AS v FROM pad_items WHERE pad_id = ?');
    $q->execute([$padId]);
    $r = $q->fetch();
    return $r['c'] . ':' . $r['t'] . ':' . $r['v'];
}

/** Group a list of items by type, keeping the order types first appear: [['type' => 'decision', 'items' => [...]], ...]. */
function mb_items_grouped(array $items): array
{
    $groups = [];
    foreach ($items as $it) {
        $groups[$it['type']][] = $it;
    }
    $out = [];
    foreach ($groups as $type => $list) {
        $out[] = ['type' => $type, 'items' => $list];
    }
    return $out;
}

/** Whether $who (a principal row) may remove $item: whoever added it, or the pad's owner. */
function mb_can_remove_item(array $pad, array $item, array $who): bool
{
    $id = (int) ($who['id'] ?? 0);
    return $id > 0 && ($id === (int) $item['creator_id'] || $id === (int) $pad['owner_id']);
}

/** Only members change a pad's items. The callers check this too; this is the last line. */
function mb_items_require_member(PDO $pdo, array $pad, array $who): void
{
    if (!mb_membership($pdo, (int) $pad['id'], (int) ($who['id'] ?? 0))) {
        throw new MbAccountError('Only members of this pad can change its items.');
    }
}

/** The most item changes a person may make in an hour, so a looping agent can't flood a pad. */
function mb_item_throttle(PDO $pdo, int $actorId): void
{
    $q = $pdo->prepare("SELECT COUNT(*) AS c, MIN(at) AS first FROM audit_log WHERE actor_id = ? AND action LIKE 'item.%' AND at > UTC_TIMESTAMP() - INTERVAL 1 HOUR");
    $q->execute([$actorId]);
    $r = $q->fetch();
    if ((int) $r['c'] >= MB_ITEM_WRITES) {
        $wait = max(60, strtotime($r['first'] . ' UTC') + 3600 - time());
        throw new MbAccountError('Too many item changes: ' . MB_ITEM_WRITES . ' an hour is the limit. Try again in ' . (int) ceil($wait / 60) . ' minutes. Items are for what is worth finding later; the conversation is for the rest.');
    }
}

/** A short piece of a value for an event line. */
function mb_item_excerpt(string $v, int $max = 120): string
{
    $v = trim((string) preg_replace('/\s+/u', ' ', $v));
    return mb_strlen($v) > $max ? rtrim(mb_substr($v, 0, $max - 1)) . '…' : $v;
}

function mb_item_add(PDO $pdo, array $pad, array $who, string $type, string $value): array
{
    mb_items_require_member($pdo, $pad, $who);
    $type  = mb_item_type($type);
    $value = mb_item_value($value);
    mb_item_throttle($pdo, (int) $who['id']);
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT next_item FROM pads WHERE id = ? FOR UPDATE');
        $lock->execute([$pad['id']]);
        $n = (int) $lock->fetchColumn();
        $count = $pdo->prepare('SELECT COUNT(*) FROM pad_items WHERE pad_id = ?');
        $count->execute([$pad['id']]);
        if ((int) $count->fetchColumn() >= MB_ITEMS_PER_PAD) {
            throw new MbAccountError('This pad already has ' . MB_ITEMS_PER_PAD . ' items, which is the most it holds. Remove ones that are settled or out of date.');
        }
        $pdo->prepare('INSERT INTO pad_items (pad_id, n, type, value, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$pad['id'], $n, $type, $value, $who['id'], $who['id']]);
        $pdo->prepare('UPDATE pads SET next_item = next_item + 1 WHERE id = ?')->execute([$pad['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    mb_audit($pdo, (int) $who['id'], 'item.add', 'pad:' . $pad['id'], "#$n $type");
    mb_pad_event($pdo, $pad, (int) $who['id'], '@' . $who['handle'] . ' added ' . mb_item_a($type) . " (#$n): " . mb_item_excerpt($value));
    return mb_item($pdo, (int) $pad['id'], $n);
}

/** "a decision", "an open question". */
function mb_item_a(string $type): string
{
    return (preg_match('/^[aeiou]/iu', $type) ? 'an ' : 'a ') . $type;
}

/**
 * Change an item. $baseVersion is the version it was read at. $type null keeps its type.
 * Refused with MbItemConflict if it changed since, or no longer exists.
 */
function mb_item_update(PDO $pdo, array $pad, array $who, int $n, ?string $type, string $value, int $baseVersion): array
{
    mb_items_require_member($pdo, $pad, $who);
    $newType = $type === null ? null : mb_item_type($type);
    $value   = mb_item_value($value);
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT type, value, version FROM pad_items WHERE pad_id = ? AND n = ? FOR UPDATE');
        $q->execute([$pad['id'], $n]);
        $cur = $q->fetch();
        if (!$cur) {
            $pdo->commit();
            throw new MbItemConflict(null, $baseVersion);
        }
        if ((int) $cur['version'] !== $baseVersion) {
            $pdo->commit();
            throw new MbItemConflict(mb_item($pdo, (int) $pad['id'], $n), $baseVersion);
        }
        $newType ??= (string) $cur['type'];
        if ($newType === $cur['type'] && $value === $cur['value']) {
            $pdo->commit();
            return mb_item($pdo, (int) $pad['id'], $n);                     // nothing changed: no new version, no event
        }
        mb_item_throttle($pdo, (int) $who['id']);       // only a real change counts against the hourly limit
        $pdo->prepare('UPDATE pad_items SET type = ?, value = ?, version = version + 1, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE pad_id = ? AND n = ?')
            ->execute([$newType, $value, $who['id'], $pad['id'], $n]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    mb_audit($pdo, (int) $who['id'], 'item.update', 'pad:' . $pad['id'], "#$n $newType");
    $said = '@' . $who['handle'] . ' updated ' . ($newType !== $cur['type'] ? 'item #' . $n . ' (now ' . mb_item_a($newType) . ')' : mb_item_a($newType) . " (#$n)")
          . ': ' . mb_item_excerpt($value) . ($value !== $cur['value'] ? ' (was: ' . mb_item_excerpt((string) $cur['value']) . ')' : '');
    mb_pad_event($pdo, $pad, (int) $who['id'], $said);
    return mb_item($pdo, (int) $pad['id'], $n);
}

/** Remove an item: whoever added it, or the pad's owner. Returns the item that was removed. */
function mb_item_remove(PDO $pdo, array $pad, array $who, int $n): array
{
    mb_items_require_member($pdo, $pad, $who);
    $item = mb_item($pdo, (int) $pad['id'], $n);
    if (!$item) {
        throw new MbAccountError('There is no item ' . $n . ' on this pad. It may already have been removed.');
    }
    if (!mb_can_remove_item($pad, $item, $who)) {
        throw new MbAccountError('Only @' . $item['added_by'] . ', who added item ' . $n . ', or the pad\'s owner can remove it. You can change its text instead.');
    }
    mb_item_throttle($pdo, (int) $who['id']);
    $pdo->prepare('DELETE FROM pad_items WHERE pad_id = ? AND n = ?')->execute([$pad['id'], $n]);
    mb_audit($pdo, (int) $who['id'], 'item.remove', 'pad:' . $pad['id'], "#$n {$item['type']}");
    mb_pad_event($pdo, $pad, (int) $who['id'], '@' . $who['handle'] . ' removed ' . mb_item_a($item['type']) . " (#$n): " . mb_item_excerpt($item['value']));
    return $item;
}

/**
 * An item as the API and the agents see it. With $pad and $who it also says whether $who may remove it.
 * The person's id is not part of it.
 */
function mb_item_public(array $it, ?array $pad = null, ?array $who = null): array
{
    $out = $it;
    unset($out['creator_id']);
    if ($pad !== null && $who !== null) {
        $out['can_remove'] = mb_can_remove_item($pad, $it, $who);
    }
    return $out;
}
