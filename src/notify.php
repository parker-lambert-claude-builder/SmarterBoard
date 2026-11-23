<?php
/**
 * Mashpad — the email Mashpad sends by itself, and each person's say over it.
 *
 * Two kinds of email, treated differently:
 * - Account email (a password-reset link). Sent only because the person asked
 *   for it just now. It has no unsubscribe link: there is nothing to unsubscribe
 *   from, and a reset must always be able to reach its owner.
 * - Notifications (someone asked to join your pad). Sent because something
 *   happened. Every one carries an unsubscribe link that works with one press
 *   and no sign-in, and the List-Unsubscribe headers mail apps use for their own
 *   Unsubscribe button.
 *
 * Nothing here ever throws into the action that triggered it: a mail problem
 * must not stop a password page rendering or a join request being filed.
 */
declare(strict_types=1);

require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/pads.php';

const MB_RESET_SECONDS  = 3600;
const MB_RESET_PER_HOUR = 3;           // reset emails per address per hour
const MB_RESET_PER_IP   = 10;          // reset requests per network address per hour
const MB_RESET_CONTACT  = 'hello@parker-lambert.com';

// ---------------------------------------------------------------- email preferences

/** A person's email preferences, creating the row (and their unsubscribe token) on first use. */
function mb_email_prefs(PDO $pdo, int $principalId): array
{
    $q = $pdo->prepare('SELECT * FROM email_prefs WHERE principal_id = ?');
    $q->execute([$principalId]);
    if ($row = $q->fetch()) {
        return $row;
    }
    $pdo->prepare('INSERT IGNORE INTO email_prefs (principal_id, unsub_token) VALUES (?, ?)')->execute([$principalId, mb_random_token()]);
    $q->execute([$principalId]);
    return $q->fetch();
}

/** The person an unsubscribe link belongs to: ['principal_id', 'notify_requests', 'handle', …] or null. */
function mb_email_prefs_by_token(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) {
        return null;
    }
    $q = $pdo->prepare('SELECT e.*, p.handle, p.email FROM email_prefs e JOIN principals p ON p.id = e.principal_id WHERE e.unsub_token = ? AND p.disabled_at IS NULL');
    $q->execute([$token]);
    return $q->fetch() ?: null;
}

function mb_email_set_requests(PDO $pdo, int $principalId, bool $on): void
{
    mb_email_prefs($pdo, $principalId);
    $pdo->prepare('UPDATE email_prefs SET notify_requests = ? WHERE principal_id = ?')->execute([$on ? 1 : 0, $principalId]);
    mb_audit($pdo, $principalId, 'email.prefs', null, 'notify_requests=' . (int) $on);
}

function mb_unsubscribe_url(array $prefs): string
{
    return mb_site_url() . 'email/?t=' . $prefs['unsub_token'];
}

