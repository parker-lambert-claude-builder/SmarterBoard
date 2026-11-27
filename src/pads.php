<?php
/**
 * Mashpad — owned pads: creation, membership, messages, agent identities,
 * agent tokens and lobby codes.
 *
 * Rules (band-competitiveness #52, #59, #61):
 * - A pad belongs to one human owner. Only active members can see it; to anyone
 *   else it is indistinguishable from a pad that does not exist.
 * - Reading or posting never creates a pad. Humans create pads; an agent can only
 *   if its responsible human allowed it, and the pad still belongs to that human.
 * - An agent is one identity (one token) across all the pads it belongs to.
 * - Lobby codes last 10 minutes, admit several agents, and admit them at once.
 * - Handles are unique among a pad's active members.
 * - A pad's address is <owner handle>/<pad name>, shown as /pads/<owner>/<pad>.
 *   The pad name is unique per owner, so two people can each have a "launch".
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/checkguide.php';
require_once __DIR__ . '/../lib.php';

const MB_LOBBY_SECONDS  = 600;
const MB_TOKEN_PREFIX   = 'mp_a_';
const MB_PRUNE_MARKER   = __DIR__ . '/../data/.last-pad-prune';

/**
 * Pad names nobody can use: every top-level page and folder, plus words kept for
 * pages that may later sit under a pad's own address. One list, used at creation.
 */
const MB_RESERVED_PAD_NAMES = [
    'access', 'admin', 'agents', 'api', 'assets', 'bin', 'create', 'email', 'data', 'docs', 'export', 'features', 'index', 'info', 'invite', 'join', 'manage', 'mcp',
    'members', 'migrations', 'new', 'oauth', 'pads', 'pricing', 'privacy', 'register', 'reset', 'settings', 'setup', 'signin',
    'signout', 'src', 'terms', 'tests', 'public', 'public-pads',
];

/** A bare pad name that matches more than one of the caller's pads. */
class MbAmbiguousPad extends MbAccountError
{
    /** @param string[] $refs full owner/pad addresses */
    public function __construct(string $name, public array $refs)
    {
        parent::__construct('More than one of your pads is called "' . $name . '": ' . implode(' and ', $refs)
            . '. Nothing was read or posted. Use the full address, for example "' . $refs[0] . '".');
    }
}

class MbThrottled extends MbAccountError
{
    public function __construct(string $message, public int $retryAfter)
    {
        parent::__construct($message);
    }
}

// ---------------------------------------------------------------- lookups

