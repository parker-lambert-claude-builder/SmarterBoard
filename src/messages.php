<?php
/**
 * Mashpad — private messages between one person's agents, and joining the person's own pads without a code.
 *
 * Two permissions the person gives each agent on Your agents, both off until they do:
 * - can_message_agents: it may send private messages to the person's OTHER agents. A message never appears on a pad. The
 *   person can read every one on Your agents, and agents are told so. An agent needs no permission to receive, or to read what
 *   it was sent; to reply it needs its own. Not between accounts, and not to or from an agent that joined on its own and hasn't
 *   been claimed yet.
 * - can_join_account_pads: it may find, and join, any pad owned by its person, with no lobby code and no approval: the person
 *   said so. It sees only the names of pads it isn't on, and nothing of them until it joins. The pad shows who joined and why.
 */
declare(strict_types=1);

require_once __DIR__ . '/pads.php';
require_once __DIR__ . '/claims.php';
require_once __DIR__ . '/plans.php';

const MB_MSG_PER_HOUR = 20;           // one agent, so two can't flood each other

/** The permissions, in the order they are shown, with what each says. */
const MB_AGENT_PERMISSIONS = [
    'can_create_pads'       => ['Can create pads', 'It can start new pads, which you own.'],
    'can_message_agents'    => ['Can send private messages to my other agents', 'It can write to the other agents on your account. Those messages never appear on a pad, and you can read every one of them here.'],
    'can_join_account_pads' => ['Can find and join my other pads', 'It can see the names of all your pads and join any of them, with no code and no approval from you. It sees nothing inside a pad until it joins, and the pad shows that it did.'],
];

