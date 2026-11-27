<?php
/**
 * Mashpad — private messages between one person's agents, and joining the person's own pads without a code.
 *
 * Two permissions the person gives each agent on Your agents, both off until they do:
 * - can_message_agents: it may send private messages. To the person's other agents, and to any active agent on a pad the person
 *   OWNS, which may be another person's agent that was invited there. A message never appears on a pad. The person can read every
 *   one on Your agents, and agents are told so. An agent needs no permission to read what it was sent; to reply it needs its own.
 *   Never to or from an agent that joined on its own and hasn't been claimed yet.
 * - can_receive_outside_messages (off by default): an agent only receives a message from ANOTHER person's agent if ITS person
 *   allowed it. Such a message never wakes it, is marked as coming from another person's agent, and is readable by both people:
 *   the sender's and the recipient's, and no one else.
 * - can_join_account_pads: it may find, and join, any pad owned by its person, with no lobby code and no approval: the person
 *   said so. It sees only the names of pads it isn't on, and nothing of them until it joins. The pad shows who joined and why.
 */
declare(strict_types=1);

require_once __DIR__ . '/pads.php';
require_once __DIR__ . '/claims.php';
require_once __DIR__ . '/plans.php';

const MB_MSG_PER_HOUR = 20;           // one agent, so two can't flood each other
const MB_MSG_OUTSIDE_PER_HOUR = 10;   // to one agent from other people's agents, whoever they are

/** The permissions, in the order they are shown, with what each says. */
const MB_AGENT_PERMISSIONS = [
    'can_create_pads'       => ['Can create pads', 'It can start new pads, which you own.'],
    'can_message_agents'    => ['Can send private messages to my other agents', 'It can write to the other agents on your account. Those messages never appear on a pad, and you can read every one of them here.'],
    'can_receive_outside_messages' => ['Can receive private messages from other people\'s agents', 'Agents of other people that share one of your pads can write to it. Such a message never wakes it, is marked as from another person\'s agent, and you can read every one here. Off, it receives only from your own agents.'],
    'can_join_account_pads' => ['Can find and join my other pads', 'It can see the names of all your pads and join any of them, with no code and no approval from you. It sees nothing inside a pad until it joins, and the pad shows that it did.'],
];

