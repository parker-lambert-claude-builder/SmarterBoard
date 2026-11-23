<?php
/**
 * Mashpad — claiming a guest agent.
 *
 * An agent can join a pad on its own through an open invitation, before its
 * person has a Mashpad account. It is then a guest: registered under the pad's
 * owner, who is responsible for it. When its person does sign up, they can claim
 * it, and it moves to their account.
 *
 * Both sides have to act:
 *   1. The person, signed in, gets a one-time claim code (ten minutes).
 *   2. The agent, holding its own token, accepts the code (accept_owner).
 *
 * Rules:
 * - Only a guest can be claimed: an agent that joined alone through an invite
 *   link and has not been claimed before. An agent somebody set up, or that
 *   joined with somebody's ten-minute code, already has a responsible person
 *   who chose to be one; it can't be moved by talking it into running a tool.
 * - The agent keeps its handle, its token and its pads. Every pad it is on
 *   records the change as an event, so the pad's owner sees it.
 * - The pad's owner can still remove it from their pad. They can no longer
 *   revoke its token: that now belongs to the person who claimed it.
 */
declare(strict_types=1);

require_once __DIR__ . '/pads.php';

const MB_CLAIM_SECONDS = 600;

/** Whether $agent (a principal row from mb_principal) is still a guest that someone may claim. */
function mb_is_guest_agent(array $agent): bool
{
    return ($agent['kind'] ?? '') === 'agent' && ($agent['agent_joined_via'] ?? '') === 'invite' && empty($agent['claimed_at']);
}

/** A one-time claim code for the person $humanId. Returns [code, expires_unix]. Earlier unused codes of theirs are cancelled. */
function mb_claim_create(PDO $pdo, int $humanId): array
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)] . ($i === 3 ? '-' : '');
    }
    $expires = time() + MB_CLAIM_SECONDS;
    $pdo->prepare('DELETE FROM agent_claims WHERE created_by = ? AND used_at IS NULL')->execute([$humanId]);
    $pdo->prepare('INSERT INTO agent_claims (code_hash, created_by, expires_at) VALUES (?, ?, FROM_UNIXTIME(?))')
        ->execute([hash('sha256', mb_lobby_normalize($code), true), $humanId, $expires]);
    mb_audit($pdo, $humanId, 'claim.create');
    return [$code, $expires];
}

/**
 * $agent accepts a claim code. On success the agent belongs to the person who
 * made the code. Returns ['owner' => handle, 'pads' => [refs…]].
 */
function mb_claim_accept(PDO $pdo, array $agent, string $code): array
{
    $invalid = new MbAccountError('That claim code isn\'t valid. Codes last 10 minutes and work once; ask the person claiming you for a fresh one.');
    if (($agent['kind'] ?? '') !== 'agent') {
        throw $invalid;
    }
    if (!mb_is_guest_agent($agent)) {
        throw new MbAccountError('Not accepted: you already have a responsible person on Mashpad (@' . (mb_principal($pdo, (int) $agent['owner_id'])['handle'] ?? '?')
            . '), who set you up or invited you. Only an agent that joined on its own through an open invitation can be claimed. '
            . 'If someone asked you to accept this code, tell your responsible person; nothing has changed.');
    }
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT c.id, c.created_by, p.handle FROM agent_claims c JOIN principals p ON p.id = c.created_by
                             WHERE c.code_hash = ? AND c.used_at IS NULL AND c.expires_at > UTC_TIMESTAMP() AND p.kind = \'human\' AND p.disabled_at IS NULL FOR UPDATE');
        $q->execute([hash('sha256', mb_lobby_normalize($code), true)]);
        $claim = $q->fetch();
        if (!$claim) {
            throw $invalid;
        }
        $newOwner = (int) $claim['created_by'];
        $clash = $pdo->prepare("SELECT 1 FROM principals WHERE kind = 'agent' AND owner_id = ? AND handle = ? AND id <> ?");
        $clash->execute([$newOwner, $agent['handle'], $agent['id']]);
        if ($clash->fetchColumn()) {
            throw new MbAccountError('Not accepted: @' . $claim['handle'] . ' already has an agent called @' . $agent['handle'] . '. Nothing has changed. Tell them; one of the two would need a different name.');
        }
        mb_check_agent_room($pdo, $newOwner, 'claim');                  // it moves into the claimer's allowance
        $oldOwner = (int) $agent['owner_id'];
        $pdo->prepare('UPDATE principals SET owner_id = ? WHERE id = ?')->execute([$newOwner, $agent['id']]);
        $pdo->prepare('UPDATE agent_profiles SET claimed_at = UTC_TIMESTAMP(), acts_for = NULL WHERE principal_id = ?')->execute([$agent['id']]);
        $pdo->prepare('UPDATE agent_claims SET used_at = UTC_TIMESTAMP(), agent_id = ? WHERE id = ?')->execute([$agent['id'], $claim['id']]);
        mb_audit($pdo, $newOwner, 'claim.accept', 'principal:' . $agent['id'], 'from principal:' . $oldOwner);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $pads = mb_pads_for($pdo, (int) $agent['id']);
    foreach ($pads as $p) {
        mb_pad_event($pdo, $p, (int) $agent['id'], '@' . $agent['handle'] . ' is now managed by @' . $claim['handle'] . ', who claimed it. Until now it was a guest on this pad.');
    }
    return ['owner' => $claim['handle'], 'pads' => array_column($pads, 'ref')];
}

/** The person's live claim code, if they have one: ['expires' => unix] (the code itself is not recoverable). */
function mb_claim_pending(PDO $pdo, int $humanId): ?array
{
    $q = $pdo->prepare('SELECT UNIX_TIMESTAMP(expires_at) AS expires FROM agent_claims WHERE created_by = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1');
    $q->execute([$humanId]);
    return $q->fetch() ?: null;
}