function mb_principal(PDO $pdo, int $id): ?array
{
    $q = $pdo->prepare('SELECT p.*, a.role, a.doing, a.platform, a.acts_for, a.claimed_at, a.webhook_url, a.can_create_pads, a.can_message_agents, a.can_join_account_pads, a.can_receive_outside_messages, a.icon, a.joined_via AS agent_joined_via,
                               a.self_described, a.last_active_at, a.last_messages_read_at, a.checks, a.checks_note
                          FROM principals p LEFT JOIN agent_profiles a ON a.principal_id = p.id WHERE p.id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

const MB_PAD_SELECT = 'SELECT d.*, o.handle AS owner_handle, o.plan_code, pl.name AS plan_name, pl.retention_days, pl.post_interval_seconds, pl.check_interval_seconds, pl.threading
                         FROM pads d JOIN principals o ON o.id = d.owner_id LEFT JOIN plans pl ON pl.code = o.plan_code';

function mb_pad_by_id(PDO $pdo, int $id): ?array
{
    $q = $pdo->prepare(MB_PAD_SELECT . ' WHERE d.id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

/** The pad at <owner handle>/<slug>, whoever is asking. */
function mb_pad_by_address(PDO $pdo, string $ownerHandle, string $slug): ?array
{
    $q = $pdo->prepare(MB_PAD_SELECT . ' WHERE o.handle = ? AND d.slug = ?');
    $q->execute([strtolower($ownerHandle), $slug]);
    return $q->fetch() ?: null;
}

/** "dylan/launch": the address people and agents use for an owned pad. */
function mb_pad_ref(array $pad): string
{
    return $pad['owner_handle'] . '/' . $pad['slug'];
}

/** Absolute link to an owned pad, e.g. https://mashpad.ai/pads/dylan/launch */
function mb_pad_url(array $pad): string
{
    return mb_site_url() . 'pads/' . mb_pad_ref($pad);
}

/**
 * Take apart whatever someone gave as a pad: a link (…/pads/<owner>/<pad>, or the
 * older …/pads/?b=<pad>), "owner/pad", "@owner/pad", or a bare name.
 * Returns ['owner' => handle|null, 'slug' => slug|null, 'flat' => slug of the whole text|null].
 * 'flat' covers names that merely contain a slash ("A/B test" is the pad "a-b-test").
 */
function mb_parse_pad_address(string $address): array
{
    $address = trim($address);
    if (preg_match('~^https?://~i', $address)) {
        $path = (string) parse_url($address, PHP_URL_PATH);
        if (preg_match('~/pads/([^/]+)/([^/]+)/?$~', $path, $m)) {
            $address = rawurldecode($m[1]) . '/' . rawurldecode($m[2]);
        } else {
            parse_str((string) parse_url($address, PHP_URL_QUERY), $q);
            $address = trim((string) ($q['b'] ?? ''));
        }
    }
    $out = ['owner' => null, 'slug' => null, 'flat' => null];
    try {
        $out['flat'] = mb_slug($address);
    } catch (MbError $e) {
    }
    if (preg_match('~^@?([A-Za-z0-9][A-Za-z0-9_-]{1,38}[A-Za-z0-9])/([^/]+)/?$~', $address, $m)) {
        try {
            $out['slug']  = mb_slug($m[2]);
            $out['owner'] = strtolower($m[1]);
        } catch (MbError $e) {
        }
    }
    return $out;
}

function mb_membership(PDO $pdo, int $padId, int $principalId): ?array
{
    $q = $pdo->prepare('SELECT * FROM pad_members WHERE pad_id = ? AND principal_id = ? AND removed_at IS NULL');
    $q->execute([$padId, $principalId]);
    return $q->fetch() ?: null;
}

/** Every pad matching $address that $principalId is an active member of. */
function mb_pads_matching(PDO $pdo, string $address, int $principalId): array
{
    $a    = mb_parse_pad_address($address);
    $sql  = str_replace('SELECT d.*', 'SELECT m.role AS my_role, d.*', MB_PAD_SELECT)
          . ' JOIN pad_members m ON m.pad_id = d.id AND m.principal_id = ? AND m.removed_at IS NULL';
    $rows = [];
    if ($a['owner'] !== null) {
        $q = $pdo->prepare($sql . ' WHERE o.handle = ? AND d.slug = ?');
        $q->execute([$principalId, $a['owner'], $a['slug']]);
        $rows = $q->fetchAll();
    }
    if (!$rows && $a['flat'] !== null) {
        $q = $pdo->prepare($sql . ' WHERE d.slug = ? ORDER BY o.handle');
        $q->execute([$principalId, $a['flat']]);
        $rows = $q->fetchAll();
    }
    return $rows;
}

/**
 * The pad if $principalId is an active member; null otherwise (same as "doesn't exist").
 * $name is a full address or a bare name. A bare name that matches two of the
 * caller's pads throws MbAmbiguousPad rather than guessing.
 */
function mb_pad_for(PDO $pdo, string $name, int $principalId): ?array
{
    $pads = mb_pads_matching($pdo, $name, $principalId);
    if (count($pads) > 1) {
        throw new MbAmbiguousPad(trim($name), array_map('mb_pad_ref', $pads));
    }
    return $pads[0] ?? null;
}

function mb_not_found(string $name): MbAccountError
{
    return new MbAccountError('There is no pad called "' . $name . '" that you belong to. Check the name, or ask the pad\'s owner to add you.');
}

/** Pads $principalId belongs to, most recently active first. */
function mb_pads_for(PDO $pdo, int $principalId): array
{
    $q = $pdo->prepare("SELECT d.id, d.slug, d.name, d.owner_id, d.visibility, d.read_only, d.humans_can_join, o.handle AS owner_handle, d.last_activity_at, d.next_seq - 1 AS latest_id, m.role,
                               pl.retention_days, pl.check_interval_seconds, m.check_interval_seconds AS agreed_interval, cb.kind AS created_by_kind, cb.handle AS created_by_handle,
                               (SELECT COUNT(*) FROM messages x WHERE x.pad_id = d.id) AS total
                          FROM pad_members m JOIN pads d ON d.id = m.pad_id
                          JOIN principals o ON o.id = d.owner_id LEFT JOIN plans pl ON pl.code = o.plan_code
                          JOIN principals cb ON cb.id = d.created_by
                         WHERE m.principal_id = ? AND m.removed_at IS NULL
                         ORDER BY d.last_activity_at DESC");
    $q->execute([$principalId]);
    $pads = $q->fetchAll();
    $who  = $pdo->prepare("SELECT p.handle, p.kind FROM pad_members m JOIN principals p ON p.id = m.principal_id
                            WHERE m.pad_id = ? AND m.removed_at IS NULL ORDER BY m.joined_at");
    foreach ($pads as &$p) {
        $who->execute([$p['id']]);
        $rows               = $who->fetchAll();
        $p['members']       = array_column($rows, 'handle');
        $p['agents']        = count(array_filter($rows, fn ($r) => $r['kind'] === 'agent'));      // the rail says "3 agents, 2 humans"
        $p['humans']        = count($rows) - $p['agents'];
        $p['ref']           = mb_pad_ref($p);
        $p['last_activity'] = strtotime($p['last_activity_at'] . ' UTC');
        $p['expires']       = $p['last_activity'] + 86400 * (int) ($p['retention_days'] ?? 30);
    }
    return $pads;
}

/** Active members with their identities, owner first. */
function mb_pad_members(PDO $pdo, int $padId): array
{
    $q = $pdo->prepare("SELECT m.role, m.joined_via, m.joined_at, m.last_read_at, p.id, p.kind, p.handle, p.display_name, p.owner_id,
                               COALESCE(m.profile_role, a.role) AS agent_role, COALESCE(m.profile_doing, a.doing) AS doing, m.check_interval_seconds AS declared_interval, a.platform, a.acts_for, a.self_described, a.last_active_at, a.checks, a.checks_note, a.can_create_pads
                          FROM pad_members m JOIN principals p ON p.id = m.principal_id
                          LEFT JOIN agent_profiles a ON a.principal_id = p.id
                         WHERE m.pad_id = ? AND m.removed_at IS NULL
                         ORDER BY m.role = 'owner' DESC, m.joined_at");
    $q->execute([$padId]);
    return $q->fetchAll();
}

// ---------------------------------------------------------------- pads

/** The owner's existing pad with this name, if any. Names are unique per owner, not across the site. */
function mb_owner_pad(PDO $pdo, int $ownerId, string $slug): ?array
{
    $q = $pdo->prepare(MB_PAD_SELECT . ' WHERE d.owner_id = ? AND d.slug = ?');
    $q->execute([$ownerId, $slug]);
    return $q->fetch() ?: null;
}

function mb_pad_name_taken(array $existing): MbAccountError
{
    return new MbAccountError('Not created: there is already a pad at ' . mb_pad_url($existing) . ' (' . mb_pad_ref($existing) . '). Pick another name.');
}

/**
 * Create a pad. $creator is a principal row (human, or an agent with can_create_pads).
 * The owner is always a human: the creator, or the agent's responsible human.
 */
function mb_create_pad(PDO $pdo, array $creator, string $name): array
{
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    if ($name === '' || mb_ulen($name) > 120) {
        throw new MbAccountError('Give the pad a name of up to 120 characters.');
    }
    $slug = mb_slug($name);
    if (in_array($slug, MB_RESERVED_PAD_NAMES, true)) {
        throw new MbAccountError('Not created: "' . $slug . '" is a name Mashpad keeps for its own pages. Pick another name.');
    }
    if ($creator['kind'] === 'agent') {
        if (empty($creator['can_create_pads'])) {
            throw new MbAccountError('Not created: your responsible person hasn\'t allowed you to create pads. Ask them to switch on "Can create pads" on your agent profile, or to create the pad and add you.');
        }
        $ownerId = (int) $creator['owner_id'];
    } else {
        $ownerId = (int) $creator['id'];
    }
    $owner = mb_principal($pdo, $ownerId);
    $limit = $pdo->prepare('SELECT max_owned_pads FROM plans WHERE code = ?');
    $limit->execute([$owner['plan_code'] ?? 'free']);
    $max = $limit->fetchColumn();
    if ($max !== null && $max !== false) {
        $n = $pdo->prepare('SELECT COUNT(*) FROM pads WHERE owner_id = ?');
        $n->execute([$ownerId]);
        if ((int) $n->fetchColumn() >= (int) $max) {
            throw new MbAccountError("Not created: this account's plan allows $max pads, and all are in use.");
        }
    }
    if ($existing = mb_owner_pad($pdo, $ownerId, $slug)) {
        throw mb_pad_name_taken($existing);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO pads (slug, name, owner_id, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$slug, $name, $ownerId, (int) $creator['id']]);
        $padId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO pad_members (pad_id, principal_id, role, joined_via) VALUES (?, ?, 'owner', 'created')")
            ->execute([$padId, $ownerId]);
        if ($creator['kind'] === 'agent') {
            $pdo->prepare("INSERT INTO pad_members (pad_id, principal_id, role, joined_via) VALUES (?, ?, 'member', 'created')")
                ->execute([$padId, (int) $creator['id']]);
        }
        mb_audit($pdo, (int) $creator['id'], 'pad.create', 'pad:' . $padId, $slug);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ((int) ($e->errorInfo[1] ?? 0) === 1062 && ($existing = mb_owner_pad($pdo, $ownerId, $slug))) {
            throw mb_pad_name_taken($existing);
        }
        throw $e;
    }
    return mb_pad_by_id($pdo, $padId);
}

/** Messages in the same shape as the file-based pads: id (the per-pad #), author, body, ts. */
function mb_pad_read(PDO $pdo, array $pad, ?int $sinceId, int $limit): array
{
    $cols = 'SELECT m.seq AS id, p.handle AS author, m.body, UNIX_TIMESTAMP(m.created_at) AS ts, p.kind AS author_kind, m.kind, pm.seq AS reply_to
               FROM messages m JOIN principals p ON p.id = m.author_id LEFT JOIN messages pm ON pm.id = m.parent_id WHERE m.pad_id = ?';
    if ($sinceId !== null) {
        $q = $pdo->prepare("$cols AND m.seq > ? ORDER BY m.seq");
        $q->execute([$pad['id'], $sinceId]);
        $msgs = $q->fetchAll();
    } else {
        $q = $pdo->prepare("$cols ORDER BY m.seq DESC LIMIT " . max(1, min($limit, MB_MAX_MESSAGES)));
        $q->execute([$pad['id']]);
        $msgs = array_reverse($q->fetchAll());
    }
    foreach ($msgs as &$m) {
        $m['id'] = (int) $m['id'];
        $m['ts'] = (int) $m['ts'];
        $m['reply_to'] = $m['reply_to'] === null ? null : (int) $m['reply_to'];
    }
    $total = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE pad_id = ?');
    $total->execute([$pad['id']]);
    $who = $pdo->prepare('SELECT p.handle FROM pad_members m JOIN principals p ON p.id = m.principal_id
                           WHERE m.pad_id = ? AND m.removed_at IS NULL ORDER BY m.joined_at');
    $who->execute([$pad['id']]);
    $kinds = $pdo->prepare('SELECT p.handle, p.kind, p.id, a.icon FROM pad_members m JOIN principals p ON p.id = m.principal_id LEFT JOIN agent_profiles a ON a.principal_id = p.id WHERE m.pad_id = ?');      // everyone who has been on it, so an author who left still shows as a person or an agent
    $kinds->execute([$pad['id']]);
    $kindRows = $kinds->fetchAll();
    $icons = [];
    foreach ($kindRows as $kr) {
        if ($kr['kind'] === 'agent') {
            $icons[$kr['handle']] = mb_agent_icon_url(mb_agent_icon($pdo, ['id' => $kr['id'], 'icon' => $kr['icon']]));
        }
    }
    $last = strtotime($pad['last_activity_at'] . ' UTC');
    return [
        'name'          => $pad['name'],
        'slug'          => $pad['slug'],
        'owner'         => $pad['owner_handle'],
        'key'           => mb_pad_ref($pad),
        'owned'         => true,
        'public'        => ($pad['visibility'] ?? 'private') === 'public',       // a public pad: no expiry, no owner to manage it
        'read_only'     => (bool) (int) ($pad['read_only'] ?? 0),
        'threaded'      => mb_pad_threaded($pad),
        'members'       => $who->fetchAll(PDO::FETCH_COLUMN),      // handles; lets the viewer link @mentions
        'kinds'         => array_column($kindRows, 'kind', 'handle'),  // handle => 'human' or 'agent', for the mark in front of each name
        'icons'         => $icons,                                  // handle => the address of an agent's icon
        'is_owner'      => ($pad['my_role'] ?? '') === 'owner',
        'created'       => strtotime($pad['created_at'] . ' UTC'),
        'last_activity' => $last,
        'expires'       => $last + 86400 * (int) ($pad['retention_days'] ?? 30),
        'total'         => (int) $total->fetchColumn(),
        'latest_id'     => (int) $pad['next_seq'] - 1,
        'messages'      => $msgs,
    ];
}

/** "one post per minute", "one post every 10 seconds" */
function mb_interval_phrase(int $seconds): string
{
    return match (true) {
        $seconds === 60          => 'one post per minute',
        $seconds % 60 === 0      => 'one post every ' . ($seconds / 60) . ' minutes',
        default                  => "one post every $seconds seconds",
    };
}

/** Whether replies on this pad are threaded: a feature of its owner's plan. */
function mb_pad_threaded(array $pad): bool
{
    return !empty($pad['threading']);
}

/**
 * Append a message. Enforces the owner's plan post interval per author (#43, #50).
 * $replyTo is the #number of the message being answered. On a pad whose plan has
 * threaded replies it is stored and the post appears under that message. On a
 * pad without them the post still goes through, at the end of the conversation,
 * and the returned message has 'unthreaded' => true so the caller can say so.
 */
function mb_pad_post(PDO $pdo, array $pad, array $author, string $body, ?int $replyTo = null): array
{
    if (!empty($pad['read_only']) && empty($author['is_admin'])) {
        throw new MbAccountError('Not posted: this is a read-only public pad, for announcements. Only Mashpad\'s administrators can post here. Your text was not saved.');
    }
    $body     = mb_clean_body($body);
    $parentId = null;
    $flat     = false;
    if ($replyTo !== null) {
        $q = $pdo->prepare("SELECT id FROM messages WHERE pad_id = ? AND seq = ? AND kind = 'post'");
        $q->execute([$pad['id'], $replyTo]);
        $found = $q->fetchColumn();
        if (!$found) {
            throw new MbAccountError('Not posted: there is no message #' . $replyTo . ' on this pad to reply to. Your text was not saved.');
        }
        if (mb_pad_threaded($pad)) {
            $parentId = (int) $found;
        } else {
            $flat = true;
        }
    }
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT next_seq FROM pads WHERE id = ? FOR UPDATE');
        $lock->execute([$pad['id']]);
        $seq = (int) $lock->fetchColumn();

        $interval = (int) ($pad['post_interval_seconds'] ?? 0);
        if ($interval > 0) {
            $q = $pdo->prepare("SELECT UNIX_TIMESTAMP(MAX(created_at)) FROM messages WHERE pad_id = ? AND author_id = ? AND kind = 'post'");
            $q->execute([$pad['id'], $author['id']]);
            $lastTs = $q->fetchColumn();
            $since  = $lastTs ? time() - (int) $lastTs : PHP_INT_MAX;
            if ($since < $interval) {
                $wait = $interval - $since;
                $plan = ($pad['plan_code'] ?? 'free') === 'free' ? 'the Free plan' : 'the ' . ($pad['plan_name'] ?? 'current') . ' plan';
                throw new MbThrottled(sprintf(
                    'Not posted: %s allows %s, and your last post was %d second%s ago. Send this message again in %d second%s (after %s UTC). Your text was not saved.',
                    $plan, mb_interval_phrase($interval), $since, $since === 1 ? '' : 's', $wait, $wait === 1 ? '' : 's', gmdate('H:i:s', time() + $wait)
                ), $wait);
            }
        }

        $pdo->prepare('INSERT INTO messages (pad_id, seq, author_id, body, parent_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$pad['id'], $seq, $author['id'], $body, $parentId]);
        $pdo->prepare('UPDATE pads SET next_seq = next_seq + 1, last_activity_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$pad['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    try {
        mb_wake_after_post($pdo, $pad, $author, $seq, $body, $replyTo);      // agents this post wakes are queued; it can never make the post fail
    } catch (Throwable $e) {
    }
    return ['id' => $seq, 'author' => $author['handle'], 'body' => $body, 'ts' => time(), 'reply_to' => $parentId !== null ? $replyTo : null] + ($flat ? ['unthreaded' => true] : []);
}

/**
 * Record something that happened on the pad ("@sam joined through the invite
 * link") in the conversation itself, so every member sees it. An event takes
 * the next message number but is not a post: it is never throttled, doesn't
 * count against the actor's post allowance, and doesn't extend retention.
 */
function mb_pad_event(PDO $pdo, array $pad, int $actorId, string $text): int
{
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT next_seq FROM pads WHERE id = ? FOR UPDATE');
        $lock->execute([$pad['id']]);
        $seq = (int) $lock->fetchColumn();
        $pdo->prepare("INSERT INTO messages (pad_id, seq, author_id, body, kind) VALUES (?, ?, ?, ?, 'event')")->execute([$pad['id'], $seq, $actorId, $text]);
        $pdo->prepare('UPDATE pads SET next_seq = next_seq + 1 WHERE id = ?')->execute([$pad['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $seq;
}

/** Delete owned pads past their owner's plan retention. Throttled; safe to call on every request. */
function mb_prune_owned(PDO $pdo, bool $force = false): int
{
    if (!$force && is_file(MB_PRUNE_MARKER) && filemtime(MB_PRUNE_MARKER) > time() - MB_PRUNE_EVERY) {
        return 0;
    }
    @touch(MB_PRUNE_MARKER);
    $q = $pdo->query("SELECT d.id, d.slug FROM pads d JOIN principals o ON o.id = d.owner_id JOIN plans pl ON pl.code = o.plan_code
                       WHERE d.visibility = 'private' AND d.last_activity_at < UTC_TIMESTAMP() - INTERVAL pl.retention_days DAY");        // public pads don't expire
    $n = 0;
    foreach ($q->fetchAll() as $p) {
        $pdo->prepare('DELETE FROM pads WHERE id = ?')->execute([$p['id']]);
        mb_audit($pdo, null, 'pad.expire', 'pad:' . $p['id'], $p['slug']);
        $n++;
    }
    return $n;
}

// ---------------------------------------------------------------- membership

/** Add (or re-add) a principal. Handles must be unique among the pad's active members. */
function mb_add_member(PDO $pdo, array $pad, int $principalId, string $via, ?int $lobbyId = null): void
{
    $p = mb_principal($pdo, $principalId);
    if (!$p || $p['disabled_at'] !== null) {
        throw new MbAccountError('That account isn\'t available.');
    }
    if (mb_membership($pdo, (int) $pad['id'], $principalId)) {
        return;
    }
    mb_check_seat_room($pdo, $pad, $p);          // a person needs room on the pad; an agent never does
    $clash = $pdo->prepare('SELECT 1 FROM pad_members m JOIN principals x ON x.id = m.principal_id
                             WHERE m.pad_id = ? AND m.removed_at IS NULL AND x.handle = ? AND x.id <> ?');
    $clash->execute([$pad['id'], $p['handle'], $principalId]);
    if ($clash->fetchColumn()) {
        throw new MbAccountError('Someone on this pad already goes by @' . $p['handle'] . '. Handles must be unique on a pad.');
    }
    // A new member starts up to date: what was said before they came is not "new" to them.
    $pdo->prepare("INSERT INTO pad_members (pad_id, principal_id, role, joined_via, lobby_code_id, last_seen_seq)
                   VALUES (?, ?, 'member', ?, ?, (SELECT next_seq - 1 FROM pads WHERE id = ?))
                   ON DUPLICATE KEY UPDATE removed_at = NULL, joined_via = VALUES(joined_via), lobby_code_id = VALUES(lobby_code_id), joined_at = UTC_TIMESTAMP(), last_seen_seq = VALUES(last_seen_seq)")
        ->execute([$pad['id'], $principalId, $via, $lobbyId, $pad['id']]);
    mb_audit($pdo, $principalId, 'pad.join', 'pad:' . $pad['id'], $via);
}

function mb_remove_member(PDO $pdo, array $pad, int $principalId, int $actorId): void
{
    $m = mb_membership($pdo, (int) $pad['id'], $principalId);
    if (!$m) {
        return;
    }
    if ($m['role'] === 'owner') {
        throw new MbAccountError('The owner can\'t be removed from their own pad.');
    }
    $pdo->prepare('UPDATE pad_members SET removed_at = UTC_TIMESTAMP() WHERE pad_id = ? AND principal_id = ?')->execute([$pad['id'], $principalId]);
    mb_audit($pdo, $actorId, 'pad.remove_member', 'pad:' . $pad['id'], 'principal:' . $principalId);
}

// ---------------------------------------------------------------- agents and tokens

function mb_clean_profile(array $in): array
{
    $short = function (string $key, int $max) use ($in): ?string {
        $v = trim((string) preg_replace('/\s+/u', ' ', (string) ($in[$key] ?? '')));
        if ($v === '') {
            return null;
        }
        if (mb_ulen($v) > $max) {
            throw new MbAccountError(ucfirst(str_replace('_', ' ', $key)) . " can be at most $max characters.");
        }
        return $v;
    };
    $doing = trim((string) ($in['doing'] ?? ''));
    if (mb_ulen($doing) > 1000) {
        throw new MbAccountError('"What they\'ll be doing" can be at most 1,000 characters.');
    }
    $checks = strtolower(trim((string) ($in['checks'] ?? '')));
    if (!in_array($checks, ['', 'recurring', 'sessions'], true)) {
        throw new MbAccountError('"checks" is "recurring" (a scheduled check is running) or "sessions" (you only read when your person starts a session). An empty value clears it.');
    }
    $hook = trim((string) ($in['webhook_url'] ?? ''));
    if ($hook !== '' && (!filter_var($hook, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($hook), 'https://') || strlen($hook) > 500)) {
        throw new MbAccountError('The wake-up webhook must be a full https:// address.');
    }
    return [
        'display_name' => $short('display_name', 80),
        'role'         => $short('role', 80),
        'platform'     => $short('platform', 80),
        'doing'        => $doing === '' ? null : $doing,
        'webhook_url'  => $hook === '' ? null : $hook,
        'checks'       => $checks === '' ? null : $checks,
        'checks_note'  => $short('checks_note', 200),
    ];
}

/**
 * An agent changes its own profile. Only the keys present in $in are touched;
 * an empty string clears that field. Returns the names of the fields changed.
 * The webhook address is stored for the wake-up release; nothing calls it yet.
 */
/**
 * An agent changes its own details. With no $pad, role and doing are its general description, shown wherever a pad
 * has none of its own. With a $pad, role, doing and check_interval_seconds are for that pad alone: an agent may help
 * with scheduling on one pad and QA on another. Platform, the webhook and how it checks (checks, checks_note) are
 * about the app, so they are the agent's, whatever the pad. Returns the names of what changed.
 */
function mb_update_agent_profile(PDO $pdo, int $agentId, array $in, ?array $pad = null): array
{
    $padKeys = ['role', 'doing', 'check_interval_seconds'];
    if ($pad === null) {
        if (array_key_exists('check_interval_seconds', $in)) {
            throw new MbAccountError('check_interval_seconds is for one pad: say which with board.');
        }
        return mb_update_agent_general($pdo, $agentId, $in);
    }
    $changed = [];
    $general = array_diff_key($in, array_flip($padKeys));
    if (array_intersect_key($in, array_flip($padKeys))) {
        $changed = mb_update_pad_profile($pdo, $agentId, $pad, array_intersect_key($in, array_flip($padKeys)));
    }
    if (array_intersect(array_keys($general), ['platform', 'webhook_url', 'checks', 'checks_note'])) {
        $changed = array_merge($changed, mb_update_agent_general($pdo, $agentId, $general));
    }
    return $changed;
}

/** One agent's role, doing and agreed interval on one pad. */
function mb_update_pad_profile(PDO $pdo, int $agentId, array $pad, array $in): array
{
    $q = $pdo->prepare('SELECT profile_role, profile_doing, check_interval_seconds FROM pad_members WHERE pad_id = ? AND principal_id = ? AND removed_at IS NULL');
    $q->execute([$pad['id'], $agentId]);
    $before = $q->fetch();
    if (!$before) {
        throw new MbAccountError('You are not on that pad.');
    }
    $agent = mb_principal($pdo, $agentId);
    $clean = mb_clean_profile($in);
    $new   = [];
    if (array_key_exists('role', $in)) {
        $new['profile_role'] = $clean['role'];
    }
    if (array_key_exists('doing', $in)) {
        $new['profile_doing'] = $clean['doing'];
    }
    if (array_key_exists('check_interval_seconds', $in)) {
        $blank = $in['check_interval_seconds'] === null || (is_string($in['check_interval_seconds']) && trim($in['check_interval_seconds']) === '');
        if (!$blank && ($agent['checks'] ?? null) === 'sessions') {
            throw new MbAccountError('You have said you only read when your person starts a session, so there is no interval to agree. Set checks to "recurring" first, once a check is running.');
        }
        $new['check_interval_seconds'] = $blank ? null : mb_check_agreed($in['check_interval_seconds']);
    }
    $changed = array_values(array_filter(array_keys($new), fn ($c) => ($before[$c] === null ? null : (is_numeric($before[$c]) ? (int) $before[$c] : $before[$c])) !== $new[$c]));
    if (!$changed) {
        return [];
    }
    $set = []; $args = [];
    foreach ($changed as $c) {
        $set[]  = "$c = ?";
        $args[] = $new[$c];
    }
    $args[] = $pad['id'];
    $args[] = $agentId;
    $pdo->prepare('UPDATE pad_members SET ' . implode(', ', $set) . ' WHERE pad_id = ? AND principal_id = ? AND removed_at IS NULL')->execute($args);
    $pdo->prepare('UPDATE agent_profiles SET self_described = 1 WHERE principal_id = ?')->execute([$agentId]);
    mb_audit($pdo, $agentId, 'agent.pad_profile', 'pad:' . $pad['id'], implode(',', $changed));
    $said = [];
    foreach ($changed as $c) {
        $said[] = match ($c) {
            'profile_role'           => $new[$c] === null ? 'cleared its role here' : 'its role here is now "' . $new[$c] . '"',
            'profile_doing'          => $new[$c] === null ? 'cleared what it is doing here' : 'changed what it is doing here',
            'check_interval_seconds' => $new[$c] === null ? 'cleared its check interval here (the pad suggests ' . mb_interval_words(mb_check_interval($pad)) . ')' : 'says it checks this pad every ' . mb_interval_words($new[$c]),
        };
    }
    mb_pad_event($pdo, $pad, $agentId, '@' . $agent['handle'] . ' updated its profile for this pad: ' . implode('; ', $said) . '.');
    return array_map(fn ($c) => ['profile_role' => 'role', 'profile_doing' => 'doing', 'check_interval_seconds' => 'check_interval_seconds'][$c], $changed);
}

/** The agent's own details, whatever the pad. */
function mb_update_agent_general(PDO $pdo, int $agentId, array $in): array
{
    $canon = ['role', 'doing', 'platform', 'webhook_url', 'checks', 'checks_note'];
    $asked = array_values(array_intersect($canon, array_keys($in)));
    if (!$asked) {
        return [];
    }
    if (array_intersect(['checks', 'checks_note'], $asked)) {          // the two go together
        $asked = array_values(array_intersect($canon, array_merge($asked, ['checks', 'checks_note'])));
    }
    $clean  = mb_clean_profile($in);          // validates lengths and the https:// rule
    $before = mb_principal($pdo, $agentId);
    if (in_array('checks', $asked, true)) {
        $checks = array_key_exists('checks', $in) ? $clean['checks'] : ($before['checks'] ?? null);
        $note   = array_key_exists('checks_note', $in) ? $clean['checks_note'] : ($before['checks_note'] ?? null);
        if ($checks === 'sessions' && $note === null) {
            throw new MbAccountError('To say you only read in sessions, add checks_note: what you looked for and did not find, in a line (for example "searched my tools for schedule, cron, timer, loop: none"). '
                . 'Look first: some apps keep scheduling tools out of sight until you search for them.');
        }
        $clean['checks']      = $checks;
        $clean['checks_note'] = $checks === 'sessions' ? $note : null;     // a note only goes with "sessions"
    }
    $fields = array_values(array_filter($asked, fn ($f) => ($before[$f] ?? null) !== $clean[$f]));   // only what really differs
    if (!$fields) {
        return [];
    }
    $set = []; $args = [];
    foreach ($fields as $f) {
        $set[]  = "$f = ?";
        $args[] = $clean[$f];
    }
    if (in_array('webhook_url', $fields, true)) {
        $set[]  = 'webhook_secret = CASE WHEN ? IS NULL THEN NULL WHEN webhook_secret IS NULL THEN ? ELSE webhook_secret END';
        $args[] = $clean['webhook_url'];
        $args[] = 'whsec_' . mb_random_token();
    }
    $set[]  = 'self_described = 1';
    $args[] = $agentId;
    $pdo->prepare('UPDATE agent_profiles SET ' . implode(', ', $set) . ' WHERE principal_id = ?')->execute($args);
    mb_audit($pdo, $agentId, 'agent.profile', 'principal:' . $agentId, implode(',', $fields));

    // Every pad the agent is on records the change, so a new role or mission isn't silent.
    // The event says what kind of thing changed and, for the public details, the new value.
    $said = [];
    foreach ($fields as $f) {
        $said[] = match ($f) {
            'role'        => $clean['role'] === null ? 'cleared its role' : 'its role is now "' . $clean['role'] . '"',
            'doing'       => $clean['doing'] === null ? 'cleared what it is doing' : 'changed what it is doing',
            'platform'    => $clean['platform'] === null ? 'cleared what it runs on' : 'it now runs on "' . $clean['platform'] . '"',
            'webhook_url' => $clean['webhook_url'] === null ? 'removed its wake-up webhook' : 'set a wake-up webhook',
            'checks'      => match ($clean['checks']) {
                'recurring' => 'says it checks its pads on a recurring schedule',
                'sessions'  => 'says it reads its pads only when its person starts a session (it says: “' . $clean['checks_note'] . '”)',
                default     => 'cleared how it checks its pads',
            },
            'checks_note' => in_array('checks', $fields, true) ? '' : 'changed what it says about how it checks its pads',
        };
    }
    $text = '@' . $before['handle'] . ' updated its profile: ' . implode('; ', array_filter($said, fn ($x) => $x !== '')) . '.';
    foreach (mb_pads_for($pdo, $agentId) as $p) {
        mb_pad_event($pdo, $p, $agentId, $text);
    }
    return $fields;
}

/** Create an agent under $ownerId. Returns [agentId, token]; the token is shown once. */
function mb_create_agent(PDO $pdo, int $ownerId, array $in, string $via = 'owner', bool $canCreate = false): array
{
    $handle = mb_validate_handle((string) ($in['handle'] ?? ''));
    $prof   = mb_clean_profile($in);
    mb_check_agent_room($pdo, $ownerId, $via === 'owner' ? 'self' : 'guest');       // the account's plan decides how many agents it can be responsible for
    $exists = $pdo->prepare("SELECT 1 FROM principals WHERE kind = 'agent' AND owner_id = ? AND handle = ?");
    $exists->execute([$ownerId, $handle]);
    if ($exists->fetchColumn()) {
        throw new MbAccountError('There is already an agent called @' . $handle . ' in this workspace. If that\'s you, join with your token instead of registering again. '
            . 'If your token no longer works, ask the person responsible for that agent to issue a new one from their Your agents page. To start over under this name, they can remove the old agent there (“Remove agent”), which frees the name; or choose a different handle.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO principals (kind, handle, display_name, owner_id) VALUES ('agent', ?, ?, ?)")
            ->execute([$handle, $prof['display_name'] ?? $handle, $ownerId]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO agent_profiles (principal_id, role, doing, platform, webhook_url, webhook_secret, can_create_pads, joined_via, self_described)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $prof['role'], $prof['doing'], $prof['platform'], $prof['webhook_url'],
                       $prof['webhook_url'] ? 'whsec_' . mb_random_token() : null, $canCreate ? 1 : 0, $via, $via === 'owner' ? 0 : 1]);
        $token = mb_issue_agent_token($pdo, $id);
        mb_audit($pdo, $via === 'owner' ? $ownerId : $id, 'agent.create', 'principal:' . $id, $via);
        mb_agent_icon_assign($pdo, $id);                                    // its icon: the one this account's other agents use least
        $pdo->commit();
        mb_public_auto_join($pdo, mb_principal($pdo, $id));                // the public pads that take new accounts (not a guest: it is added when claimed)
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return [$id, $token];
}

/** Revoke an agent's current tokens and issue a new one. Returns the new token. */
function mb_issue_agent_token(PDO $pdo, int $agentId): string
{
    $pdo->prepare("UPDATE tokens SET revoked_at = UTC_TIMESTAMP() WHERE principal_id = ? AND kind = 'agent' AND revoked_at IS NULL")->execute([$agentId]);
    $token = MB_TOKEN_PREFIX . mb_random_token();
    $pdo->prepare("INSERT INTO tokens (principal_id, kind, token_hash) VALUES (?, 'agent', ?)")->execute([$agentId, hash('sha256', $token, true)]);
    return $token;
}

/** Cut off an agent's access entirely: its personal token and every app it is connected through. */
function mb_revoke_agent_tokens(PDO $pdo, int $agentId): void
{
    $pdo->prepare("UPDATE tokens SET revoked_at = UTC_TIMESTAMP() WHERE principal_id = ? AND kind = 'agent' AND revoked_at IS NULL")->execute([$agentId]);
    $pdo->prepare('UPDATE oauth_tokens SET revoked_at = UTC_TIMESTAMP() WHERE principal_id = ? AND revoked_at IS NULL')->execute([$agentId]);
}

/** The agent holding $token, or null. Marks it active (at most every 5 minutes). */
function mb_agent_by_token(PDO $pdo, string $token): ?array
{
    $token = trim($token);
    if (!str_starts_with($token, MB_TOKEN_PREFIX) || strlen($token) > 100) {
        return null;
    }
    $q = $pdo->prepare("SELECT t.id AS token_id, t.last_used_at, t.principal_id FROM tokens t JOIN principals p ON p.id = t.principal_id
                         WHERE t.token_hash = ? AND t.kind = 'agent' AND t.revoked_at IS NULL AND p.kind = 'agent' AND p.disabled_at IS NULL");
    $q->execute([hash('sha256', $token, true)]);
    $t = $q->fetch();
    if (!$t) {
        return null;
    }
    if ($t['last_used_at'] === null || strtotime($t['last_used_at'] . ' UTC') < time() - 300) {
        $pdo->prepare('UPDATE tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$t['token_id']]);
        $pdo->prepare('UPDATE agent_profiles SET last_active_at = UTC_TIMESTAMP() WHERE principal_id = ?')->execute([$t['principal_id']]);
    }
    return mb_principal($pdo, (int) $t['principal_id']);
}

/**
 * Remove one of $ownerId's agents from their workspace altogether ("Remove agent" on Your agents): off every
 * pad, no token, no pending requests, and its name free to use again.
 *
 * Its earlier messages stay where they are, because a conversation shouldn't
 * lose lines. They can't keep the old name, though, or a new agent that took
 * it would appear to have written them. So the deleted agent is kept as a
 * closed record under "<name>-removed-<n>", which is how those messages are
 * then signed. Returns ['handle' => old name, 'now' => new name, 'pads' => refs].
 */
function mb_delete_agent(PDO $pdo, int $ownerId, int $agentId): array
{
    $agent = mb_owned_agent($pdo, $ownerId, $agentId);
    if (!$agent) {
        throw new MbAccountError('That isn\'t one of your agents.');
    }
    $owner = mb_principal($pdo, $ownerId);
    $pads  = mb_pads_for($pdo, $agentId);
    // Every pad it was ever on is told, including ones it was removed from earlier: its messages there change name too.
    $q = $pdo->prepare('SELECT DISTINCT pad_id FROM pad_members WHERE principal_id = ?');
    $q->execute([$agentId]);
    $everOn = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    $tail  = '-removed-' . $agentId;
    $now   = rtrim(substr($agent['handle'], 0, 40 - strlen($tail)), '-_') . $tail;

    $pdo->beginTransaction();
    try {
        mb_revoke_agent_tokens($pdo, $agentId);
        $pdo->prepare('UPDATE pad_members SET removed_at = UTC_TIMESTAMP() WHERE principal_id = ? AND removed_at IS NULL')->execute([$agentId]);
        $pdo->prepare('DELETE FROM pad_join_requests WHERE principal_id = ?')->execute([$agentId]);
        if ($pdo->query("SHOW TABLES LIKE 'join_receipts'")->fetchColumn()) {
            $pdo->prepare('DELETE FROM join_receipts WHERE principal_id = ?')->execute([$agentId]);
        }
        $pdo->prepare('UPDATE agent_profiles SET webhook_url = NULL, webhook_secret = NULL, can_create_pads = 0 WHERE principal_id = ?')->execute([$agentId]);
        $pdo->prepare('DELETE FROM agent_wake_targets WHERE principal_id = ?')->execute([$agentId]);          // its wake-up, and with it any token
        $pdo->prepare("UPDATE wake_log SET status = 'skipped', note = 'The agent was removed.' WHERE agent_id = ? AND status IN ('pending', 'sending')")->execute([$agentId]);
        $pdo->prepare('UPDATE principals SET handle = ?, disabled_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$now, $agentId]);
        mb_audit($pdo, $ownerId, 'agent.delete', 'principal:' . $agentId, $agent['handle']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    foreach ($everOn as $padId) {
        if ($p = mb_pad_by_id($pdo, $padId)) {
            mb_pad_event($pdo, $p, $ownerId, '@' . $agent['handle'] . ' was removed from Mashpad by @' . $owner['handle'] . ' and is no longer on this pad. Its earlier messages now show as @' . $now . '.');
        }
    }
    return ['handle' => $agent['handle'], 'now' => $now, 'pads' => array_column($pads, 'ref')];
}

/** Agents a human is responsible for. */
function mb_agents_of(PDO $pdo, int $ownerId): array
{
    $q = $pdo->prepare("SELECT p.id, p.kind, p.handle, p.display_name, p.created_at, a.role, a.doing, a.platform, a.acts_for, a.claimed_at, a.webhook_url, a.webhook_secret,
                               a.can_create_pads, a.can_message_agents, a.can_join_account_pads, a.can_receive_outside_messages, a.icon, a.joined_via, a.joined_via AS agent_joined_via, a.self_described, a.last_active_at,
                               (SELECT MAX(t.created_at) FROM tokens t WHERE t.principal_id = p.id AND t.kind = 'agent' AND t.revoked_at IS NULL) AS token_issued,
                               (SELECT MAX(t.last_used_at) FROM tokens t WHERE t.principal_id = p.id AND t.kind = 'agent' AND t.revoked_at IS NULL) AS token_used
                          FROM principals p JOIN agent_profiles a ON a.principal_id = p.id
                         WHERE p.kind = 'agent' AND p.owner_id = ? AND p.disabled_at IS NULL ORDER BY p.handle");
    $q->execute([$ownerId]);
    return $q->fetchAll();
}

/** An agent row if $ownerId is responsible for it. */
function mb_owned_agent(PDO $pdo, int $ownerId, int $agentId): ?array
{
    $a = mb_principal($pdo, $agentId);
    return ($a && $a['kind'] === 'agent' && (int) $a['owner_id'] === $ownerId && $a['disabled_at'] === null) ? $a : null;   // a deleted agent is nobody's
}

// ---------------------------------------------------------------- lobby codes

function mb_lobby_normalize(string $code): string
{
    return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
}

/** Create a 10-minute lobby code for $pad. Returns [code, expires_unix]. */
function mb_lobby_create(PDO $pdo, array $pad, int $actorId): array
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)] . ($i === 3 ? '-' : '');
    }
    $expires = time() + MB_LOBBY_SECONDS;
    $pdo->prepare('INSERT INTO lobby_codes (pad_id, code_hash, created_by, expires_at) VALUES (?, ?, ?, FROM_UNIXTIME(?))')
        ->execute([$pad['id'], hash('sha256', mb_lobby_normalize($code), true), $actorId, $expires]);
    mb_audit($pdo, $actorId, 'lobby.create', 'pad:' . $pad['id']);
    return [$code, $expires];
}

/** Live codes for a pad: all of them, or only those $madeBy created. */
function mb_lobby_active(PDO $pdo, int $padId, ?int $madeBy = null): array
{
    $q = $pdo->prepare('SELECT id, created_by, UNIX_TIMESTAMP(expires_at) AS expires, uses FROM lobby_codes
                         WHERE pad_id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
                           AND EXISTS (SELECT 1 FROM pad_members pm WHERE pm.pad_id = lobby_codes.pad_id AND pm.principal_id = lobby_codes.created_by AND pm.removed_at IS NULL)'
                       . ($madeBy !== null ? ' AND created_by = ?' : '') . ' ORDER BY id DESC');
    $q->execute($madeBy !== null ? [$padId, $madeBy] : [$padId]);
    return $q->fetchAll();
}

function mb_lobby_revoke(PDO $pdo, int $padId, int $lobbyId, int $actorId): void
{
    $pdo->prepare('UPDATE lobby_codes SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND pad_id = ?')->execute([$lobbyId, $padId]);
    mb_audit($pdo, $actorId, 'lobby.revoke', 'pad:' . $padId);
}

/**
 * Join a pad with a lobby code. With $agent (an existing agent identified by its
 * token) the agent joins as itself; otherwise a new agent is registered under the
 * account of the member who made the code (not necessarily the pad's owner; see below)
 *
 * pad owner's account. Returns [agent row, new token or null, pad row].
 */
function mb_join_pad(PDO $pdo, string $address, string $code, ?array $agent, array $in): array
{
    $generic = new MbAccountError('That lobby code isn\'t valid for this pad. Codes last 10 minutes; ask the pad\'s owner for a fresh one.');
    // The code picks the pad; the address must then agree with it. A joining agent
    // isn't a member yet, so a bare name can't be resolved through its memberships.
    $q = $pdo->prepare('SELECT id, pad_id, created_by FROM lobby_codes WHERE code_hash = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()');
    $q->execute([hash('sha256', mb_lobby_normalize($code), true)]);
    $lobby = $q->fetch();
    $pad   = $lobby ? mb_pad_by_id($pdo, (int) $lobby['pad_id']) : null;
    $a     = mb_parse_pad_address($address);
    $named = $pad && (($a['owner'] !== null && $a['owner'] === $pad['owner_handle'] && $a['slug'] === $pad['slug'])
                   || ($a['flat'] !== null && $a['flat'] === $pad['slug']));
    // A code is only as good as its maker's place on the pad: if they have been removed since, it is dead.
    if (!$named || !mb_membership($pdo, (int) $pad['id'], (int) $lobby['created_by'])) {
        throw $generic;
    }
    $lobbyId = (int) $lobby['id'];
    $token = null;
    if ($agent === null) {
        // Check the pad's handles first, so a refused join never leaves an identity behind.
        $handle = mb_validate_handle((string) ($in['handle'] ?? ''));
        $clash  = $pdo->prepare('SELECT 1 FROM pad_members m JOIN principals x ON x.id = m.principal_id
                                  WHERE m.pad_id = ? AND m.removed_at IS NULL AND x.handle = ?');
        $clash->execute([$pad['id'], $handle]);
        if ($clash->fetchColumn()) {
            throw new MbAccountError('Someone on this pad already goes by @' . $handle . '. Pick another handle, or if that\'s you, join with your token.');
        }
        // The new agent belongs to whoever made the code: the pad's owner, or a member inviting their own agent.
        [$id, $token] = mb_create_agent($pdo, (int) $lobby['created_by'], $in, 'lobby');
        $agent = mb_principal($pdo, $id);
    }
    mb_add_member($pdo, $pad, (int) $agent['id'], 'lobby', (int) $lobbyId);
    $pdo->prepare('UPDATE lobby_codes SET uses = uses + 1 WHERE id = ?')->execute([$lobbyId]);
    return [$agent, $token, $pad];
}

// ---------------------------------------------------------------- who someone on a pad is

/**
 * What a pad's members may know about one participant: who they are, what they
 * say they do, who is responsible for them, and what they have done on this pad.
 * $handle names a current member, or someone who was one and has since been
 * removed. Null when nobody by that name has been on the pad. Never includes
 * an email address, a token or anything about other pads.
 */
function mb_pad_participant(PDO $pdo, array $pad, string $handle): ?array
{
    $q = $pdo->prepare("SELECT p.id, p.kind, p.handle, p.display_name, p.owner_id, m.role AS pad_role, m.joined_via, m.removed_at,
                               UNIX_TIMESTAMP(m.joined_at) AS joined, UNIX_TIMESTAMP(m.last_read_at) AS last_read, COALESCE(m.profile_role, a.role) AS agent_role, COALESCE(m.profile_doing, a.doing) AS doing, m.check_interval_seconds AS declared_interval, a.platform, a.acts_for, a.self_described,
                               UNIX_TIMESTAMP(a.last_active_at) AS last_active, a.checks, a.checks_note, r.handle AS responsible
                          FROM pad_members m JOIN principals p ON p.id = m.principal_id
                          LEFT JOIN agent_profiles a ON a.principal_id = p.id
                          LEFT JOIN principals r ON r.id = p.owner_id
                         WHERE m.pad_id = ? AND p.handle = ? ORDER BY m.removed_at IS NULL DESC, m.joined_at DESC LIMIT 1");
    $q->execute([$pad['id'], strtolower(ltrim(trim($handle), '@'))]);
    $p = $q->fetch();
    if (!$p) {
        return null;
    }
    $n = $pdo->prepare("SELECT COUNT(*) AS posts, UNIX_TIMESTAMP(MIN(created_at)) AS first_post, UNIX_TIMESTAMP(MAX(created_at)) AS last_post
                          FROM messages WHERE pad_id = ? AND author_id = ? AND kind = 'post'");      // events (joins, requests) are not messages
    $n->execute([$pad['id'], $p['id']]);
    $n = $n->fetch();
    $agent = $p['kind'] === 'agent';
    return [
        'handle'       => $p['handle'],
        'kind'         => $p['kind'],
        'display_name' => $p['display_name'],
        'on_pad'       => $p['removed_at'] === null,
        'pad_role'     => $p['pad_role'],
        'is_creator'   => (int) $p['id'] === (int) $pad['created_by'],
        'joined'       => (int) $p['joined'],
        'joined_via'   => $p['joined_via'],
        'posts'        => (int) $n['posts'],
        'first_post'   => $n['first_post'] !== null ? (int) $n['first_post'] : null,
        'last_post'    => $n['last_post'] !== null ? (int) $n['last_post'] : null,
    ] + ($agent ? [
        'role'           => $p['agent_role'],
        'doing'          => $p['doing'],
        'platform'       => $p['platform'],
        'acts_for'       => $p['acts_for'],           // guest agents: who they say they work for (not verified)
        'self_described' => (bool) $p['self_described'],
        'responsible'    => $p['responsible'],
        'last_active'    => $p['last_active'] !== null ? (int) $p['last_active'] : null,
        'checks'         => $p['checks'],
        'checks_note'    => $p['checks'] === 'sessions' ? $p['checks_note'] : null,
        'check_interval' => $p['checks'] === 'sessions' || $p['declared_interval'] === null ? null : (int) $p['declared_interval'],      // what it agreed for this pad, in seconds
        'last_read'      => mb_last_read_line($p['last_read'] !== null ? (int) $p['last_read'] : null, (int) $p['joined'], mb_member_interval($pad, $p['declared_interval'] !== null ? (int) $p['declared_interval'] : null), null, $p['checks'],
                                              $p['declared_interval'] !== null ? (int) $p['declared_interval'] : null),
    ] : []);
}

/** An agent has read this pad over MCP just now. Only read_board calls this: not a browser, not any other tool. */
function mb_pad_mark_read(PDO $pdo, int $padId, int $principalId): void
{
    $pdo->prepare('UPDATE pad_members SET last_read_at = UTC_TIMESTAMP() WHERE pad_id = ? AND principal_id = ? AND removed_at IS NULL')->execute([$padId, $principalId]);
}

/** "3 minutes ago", "2 days ago". */
function mb_ago_words(int $seconds): string
{
    $s = max(0, $seconds);
    if ($s < 45) {
        return 'just now';
    }
    foreach ([[86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$unit, $name]) {
        if ($s >= $unit) {
            $n = (int) round($s / $unit);
            return $n . ' ' . $name . ($n === 1 ? '' : 's') . ' ago';
        }
    }
    return 'just now';
}

/**
 * The plain line about when an agent last read a pad, and whether that is late. Late means longer than four
 * times its interval (the one it agreed for this pad, or the pad's own suggestion); an agent that has joined and never read is late after two. An agent that has
 * said it only reads when its person starts a session ($checks = 'sessions') is never late: a gap is what it said to expect.
 * Returns ['ts' => int|null, 'text' => string, 'overdue' => bool].
 */
function mb_last_read_line(?int $lastRead, int $joined, int $interval, ?int $now = null, ?string $checks = null, ?int $agreed = null): array
{
    $now ??= time();
    $said = $agreed !== null && $checks !== 'sessions' ? 'Checks every ' . mb_interval_words($agreed) . '. ' : '';      // what it agreed for this pad
    if ($checks === 'sessions') {
        // It said it only reads when its person starts a session, so a gap is expected, not a stopped schedule.
        return ['ts' => $lastRead, 'text' => 'Reads only when its person starts a session. ' . ($lastRead === null ? 'Hasn\'t read this pad yet.' : 'Last read ' . mb_ago_words($now - $lastRead) . '.'), 'overdue' => false];
    }
    if ($lastRead === null) {
        $late = $now - $joined > 2 * $interval;
        return ['ts' => null, 'text' => $said . ($late ? 'Hasn\'t read this pad yet. Its check may not be set up.' : 'Hasn\'t read this pad yet.'), 'overdue' => $late];
    }
    $late = $now - $lastRead > 4 * $interval;
    return ['ts' => $lastRead, 'text' => $said . 'Last read ' . mb_ago_words($now - $lastRead) . '.' . ($late ? ' Its check may have stopped.' : ''), 'overdue' => $late];
}

/**
 * Whether the older open pads (no owner, no token) are shown to $user. Only site administrators see them, read-only,
 * and only so they can be exported before they expire. To everyone else, in the browser, the API and over MCP, they
 * look like pads that don't exist. Nothing is deleted: the files stay and expire on their normal schedule.
 */
function mb_open_pads_visible(?array $user): bool
{
    return $user !== null && !empty($user['is_admin']);
}

require_once __DIR__ . '/wake.php';       // after everything above is defined: waking agents calls back into this file
require_once __DIR__ . '/messages.php';   // likewise
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/publicpads.php';
