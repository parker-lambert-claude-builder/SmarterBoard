<?php
/**
 * Mashpad — permanent invite links.
 *
 * A pad's owner can share one link that never expires. Whoever opens it can ask
 * to join that pad:
 * - A person creates an account (or signs in) and joins. They can then bring
 *   their own agents, which belong to their account, not the pad owner's.
 * - An agent can join on its own, without its person having an account. It says
 *   who it acts for, and is registered as a guest under the pad owner, who is
 *   then the person responsible for it on Mashpad.
 * - An agent that already has a Mashpad token joins as itself.
 *
 * Rules:
 * - The link carries a random token, not the pad's address. Until someone has
 *   joined, it reveals only the pad's name and its owner's handle.
 * - By default the owner approves each request ("ask to join"). The owner can
 *   switch approval off, switch the link off, or reset it (the old link dies).
 * - Every request and every join through the link is recorded in the pad as an
 *   event, and the members page shows how each member joined.
 * - Lobby codes are unchanged: still the quick, ten-minute way to add agents.
 * - A new agent that has to wait for approval is given a request code, not a
 *   token. After approval it presents the code and collects its token then.
 *   Many agents run in sessions that can't keep a secret for long; this way
 *   nothing irreplaceable is handed over before the owner has said yes, and a
 *   lost code can be reissued by the owner.
 */
declare(strict_types=1);

require_once __DIR__ . '/pads.php';

const MB_INVITE_TOKEN_CHARS = 32;

/** The pad's invite row, or null if a link was never made. */
function mb_invite(PDO $pdo, int $padId): ?array
{
    $q = $pdo->prepare('SELECT * FROM pad_invites WHERE pad_id = ?');
    $q->execute([$padId]);
    return $q->fetch() ?: null;
}

function mb_invite_new_token(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < MB_INVITE_TOKEN_CHARS; $i++) {
        $t .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $t;
}

/** Create the pad's link if it has none, or replace its token ($reset; on or off, it stays as it was). Returns the row. */
function mb_invite_make(PDO $pdo, array $pad, int $actorId, bool $reset = false): array
{
    $existing = mb_invite($pdo, (int) $pad['id']);
    if ($existing && !$reset) {
        return $existing;
    }
    $token = mb_invite_new_token();
    // A reset only replaces the token. A link that was switched off stays off until its owner switches it on.
    $pdo->prepare('INSERT INTO pad_invites (pad_id, token, created_by) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE token = VALUES(token)')->execute([$pad['id'], $token, $actorId]);
    mb_audit($pdo, $actorId, $existing ? 'invite.reset' : 'invite.create', 'pad:' . $pad['id']);
    return mb_invite($pdo, (int) $pad['id']);
}

/** Switch the link on or off, or approval on or off. Null leaves a setting alone. */
function mb_invite_set(PDO $pdo, array $pad, int $actorId, ?bool $enabled, ?bool $approval): void
{
    if ($enabled !== null) {
        $pdo->prepare('UPDATE pad_invites SET enabled = ? WHERE pad_id = ?')->execute([$enabled ? 1 : 0, $pad['id']]);
        mb_audit($pdo, $actorId, 'invite.' . ($enabled ? 'enable' : 'disable'), 'pad:' . $pad['id']);
    }
    if ($approval !== null) {
        $pdo->prepare('UPDATE pad_invites SET require_approval = ? WHERE pad_id = ?')->execute([$approval ? 1 : 0, $pad['id']]);
        mb_audit($pdo, $actorId, 'invite.approval', 'pad:' . $pad['id'], $approval ? 'on' : 'off');
    }
}

function mb_invite_url(array $invite): string
{
    return mb_site_url() . 'join/' . $invite['token'];
}

/** The invite token in $text if it is an invite link (…/join/<token>), else null. */
function mb_invite_token_from(string $text): ?string
{
    return preg_match('~/join/([A-Za-z0-9]{' . MB_INVITE_TOKEN_CHARS . '})/?(?:[?#].*)?$~', trim($text), $m) ? $m[1] : null;
}

/** [invite row, pad row] for a live link, or null (unknown, reset or switched off: all the same answer). */
function mb_invite_by_token(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9]{' . MB_INVITE_TOKEN_CHARS . '}$/', $token)) {
        return null;
    }
    $q = $pdo->prepare('SELECT * FROM pad_invites WHERE token = ? AND enabled = 1');
    $q->execute([$token]);
    $invite = $q->fetch();
    $pad    = $invite && hash_equals($invite['token'], $token) ? mb_pad_by_id($pdo, (int) $invite['pad_id']) : null;
    return $pad ? [$invite, $pad] : null;
}

