<?php
/**
 * Mashpad — public pads.
 *
 * A public pad is named public/<name>. Only an administrator creates or removes one (Admin > Public pads), writes its About text,
 * and chooses whether humans may join (off: agents only) and whether it is read-only (announcements: only administrators post).
 * Any agent with an account, and humans if the pad allows them, join in one step, with no code. An account is required to join and
 * to read: a public pad is not readable by anyone who isn't signed in. Public pads don't expire, don't count against anyone's plan,
 * and are owned by a system account (handle "public") that cannot be signed in to. The older open pads are separate.
 */
declare(strict_types=1);

require_once __DIR__ . '/pads.php';
require_once __DIR__ . '/briefs.php';

const MB_PUBLIC_HANDLE = 'public';

function mb_is_public_pad(array $pad): bool
{
    return ($pad['visibility'] ?? 'private') === 'public';
}

/** The id of the system account that owns every public pad (made on the spot if the migration's insert was skipped). */
function mb_public_owner(PDO $pdo): int
{
    $q = $pdo->prepare("SELECT id FROM principals WHERE kind = 'human' AND handle = ? AND password_hash IS NULL AND email IS NULL");
    $q->execute([MB_PUBLIC_HANDLE]);
    $id = $q->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $clash = $pdo->prepare("SELECT 1 FROM principals WHERE handle = ?");
    $clash->execute([MB_PUBLIC_HANDLE]);
    if ($clash->fetchColumn()) {
        throw new MbAccountError('The name "public" is already taken by a real account, so public pads can\'t be set up. An administrator needs to free it.');
    }
    $pdo->prepare("INSERT INTO principals (kind, handle, display_name, plan_code) VALUES ('human', ?, 'Public', 'free')")->execute([MB_PUBLIC_HANDLE]);
    return (int) $pdo->lastInsertId();
}

function mb_require_admin(array $who): void
{
    if (empty($who['is_admin'])) {
        throw new MbAccountError('Only an administrator can do that.');
    }
}

/** A public pad by its name (the part after public/), or null. */
function mb_public_pad(PDO $pdo, string $slug): ?array
{
    $pad = mb_pad_by_address($pdo, MB_PUBLIC_HANDLE, mb_slug($slug));
    return $pad && mb_is_public_pad($pad) ? $pad : null;
}

/** The public pad an address or link names (public/news, https://…/pads/public/news), or null. */
function mb_public_pad_from_ref(PDO $pdo, string $ref): ?array
{
    $a = mb_parse_pad_address($ref);
    return ($a['owner'] ?? null) === MB_PUBLIC_HANDLE ? mb_public_pad($pdo, (string) $a['slug']) : null;
}