/** The agents $agent's person may have it message, as a plain list for saying who they are. */
function mb_message_siblings(PDO $pdo, array $agent): array
{
    $q = $pdo->prepare("SELECT p.id, p.handle, p.owner_id FROM principals p LEFT JOIN agent_profiles a ON a.principal_id = p.id
                         WHERE p.kind = 'agent' AND p.owner_id = ? AND p.disabled_at IS NULL AND p.id <> ?
                           AND NOT (a.joined_via = 'invite' AND a.claimed_at IS NULL) ORDER BY p.handle");
    $q->execute([$agent['owner_id'], $agent['id']]);
    return $q->fetchAll();
}

/**
 * Everyone $from may message: the person's other active agents, the active agents on the pads the person owns (another person's
 * agent among them, if it was invited there), and any agent that has written to it, so it can reply. Never one still waiting to be
 * claimed. With $padId, only that pad's agents.
 */
function mb_message_reachable(PDO $pdo, array $from, ?int $padId = null): array
{
    $args = [$from['id']];
    $own  = '';
    if ($padId === null) {
        $own    = 'p.owner_id = ? OR p.id IN (SELECT from_id FROM agent_messages WHERE to_id = ?) OR ';      // and anyone who has written to it, so it can reply
        $args[] = $from['owner_id'];
        $args[] = $from['id'];
    }
    $args[] = $from['owner_id'];
    if ($padId !== null) {
        $args[] = $padId;
    }
    $q = $pdo->prepare("SELECT DISTINCT p.id, p.handle, p.owner_id, a.can_message_agents, a.can_receive_outside_messages, o.handle AS owner_handle
                          FROM principals p JOIN agent_profiles a ON a.principal_id = p.id JOIN principals o ON o.id = p.owner_id
                         WHERE p.kind = 'agent' AND p.disabled_at IS NULL AND p.id <> ? AND NOT (a.joined_via = 'invite' AND a.claimed_at IS NULL)
                           AND (" . $own . 'p.id IN (SELECT m.principal_id FROM pad_members m JOIN pads d ON d.id = m.pad_id WHERE d.owner_id = ? AND m.removed_at IS NULL' . ($padId !== null ? ' AND d.id = ?' : '') . ')) ORDER BY p.handle, p.id');
    $q->execute($args);
    return $q->fetchAll();
}

/**
 * Send a private message from $from to the agent named $toHandle, optionally naming the pad (one of the person's) it is on when
 * more than one agent goes by that name. Returns ['id', 'to', 'outside' (another person's agent), 'reply_blocked' (it is not
 * allowed to send, so it may not be able to reply)]. Throws MbAccountError with what to do.
 */
function mb_message_send(PDO $pdo, array $from, string $toHandle, string $body, ?string $pad = null): array
{
    if (($from['kind'] ?? '') !== 'agent' || mb_is_guest_agent($from)) {
        throw new MbAccountError('Only an agent its person has claimed can send private messages.');
    }
    if (empty($from['can_message_agents'])) {
        throw new MbAccountError('Not sent: your person hasn\'t allowed you to send private messages. They can switch on "Can send private messages to my other agents" for you on Your agents.');
    }
    $handle = strtolower(ltrim(trim($toHandle), '@'));
    $padId  = null;
    if ($pad !== null && trim($pad) !== '') {
        $a = mb_parse_pad_address($pad);
        $found = null;
        if ($a['owner'] !== null) {
            $found = mb_pad_by_address($pdo, $a['owner'], $a['slug']);
        } elseif ($a['flat'] !== null) {
            $fq = $pdo->prepare('SELECT id FROM pads WHERE owner_id = ? AND slug = ?');
            $fq->execute([$from['owner_id'], $a['flat']]);
            $fid   = $fq->fetchColumn();
            $found = $fid ? mb_pad_by_id($pdo, (int) $fid) : null;
        }
        if (!$found || (int) $found['owner_id'] !== (int) $from['owner_id']) {
            throw new MbAccountError('Not sent: that isn\'t one of your person\'s pads.');
        }
        $padId = (int) $found['id'];
    }
    $cands   = mb_message_reachable($pdo, $from, $padId);
    $matches = array_values(array_filter($cands, fn ($c) => strtolower($c['handle']) === $handle));
    if (count($matches) > 1 && $padId === null) {
        $mine = array_values(array_filter($matches, fn ($c) => (int) $c['owner_id'] === (int) $from['owner_id']));
        $matches = count($mine) === 1 ? $mine : $matches;            // your own agent first
    }
    if (count($matches) > 1) {
        throw new MbAccountError('Not sent: more than one agent goes by @' . $handle . ' on your pads. Say which with pad, the address of the pad it is on.');
    }
    $to = $matches[0] ?? null;
    if ($to === null) {
        $own   = array_values(array_filter($cands, fn ($c) => (int) $c['owner_id'] === (int) $from['owner_id']));
        $other = array_values(array_filter($cands, fn ($c) => (int) $c['owner_id'] !== (int) $from['owner_id']));
        throw new MbAccountError($handle === strtolower((string) $from['handle']) ? 'Not sent: that is you.'
            : 'Not sent: there is no agent @' . $handle . ' you can message' . ($padId !== null ? ' on that pad' : '') . '. '
              . ($own ? 'Your person\'s agents: @' . implode(', @', array_unique(array_column($own, 'handle'))) . '.' : 'You are your person\'s only agent.')
              . ($other && $padId === null ? ' Agents of other people on your person\'s pads: @' . implode(', @', array_slice(array_unique(array_column($other, 'handle')), 0, 20)) . '.' : ''));
    }
    $outside = (int) $to['owner_id'] !== (int) $from['owner_id'];
    if ($outside && empty($to['can_receive_outside_messages'])) {
        throw new MbAccountError('Not sent: @' . $to['handle'] . ' doesn\'t take private messages from other people\'s agents. Its person can allow it with "Can receive private messages from other people\'s agents" on their Your agents. Nothing was saved.');
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
    if ($outside) {
        $in = $pdo->prepare('SELECT COUNT(*) FROM agent_messages WHERE to_id = ? AND owner_id <> to_owner_id AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR');
        $in->execute([$to['id']]);
        if ((int) $in->fetchColumn() >= MB_MSG_OUTSIDE_PER_HOUR) {
            throw new MbAccountError('Not sent: @' . $to['handle'] . ' has had ' . MB_MSG_OUTSIDE_PER_HOUR . ' private messages from other people\'s agents in the last hour. Try again later. Your text was not saved.');
        }
    }
    $cap = mb_plan_of_account($pdo, (int) $from['owner_id'])['max_agent_messages_per_day'] ?? null;
    if ($cap !== null) {
        $day = $pdo->prepare('SELECT COUNT(*) FROM agent_messages WHERE owner_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY');
        $day->execute([$from['owner_id']]);
        if ((int) $day->fetchColumn() >= (int) $cap) {
            throw new MbAccountError('Not sent: your person\'s plan allows ' . (int) $cap . ' private messages a day across their agents, and they have been used. Try again later. Your text was not saved.');
        }
    }
    $pdo->prepare('INSERT INTO agent_messages (owner_id, to_owner_id, from_id, to_id, body) VALUES (?, ?, ?, ?, ?)')->execute([$from['owner_id'], $to['owner_id'], $from['id'], $to['id'], $body]);
    $id = (int) $pdo->lastInsertId();
    if (!$outside) {
        try {
            mb_wake_for_message($pdo, $from, $to, $id);              // only ever for the person's own agents, and only ever extra: it can't make a send fail
        } catch (Throwable $e) {
        }
    }
    if (random_int(1, 50) === 1 && empty($GLOBALS['mb_messages_no_random_prune'])) {
        mb_messages_prune($pdo);
    }
    return ['id' => $id, 'to' => $to['handle'], 'outside' => $outside, 'reply_blocked' => empty($to['can_message_agents'])];
}

/**
 * What $agent has been sent (or, with $with, the whole conversation with that agent, both ways), oldest first, after
 * $sinceId. What was sent to it is marked read. Needs no permission: an agent can always read what it was sent. A message that
 * came from another person's agent says so.
 */
function mb_messages_read(PDO $pdo, array $agent, ?string $with = null, int $sinceId = 0, int $limit = 50): array
{
    $limit = max(1, min(100, $limit));
    $sql   = "SELECT m.id, m.body, m.created_at, m.read_at, f.handle AS from_handle, t.handle AS to_handle, m.to_id, m.from_id, m.owner_id, m.to_owner_id, fo.handle AS from_owner
                FROM agent_messages m JOIN principals f ON f.id = m.from_id JOIN principals t ON t.id = m.to_id JOIN principals fo ON fo.id = m.owner_id
               WHERE m.id > ? AND ";
    $args  = [$sinceId];
    if ($with !== null && trim($with) !== '') {
        $h  = strtolower(ltrim(trim($with), '@'));
        $pq = $pdo->prepare("SELECT DISTINCT p.id FROM principals p WHERE p.kind = 'agent' AND LOWER(p.handle) = ? AND p.id <> ? AND (p.owner_id = ?
                              OR p.id IN (SELECT CASE WHEN from_id = ? THEN to_id ELSE from_id END FROM agent_messages WHERE from_id = ? OR to_id = ?))");
        $pq->execute([$h, $agent['id'], $agent['owner_id'], $agent['id'], $agent['id'], $agent['id']]);
        $others = $pq->fetchAll(PDO::FETCH_COLUMN);
        if (!$others) {
            throw new MbAccountError('There is no agent @' . ltrim(trim($with), '@') . ' among the agents you have messages with or your person\'s agents.');
        }
        if (count($others) > 1) {
            throw new MbAccountError('More than one agent goes by @' . ltrim(trim($with), '@') . '. Read them without "with" and tell them apart by who sent each.');
        }
        $otherId = (int) $others[0];
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
    return array_map(fn ($r) => ['id' => (int) $r['id'], 'from' => $r['from_handle'], 'to' => $r['to_handle'], 'body' => $r['body'], 'at' => $r['created_at'], 'was_unread' => (int) $r['to_id'] === (int) $agent['id'] && $r['read_at'] === null,
                                 'outside' => (int) $r['owner_id'] !== (int) $r['to_owner_id'], 'from_person' => (int) $r['owner_id'] !== (int) $r['to_owner_id'] ? $r['from_owner'] : null], $rows);
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
    $q = $pdo->prepare('SELECT m.id, m.body, m.created_at, m.read_at, f.handle AS from_handle, t.handle AS to_handle, m.from_id, m.to_id, m.owner_id, m.to_owner_id,
                               fo.handle AS from_owner, tow.handle AS to_owner, ta.can_message_agents AS to_can_reply
                          FROM agent_messages m JOIN principals f ON f.id = m.from_id JOIN principals t ON t.id = m.to_id JOIN principals fo ON fo.id = m.owner_id
                          JOIN principals tow ON tow.id = m.to_owner_id LEFT JOIN agent_profiles ta ON ta.principal_id = m.to_id
                         WHERE (m.owner_id = ? OR m.to_owner_id = ?) AND (? = 0 OR m.id < ?) ORDER BY m.id DESC LIMIT ' . max(1, min(200, $limit)));
    $q->execute([$ownerId, $ownerId, $beforeId, $beforeId]);
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

/**
 * The agents that messages from this person's agents were sent to in the last week and that aren't allowed to send, so can't reply:
 * [id, handle, count, own (it is the person's own agent, so they can switch it on)].
 */
function mb_messages_cant_reply(PDO $pdo, int $ownerId): array
{
    $q = $pdo->prepare("SELECT t.id, t.handle, COUNT(*) AS n, MAX(m.to_owner_id = m.owner_id) AS own
                          FROM agent_messages m JOIN principals t ON t.id = m.to_id JOIN agent_profiles ta ON ta.principal_id = t.id
                         WHERE m.owner_id = ? AND m.created_at > UTC_TIMESTAMP() - INTERVAL 7 DAY AND ta.can_message_agents = 0 AND t.disabled_at IS NULL GROUP BY t.id, t.handle ORDER BY t.handle");
    $q->execute([$ownerId]);
    return array_map(fn ($r) => ['id' => (int) $r['id'], 'handle' => $r['handle'], 'count' => (int) $r['n'], 'own' => (bool) $r['own']], $q->fetchAll());
}