/** "@sam" for a person, "@helper (an agent acting for Sam)" for a guest agent. */
function mb_invite_who(array $p): string
{
    $who = '@' . $p['handle'];
    if ($p['kind'] === 'agent') {
        $who .= !empty($p['acts_for']) ? ' (an agent acting for ' . $p['acts_for'] . ')' : ' (an agent)';
    }
    return $who;
}

/**
 * $principal uses the link. Returns 'member' (already on the pad), 'joined'
 * (admitted now) or 'requested' (waiting for the owner).
 */
function mb_invite_use(PDO $pdo, array $invite, array $pad, array $principal): string
{
    $id = (int) $principal['id'];
    if (mb_membership($pdo, (int) $pad['id'], $id)) {
        return 'member';
    }
    if ((int) $invite['require_approval']) {
        $q = $pdo->prepare('INSERT IGNORE INTO pad_join_requests (pad_id, principal_id) VALUES (?, ?)');
        $q->execute([$pad['id'], $id]);
        if ($q->rowCount()) {
            mb_pad_event($pdo, $pad, $id, mb_invite_who($principal) . ' asked to join through the invite link. The owner can approve or decline on the members page.');
            mb_audit($pdo, $id, 'invite.request', 'pad:' . $pad['id']);
        }
        return 'requested';
    }
    mb_add_member($pdo, $pad, $id, 'invite');
    $pdo->prepare('UPDATE pad_invites SET uses = uses + 1 WHERE pad_id = ?')->execute([$pad['id']]);
    mb_pad_event($pdo, $pad, $id, mb_invite_who($principal) . ' joined through the invite link.');
    return 'joined';
}

/** People and agents waiting for the owner's answer, oldest first. */
function mb_join_requests(PDO $pdo, int $padId): array
{
    $q = $pdo->prepare('SELECT r.id AS request_id, r.created_at AS requested_at, p.id, p.kind, p.handle, p.display_name, a.role AS agent_role, a.platform, a.acts_for
                          FROM pad_join_requests r JOIN principals p ON p.id = r.principal_id LEFT JOIN agent_profiles a ON a.principal_id = p.id
                         WHERE r.pad_id = ? AND p.disabled_at IS NULL ORDER BY r.id');
    $q->execute([$padId]);
    return $q->fetchAll();
}

/** Whether $principalId is waiting to be let into $padId. */
function mb_join_pending(PDO $pdo, int $padId, int $principalId): bool
{
    $q = $pdo->prepare('SELECT 1 FROM pad_join_requests WHERE pad_id = ? AND principal_id = ?');
    $q->execute([$padId, $principalId]);
    return (bool) $q->fetchColumn();
}

/** The owner answers a request. Approving admits them; declining just removes the request. */
function mb_join_decide(PDO $pdo, array $pad, int $requestId, bool $approve, int $actorId): string
{
    $q = $pdo->prepare('SELECT principal_id FROM pad_join_requests WHERE id = ? AND pad_id = ?');
    $q->execute([$requestId, $pad['id']]);
    $pid = $q->fetchColumn();
    if (!$pid) {
        throw new MbAccountError('That request is no longer waiting.');
    }
    $p = mb_principal($pdo, (int) $pid);
    $pdo->prepare('DELETE FROM pad_join_requests WHERE id = ?')->execute([$requestId]);
    if (!$approve) {
        $pdo->prepare('DELETE FROM join_receipts WHERE pad_id = ? AND principal_id = ?')->execute([$pad['id'], $pid]);   // a declined request's code is dead
    }
    if ($approve) {
        mb_add_member($pdo, $pad, (int) $pid, 'invite');
        $pdo->prepare('UPDATE pad_invites SET uses = uses + 1 WHERE pad_id = ?')->execute([$pad['id']]);
        mb_pad_event($pdo, $pad, (int) $pid, mb_invite_who($p) . ' joined through the invite link.');
    }
    mb_audit($pdo, $actorId, $approve ? 'invite.approve' : 'invite.decline', 'pad:' . $pad['id'], 'principal:' . $pid);
    return '@' . $p['handle'] . ($approve ? ' is now on this pad.' : '\'s request was declined.');
}

/**
 * An agent with no Mashpad identity joins through the link on its own. It is
 * registered as a guest under the pad's owner and must say who it acts for.
 * Returns [agent row, token]. With $withToken false (the pad's owner has to approve
 * first) no token exists yet and the second element is null.
 */
function mb_invite_guest_agent(PDO $pdo, array $pad, array $in, bool $withToken = true): array
{
    $for = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['acts_for'] ?? '')));
    if ($for === '' || mb_ulen($for) > 120) {
        throw new MbAccountError('Say who you act for: pass acts_for with the name of the person you are working for (up to 120 characters). The pad\'s owner sees it when deciding whether to let you in.');
    }
    $handle = mb_validate_handle((string) ($in['handle'] ?? ''));
    $clash  = $pdo->prepare('SELECT 1 FROM pad_members m JOIN principals x ON x.id = m.principal_id WHERE m.pad_id = ? AND m.removed_at IS NULL AND x.handle = ?');
    $clash->execute([$pad['id'], $handle]);
    if ($clash->fetchColumn()) {
        throw new MbAccountError('Someone on this pad already goes by @' . $handle . '. Pick another handle, or if that\'s you, join with your token.');
    }
    [$id, $token] = mb_create_agent($pdo, (int) $pad['owner_id'], $in, 'invite');
    $pdo->prepare('UPDATE agent_profiles SET acts_for = ? WHERE principal_id = ?')->execute([$for, $id]);
    if (!$withToken) {
        mb_revoke_agent_tokens($pdo, $id);          // it gets one when it collects with its request code, after approval
        $token = null;
    }
    return [mb_principal($pdo, $id), $token];
}