/** The lines every notification ends with, and the headers mail apps use for their own Unsubscribe button. */
function mb_notification_footer(array $prefs): array
{
    $url = mb_unsubscribe_url($prefs);
    return [
        "\n\n--\nYou're getting this because you own a pad on Mashpad.\nStop these emails with one press, no sign-in needed: $url",
        ['List-Unsubscribe' => '<' . $url . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
    ];
}

// ---------------------------------------------------------------- notification: someone asked to join

/**
 * Tell a pad's owner that someone is waiting. $who is the requester's principal row.
 * Returns what happened, for tests and the audit trail: 'sent', 'off' (they unsubscribed),
 * 'no-mail' (email isn't set up), or 'failed'. Never throws.
 */
function mb_notify_join_request(PDO $pdo, array $pad, array $who): string
{
    try {
        if (!mb_mail_config($pdo)['ready']) {
            return 'no-mail';
        }
        $owner = mb_principal($pdo, (int) $pad['owner_id']);
        $prefs = $owner ? mb_email_prefs($pdo, (int) $owner['id']) : null;
        if (!$owner || empty($owner['email']) || !$prefs || !(int) $prefs['notify_requests']) {
            return 'off';
        }
        $isAgent = $who['kind'] === 'agent';
        $name    = '@' . $who['handle'] . ($isAgent ? (!empty($who['acts_for']) ? ', an AI agent that says it acts for ' . $who['acts_for'] : ', an AI agent') : ' (' . $who['display_name'] . ')');
        $padUrl  = mb_site_url() . 'pads/' . $pad['owner_handle'] . '/' . $pad['slug'];
        [$footer, $headers] = mb_notification_footer($prefs);
        $text = "$name has asked to join your pad \"{$pad['name']}\" through its open invitation.\n\n"
              . "They can't read or post until you answer. Approve or decline on the pad:\n$padUrl\n\n"
              . ($isAgent ? "The name of the person it acts for is what it told us; Mashpad hasn't verified it.\n\n" : '')
              . 'Approve only people and agents you recognise: once in, they can read everything on the pad.'
              . $footer;
        $r = mb_mail($pdo, (string) $owner['email'], (string) $owner['display_name'], '@' . $who['handle'] . ' asked to join ' . $pad['name'], $text, null, $headers);
        return $r['ok'] ? 'sent' : 'failed';
    } catch (Throwable $e) {
        return 'failed';
    }
}

// ---------------------------------------------------------------- password reset

/**
 * Someone asks for a reset link for $email. Always behaves the same from the outside, whether or not the
 * address has an account, so the page can't be used to find out who is registered. Throws MbAccountError
 * only for "slow down" and "email isn't set up".
 */
function mb_reset_request(PDO $pdo, string $email): void
{
    if (!mb_mail_config($pdo)['ready']) {
        throw new MbAccountError('Mashpad can\'t send email yet, so a reset link can\'t be sent. Write to ' . MB_RESET_CONTACT . ' and we\'ll help you back in.');
    }
    $normal = mb_normalize_email($email);
    if ($wait = mb_wait_seconds($pdo, 'reset', 'ip', mb_client_ip(), 3600, MB_RESET_PER_IP, false)) {
        throw mb_too_many($wait, 'reset');
    }
    $tooMany = mb_wait_seconds($pdo, 'reset', 'email_normalized', $normal, 3600, MB_RESET_PER_HOUR, false) > 0;
    mb_attempt($pdo, 'reset', $normal, true);
    if ($tooMany || !filter_var($normal, FILTER_VALIDATE_EMAIL)) {
        return;                                 // quietly: the same answer as for any other address
    }
    $q = $pdo->prepare("SELECT id, display_name, email FROM principals WHERE kind = 'human' AND email_normalized = ? AND disabled_at IS NULL");
    $q->execute([$normal]);
    $p = $q->fetch();
    if (!$p) {
        return;
    }
    $token = mb_random_token();
    $pdo->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE principal_id = ? AND used_at IS NULL')->execute([$p['id']]);   // one live link at a time
    $pdo->prepare('INSERT INTO password_resets (principal_id, token_hash, expires_at) VALUES (?, ?, FROM_UNIXTIME(?))')
        ->execute([$p['id'], hash('sha256', $token, true), time() + MB_RESET_SECONDS]);
    $link = mb_site_url() . 'reset/?t=' . $token;
    mb_mail($pdo, (string) $p['email'], (string) $p['display_name'], 'Reset your Mashpad password',
        "Someone asked to reset the password for the Mashpad account with this email address. If that was you, choose a new password here:\n\n$link\n\n"
        . "The link works once and for one hour.\n\nIf you didn't ask for this, ignore this email. Your password stays as it is, and nobody can use the link without access to your inbox.");
    mb_audit($pdo, (int) $p['id'], 'password.reset_requested');
}

/** The account a reset link is for, if the link is still good: ['id', 'principal_id', 'handle'] or null. */
function mb_reset_lookup(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) {
        return null;
    }
    $q = $pdo->prepare('SELECT r.id, r.principal_id, p.handle FROM password_resets r JOIN principals p ON p.id = r.principal_id
                         WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > UTC_TIMESTAMP() AND p.disabled_at IS NULL');
    $q->execute([hash('sha256', $token, true)]);
    return $q->fetch() ?: null;
}

/** Set the new password. The link is used up, and every existing sign-in for the account is ended. */
function mb_reset_complete(PDO $pdo, string $token, string $password): int
{
    $r = mb_reset_lookup($pdo, $token);
    if (!$r) {
        throw new MbAccountError('That reset link isn\'t valid any more. Links work once and for one hour. Ask for a new one.');
    }
    mb_validate_password($password);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE principals SET password_hash = ? WHERE id = ?')->execute([password_hash($password, mb_password_algo()), $r['principal_id']]);
        $pdo->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE principal_id = ? AND used_at IS NULL')->execute([$r['principal_id']]);
        $pdo->prepare("UPDATE tokens SET revoked_at = UTC_TIMESTAMP() WHERE principal_id = ? AND kind = 'session' AND revoked_at IS NULL")->execute([$r['principal_id']]);
        mb_audit($pdo, (int) $r['principal_id'], 'password.reset');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return (int) $r['principal_id'];
}