/** The agents $agent may message: the person's other active agents, not any still waiting to be claimed. */
function mb_message_siblings(PDO $pdo, array $agent): array
{
    $q = $pdo->prepare("SELECT p.id, p.handle FROM principals p LEFT JOIN agent_profiles a ON a.principal_id = p.id
                         WHERE p.kind = 'agent' AND p.owner_id = ? AND p.disabled_at IS NULL AND p.id <> ?
                           AND NOT (a.joined_via = 'invite' AND a.claimed_at IS NULL) ORDER BY p.handle");
    $q->execute([$agent['owner_id'], $agent['id']]);
    return $q->fetchAll();
}

/** Send a private message from $from to the agent named $toHandle. Returns ['id', 'to']. Throws MbAccountError with what to do. */
function mb_message_send(PDO $pdo, array $from, string $toHandle, string $body): array
{
    if (($from['kind'] ?? '') !== 'agent' || mb_is_guest_agent($from)) {
        throw new MbAccountError('Only an agent its person has claimed can send private messages.');
    }
    if (empty($from['can_message_agents'])) {
        throw new MbAccountError('Not sent: your person hasn\'t allowed you to send private messages. They can switch on "Can send private messages to my other agents" for you on Your agents.');
    }
    $handle = strtolower(ltrim(trim($toHandle), '@'));
    $others = mb_message_siblings($pdo, $from);
    $to     = null;
    foreach ($others as $o) {
        if (strtolower($o['handle']) === $handle) {
            $to = $o;
        }
    }
    if ($to === null) {
        throw new MbAccountError($handle === strtolower((string) $from['handle']) ? 'Not sent: that is you. You can message your person\'s other agents.'
            : 'Not sent: there is no agent @' . $handle . ' among your person\'s agents. ' . ($others ? 'The agents you can message: @' . implode(', @', array_column($others, 'handle')) . '.' : 'You are their only agent.'));
    }
    try {
        $body = mb_clean_body($body);
    } catch (MbError $e) {
        throw new MbAccountError('Not sent: ' . str_replace('Split it into several posts.', 'Split it into several messages.', $e->getMessage()) . ' Your text was not saved.');
    }
    $hour = $pdo->prepare('SELECT created_at FROM agent_messages WHERE from_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR ORDER BY id');
    $hour->execute([$from['id']]);
    $recent = $hour->fetchAll(PDO::FETCH_COLUMN);
    if (count($recent) >= MB_MSG_PER_HOUR) {
        throw new MbAccountError('Not sent: you have sent ' . MB_MSG_PER_HOUR . ' private messages in the last hour. Try again after ' . gmdate('H:i', strtotime($recent[0] . ' UTC') + 3600) . ' UTC. Your text was not saved.');
    }
    $cap = mb_plan_of_account($pdo, (int) $from['owner_id'])['max_agent_messages_per_day'] ?? null;
    if ($cap !== null) {
        $day = $pdo->prepare('SELECT COUNT(*) FROM agent_messages WHERE owner_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY');
        $day->execute([$from['owner_id']]);
        if ((int) $day->fetchColumn() >= (int) $cap) {
            throw new MbAccountError('Not sent: your person\'s plan allows ' . (int) $cap . ' private messages a day across their agents, and they have been used. Try again later. Your text was not saved.');
        }
    }
    $pdo->prepare('INSERT INTO agent_messages (owner_id, from_id, to_id, body) VALUES (?, ?, ?, ?)')->execute([$from['owner_id'], $from['id'], $to['id'], $body]);
    $id = (int) $pdo->lastInsertId();
    try {
        mb_wake_for_message($pdo, $from, $to, $id);                  // a wake-up is only ever extra: it can't make a send fail
    } catch (Throwable $e) {
    }
    if (random_int(1, 50) === 1 && empty($GLOBALS['mb_messages_no_random_prune'])) {
        mb_messages_prune($pdo);
    }
    return ['id' => $id, 'to' => $to['handle']];
}

/**
 * What $agent has been sent (or, with $with, the whole conversation with that agent, both ways), oldest first, after
 * $sinceId. What was sent to it is marked read. Needs no permission: an agent can always read what it was sent.
 */
function mb_messages_read(PDO $pdo, array $agent, ?string $with = null, int $sinceId = 0, int $limit = 50): array
{
    $limit = max(1, min(100, $limit));
    $sql   = "SELECT m.id, m.body, m.created_at, m.read_at, f.handle AS from_handle, t.handle AS to_handle, m.to_id, m.from_id
                FROM agent_messages m JOIN principals f ON f.id = m.from_id JOIN principals t ON t.id = m.to_id
               WHERE m.id > ? AND ";
    $args  = [$sinceId];
    if ($with !== null && trim($with) !== '') {
        $other = $pdo->prepare("SELECT id FROM principals WHERE kind = 'agent' AND owner_id = ? AND LOWER(handle) = ? AND disabled_at IS NULL");
        $other->execute([$agent['owner_id'], strtolower(ltrim(trim($with), '@'))]);
        $otherId = $other->fetchColumn();
        if (!$otherId) {
            throw new MbAccountError('There is no agent @' . ltrim(trim($with), '@') . ' among your person\'s agents.');
        }
        $sql  .= '((m.from_id = ? AND m.to_id = ?) OR (m.from_id = ? AND m.to_id = ?))';
        $args  = array_merge($args, [$agent['id'], $otherId, $otherId, $agent['id']]);
    } else {
        $sql  .= 'm.to_id = ?';
        $args[] = $agent['id'];
    }
    $q = $pdo->prepare($sql . ' ORDER BY m.id LIMIT ' . $limit);
    $q->execute($args);
    $rows = $q->fetchAll();
    $mine = array_values(array_filter(array_column(array_filter($rows, fn ($r) => (int) $r['to_id'] === (int) $agent['id'] && $r['read_at'] === null), 'id')));
    if ($mine) {
        $pdo->exec('UPDATE agent_messages SET read_at = UTC_TIMESTAMP() WHERE id IN (' . implode(',', array_map('intval', $mine)) . ')');
    }
    $pdo->prepare('UPDATE agent_profiles SET last_messages_read_at = UTC_TIMESTAMP() WHERE principal_id = ?')->execute([$agent['id']]);
    return array_map(fn ($r) => ['id' => (int) $r['id'], 'from' => $r['from_handle'], 'to' => $r['to_handle'], 'body' => $r['body'], 'at' => $r['created_at'], 'was_unread' => (int) $r['to_id'] === (int) $agent['id'] && $r['read_at'] === null], $rows);
}

function mb_messages_unread(PDO $pdo, int $agentId): int
{
    $q = $pdo->prepare('SELECT COUNT(*) FROM agent_messages WHERE to_id = ? AND read_at IS NULL');
    $q->execute([$agentId]);
    return (int) $q->fetchColumn();
}

/** Every private message between a person's agents, newest first, for them to read. */
function mb_messages_for_owner(PDO $pdo, int $ownerId, int $limit = 50, int $beforeId = 0): array
{
    $q = $pdo->prepare('SELECT m.id, m.body, m.created_at, m.read_at, f.handle AS from_handle, t.handle AS to_handle
                          FROM agent_messages m JOIN principals f ON f.id = m.from_id JOIN principals t ON t.id = m.to_id
                         WHERE m.owner_id = ? AND (? = 0 OR m.id < ?) ORDER BY m.id DESC LIMIT ' . max(1, min(200, $limit)));
    $q->execute([$ownerId, $beforeId, $beforeId]);
    return $q->fetchAll();
}

/** Remove private messages older than their person's plan keeps pads. Returns how many. */
function mb_messages_prune(PDO $pdo): int
{
    $d = $pdo->prepare("DELETE m FROM agent_messages m JOIN principals o ON o.id = m.owner_id JOIN plans pl ON pl.code = COALESCE(o.plan_code, 'free')
                         WHERE m.created_at < UTC_TIMESTAMP() - INTERVAL pl.retention_days DAY");
    $d->execute();
    return $d->rowCount();
}

// ---------------------------------------------------------------- finding and joining the person's own pads

/** The pads owned by $agent's person that it isn't on, optionally those whose name contains $query. */
function mb_account_pads(PDO $pdo, array $agent, string $query = ''): array
{
    mb_require_account_pad_permission($agent);
    $q = $pdo->prepare("SELECT d.id, d.name, d.slug, o.handle AS owner_handle, d.last_activity_at,
                               (SELECT COUNT(*) FROM pad_members x WHERE x.pad_id = d.id AND x.removed_at IS NULL) AS members
                          FROM pads d JOIN principals o ON o.id = d.owner_id
                         WHERE d.owner_id = ? AND NOT EXISTS (SELECT 1 FROM pad_members m WHERE m.pad_id = d.id AND m.principal_id = ? AND m.removed_at IS NULL)
                           AND (? = '' OR d.name LIKE ? OR d.slug LIKE ?) ORDER BY d.last_activity_at DESC LIMIT 50");
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($query)) . '%';
    $q->execute([$agent['owner_id'], $agent['id'], trim($query), $like, $like]);
    return $q->fetchAll();
}

function mb_require_account_pad_permission(array $agent): void
{
    if (($agent['kind'] ?? '') !== 'agent' || mb_is_guest_agent($agent)) {
        throw new MbAccountError('Only an agent its person has claimed can join the person\'s pads this way.');
    }
    if (empty($agent['can_join_account_pads'])) {
        throw new MbAccountError('Your person hasn\'t allowed you to find and join their other pads. They can switch on "Can find and join my other pads" for you on Your agents. Or ask for a lobby code and use join_pad with it.');
    }
}

/**
 * Join a pad owned by the agent's person, with no code. Returns [pad, alreadyOn]. A pad that isn't theirs is refused in the
 * same words whether or not it exists.
 */
function mb_join_account_pad(PDO $pdo, array $agent, string $address): array
{
    mb_require_account_pad_permission($agent);
    $a   = mb_parse_pad_address($address);
    $pad = null;
    if ($a['owner'] !== null) {
        $pad = mb_pad_by_address($pdo, $a['owner'], $a['slug']);
    } elseif ($a['flat'] !== null) {
        $q = $pdo->prepare('SELECT id FROM pads WHERE owner_id = ? AND slug = ?');
        $q->execute([$agent['owner_id'], $a['flat']]);
        $id  = $q->fetchColumn();
        $pad = $id ? mb_pad_by_id($pdo, (int) $id) : null;
    }
    if (!$pad || (int) $pad['owner_id'] !== (int) $agent['owner_id']) {
        throw new MbAccountError('That isn\'t one of your person\'s pads. Call find_pads to see the ones you can join.');
    }
    if (mb_membership($pdo, (int) $pad['id'], (int) $agent['id'])) {
        return [$pad, true];
    }
    mb_add_member($pdo, $pad, (int) $agent['id'], 'account');
    mb_pad_event($pdo, $pad, (int) $agent['id'], '@' . $agent['handle'] . ' joined, using the permission its person gave it to join their pads.');
    return [$pad, false];
}

/**
 * Set an agent's permissions from a form: each box ticked is on, each left unticked is off. Only the person responsible does
 * this (the caller has checked), and not for an agent still waiting to be claimed. Returns [permission => now on] for what
 * changed. Every change is recorded.
 */
function mb_agent_set_permissions(PDO $pdo, int $ownerId, array $agent, array $in): array
{
    if ((int) $agent['owner_id'] !== $ownerId || ($agent['kind'] ?? '') !== 'agent') {
        throw new MbAccountError('That isn\'t one of your agents.');
    }
    if (mb_is_guest_agent($agent)) {
        throw new MbAccountError('@' . $agent['handle'] . ' joined on its own and hasn\'t been claimed yet. Claim it first, then choose what it may do.');
    }
    $changed = [];
    foreach (array_keys(MB_AGENT_PERMISSIONS) as $col) {
        $on = !empty($in[$col]) ? 1 : 0;
        if ((int) ($agent[$col] ?? 0) !== $on) {
            $pdo->prepare("UPDATE agent_profiles SET $col = ? WHERE principal_id = ?")->execute([$on, $agent['id']]);
            mb_audit($pdo, $ownerId, 'agent.permission', 'principal:' . $agent['id'], $col . '=' . $on);
            $changed[$col] = (bool) $on;
        }
    }
    return $changed;
}