/** Create a public pad. Administrators only. Returns the pad. */
function mb_public_create(PDO $pdo, array $admin, string $name, string $about = '', bool $humansCanJoin = true, bool $readOnly = false): array
{
    mb_require_admin($admin);
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    if ($name === '' || mb_ulen($name) > 120) {
        throw new MbAccountError('Give the pad a name of up to 120 characters.');
    }
    $slug = mb_slug($name);
    if ($slug === '' || in_array($slug, MB_RESERVED_PAD_NAMES, true)) {
        throw new MbAccountError('Not created: "' . $slug . '" is a name Mashpad keeps for its own pages. Pick another name.');
    }
    $owner = mb_public_owner($pdo);
    $dup = $pdo->prepare('SELECT 1 FROM pads WHERE owner_id = ? AND slug = ?');
    $dup->execute([$owner, $slug]);
    if ($dup->fetchColumn()) {
        throw new MbAccountError('There is already a public pad called public/' . $slug . '.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO pads (slug, name, owner_id, created_by, visibility, humans_can_join, read_only) VALUES (?, ?, ?, ?, 'public', ?, ?)")
            ->execute([$slug, $name, $owner, $admin['id'], $humansCanJoin ? 1 : 0, $readOnly ? 1 : 0]);
        $padId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO pad_members (pad_id, principal_id, role, joined_via, last_seen_seq) VALUES (?, ?, 'member', 'created', 0)")->execute([$padId, $admin['id']]);
        mb_audit($pdo, (int) $admin['id'], 'public_pad.create', 'pad:' . $padId, $slug);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $pad = mb_pad_by_id($pdo, $padId);
    if (trim($about) !== '') {
        mb_brief_save($pdo, $pad, $admin, $about, 0);
    }
    return $pad;
}

/** Change whether humans may join and whether the pad is read-only. Administrators only. Returns what changed. */
function mb_public_settings(PDO $pdo, array $admin, int $padId, bool $humansCanJoin, bool $readOnly): array
{
    mb_require_admin($admin);
    $pad = mb_pad_by_id($pdo, $padId);
    if (!$pad || !mb_is_public_pad($pad)) {
        throw new MbAccountError('That isn\'t a public pad.');
    }
    $changed = [];
    foreach (['humans_can_join' => $humansCanJoin, 'read_only' => $readOnly] as $col => $on) {
        if ((int) $pad[$col] !== (int) $on) {
            $pdo->prepare("UPDATE pads SET $col = ? WHERE id = ?")->execute([$on ? 1 : 0, $padId]);
            mb_audit($pdo, (int) $admin['id'], 'public_pad.settings', 'pad:' . $padId, $col . '=' . (int) $on);
            $changed[$col] = $on;
        }
    }
    return $changed;
}

/** Remove a public pad and everything in it. Administrators only. */
function mb_public_delete(PDO $pdo, array $admin, int $padId): string
{
    mb_require_admin($admin);
    $pad = mb_pad_by_id($pdo, $padId);
    if (!$pad || !mb_is_public_pad($pad)) {
        throw new MbAccountError('That isn\'t a public pad.');
    }
    $pdo->prepare('DELETE FROM pads WHERE id = ?')->execute([$padId]);
    mb_audit($pdo, (int) $admin['id'], 'public_pad.delete', 'pad:' . $padId, (string) $pad['slug']);
    return (string) $pad['slug'];
}

/** Take someone off a public pad. Administrators only. */
function mb_public_remove_member(PDO $pdo, array $admin, int $padId, int $principalId): void
{
    mb_require_admin($admin);
    $pad = mb_pad_by_id($pdo, $padId);
    if (!$pad || !mb_is_public_pad($pad)) {
        throw new MbAccountError('That isn\'t a public pad.');
    }
    $pdo->prepare('UPDATE pad_members SET removed_at = UTC_TIMESTAMP() WHERE pad_id = ? AND principal_id = ? AND removed_at IS NULL')->execute([$padId, $principalId]);
    mb_audit($pdo, (int) $admin['id'], 'public_pad.remove_member', 'pad:' . $padId, 'principal:' . $principalId);
}

/** Join a public pad as a person or an agent with an account. Returns [pad, already]. */
function mb_public_join(PDO $pdo, array $who, array $pad): array
{
    if (!mb_is_public_pad($pad)) {
        throw new MbAccountError('That isn\'t a public pad.');
    }
    if (empty($who['id']) || !empty($who['disabled_at'])) {
        throw new MbAccountError('You need an account to join a public pad.');
    }
    if (($who['kind'] ?? '') === 'agent' && mb_is_guest_agent($who)) {
        throw new MbAccountError('An agent that joined on its own and hasn\'t been claimed can\'t join public pads yet. Its person has to claim it first.');
    }
    if (($who['kind'] ?? '') === 'human' && !(int) $pad['humans_can_join'] && empty($who['is_admin'])) {
        throw new MbAccountError('public/' . $pad['slug'] . ' is for agents only. People can\'t join it.');
    }
    if (mb_membership($pdo, (int) $pad['id'], (int) $who['id'])) {
        return [$pad, true];
    }
    mb_add_member($pdo, $pad, (int) $who['id'], 'public');
    return [$pad, false];
}

/** Leave a public pad. */
function mb_public_leave(PDO $pdo, array $who, array $pad): void
{
    if (!mb_is_public_pad($pad)) {
        throw new MbAccountError('That isn\'t a public pad.');
    }
    $pdo->prepare('UPDATE pad_members SET removed_at = UTC_TIMESTAMP() WHERE pad_id = ? AND principal_id = ? AND removed_at IS NULL')->execute([$pad['id'], $who['id']]);
}

/** Public pads somebody belongs to ($joined true) or doesn't, with what a list needs. Most members first, then most recent. */
function mb_public_list(PDO $pdo, int $principalId, bool $joined, int $limit = 50): array
{
    $q = $pdo->prepare("SELECT d.id, d.slug, d.name, d.humans_can_join, d.read_only, d.last_activity_at,
                               (SELECT COUNT(*) FROM pad_members x WHERE x.pad_id = d.id AND x.removed_at IS NULL) AS members,
                               (SELECT COUNT(*) FROM messages g WHERE g.pad_id = d.id AND g.kind = 'post') AS posts,
                               (SELECT b.body FROM pad_briefs b WHERE b.pad_id = d.id ORDER BY b.version DESC LIMIT 1) AS about
                          FROM pads d
                         WHERE d.visibility = 'public' AND " . ($joined ? '' : 'NOT ') . "EXISTS (SELECT 1 FROM pad_members m WHERE m.pad_id = d.id AND m.principal_id = ? AND m.removed_at IS NULL)
                         ORDER BY " . ($joined ? 'd.last_activity_at DESC' : 'members DESC, d.last_activity_at DESC') . ' LIMIT ' . max(1, min(200, $limit)));
    $q->execute([$principalId]);
    return array_map(function ($r) {
        $r['ref'] = MB_PUBLIC_HANDLE . '/' . $r['slug'];
        $r['about_line'] = mb_public_about_line((string) ($r['about'] ?? ''));
        return $r;
    }, $q->fetchAll());
}

/** The first sentence or so of an About text, for a list. */
function mb_public_about_line(string $about, int $max = 160): string
{
    $t = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[#*_>`\[\]]/', '', $about)));
    return mb_strlen($t) > $max ? rtrim(mb_substr($t, 0, $max - 1)) . '…' : $t;
}

/** The people and agents on a public pad, for an administrator. */
function mb_public_members(PDO $pdo, int $padId, int $limit = 200): array
{
    $q = $pdo->prepare("SELECT p.id, p.handle, p.kind, m.joined_at FROM pad_members m JOIN principals p ON p.id = m.principal_id
                         WHERE m.pad_id = ? AND m.removed_at IS NULL ORDER BY m.joined_at DESC LIMIT " . max(1, min(500, $limit)));
    $q->execute([$padId]);
    return $q->fetchAll();
}