// ---------------------------------------------------------------- request codes

/** Whether $agentId has a working token (a guest still waiting for approval has none). */
function mb_agent_has_token(PDO $pdo, int $agentId): bool
{
    $q = $pdo->prepare("SELECT 1 FROM tokens WHERE principal_id = ? AND kind = 'agent' AND revoked_at IS NULL LIMIT 1");
    $q->execute([$agentId]);
    return (bool) $q->fetchColumn();
}

/**
 * A request code for $principalId's request to join $padId: what a waiting
 * agent keeps in place of a token. Issuing a new one replaces any earlier one.
 */
function mb_receipt_issue(PDO $pdo, int $padId, int $principalId): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 12; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)] . ($i === 3 || $i === 7 ? '-' : '');
    }
    $pdo->prepare('INSERT INTO join_receipts (pad_id, principal_id, code_hash) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), collected_at = NULL, created_at = UTC_TIMESTAMP()')
        ->execute([$padId, $principalId, hash('sha256', mb_lobby_normalize($code), true)]);
    return $code;
}

/**
 * An agent presents its request code. Returns:
 *   ['state' => 'ready', 'agent', 'pad', 'token']   approved: here is your token (the code is now used up)
 *   ['state' => 'waiting', 'agent', 'pad']          the owner hasn't answered yet; keep the code
 *   ['state' => 'invalid']                          unknown, used, declined, or the link was withdrawn
 */
function mb_receipt_collect(PDO $pdo, string $code): array
{
    $norm = mb_lobby_normalize($code);
    if (strlen($norm) !== 12) {
        return ['state' => 'invalid'];
    }
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM join_receipts WHERE code_hash = ? AND collected_at IS NULL FOR UPDATE');
        $q->execute([hash('sha256', $norm, true)]);
        $r = $q->fetch();
        $agent = $r ? mb_principal($pdo, (int) $r['principal_id']) : null;
        $pad   = $r ? mb_pad_by_id($pdo, (int) $r['pad_id']) : null;
        if (!$r || !$agent || !$pad || $agent['kind'] !== 'agent' || $agent['disabled_at'] !== null) {
            $pdo->commit();
            return ['state' => 'invalid'];
        }
        if (!mb_membership($pdo, (int) $pad['id'], (int) $agent['id'])) {
            $waiting = mb_join_pending($pdo, (int) $pad['id'], (int) $agent['id']);
            $pdo->commit();
            return $waiting ? ['state' => 'waiting', 'agent' => $agent, 'pad' => $pad] : ['state' => 'invalid'];
        }
        $token = mb_issue_agent_token($pdo, (int) $agent['id']);
        $pdo->prepare('UPDATE join_receipts SET collected_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$r['id']]);
        mb_audit($pdo, (int) $agent['id'], 'invite.collect', 'pad:' . $pad['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['state' => 'ready', 'agent' => $agent, 'pad' => $pad, 'token' => $token];
}
