<?php
/**
 * Mashpad — the email Mashpad sends by itself, and each person's say over it.
 *
 * Two kinds of email, treated differently:
 * - Account email (a password-reset code). Sent only because the person asked
 *   for it just now. It has no unsubscribe link: there is nothing to unsubscribe
 *   from, and a reset must always be able to reach its owner. The code is typed
 *   in, never carried in a link: mail services rewrite links and can break them. Its one link goes
 *   to the plain reset page and holds nothing.
 * - Notifications (someone asked to join your pad). Sent because something
 *   happened. Every one carries the List-Unsubscribe headers mail apps use for
 *   their own Unsubscribe button (one press, no sign-in); its body points to Email
 *   settings and holds no address with a token in it.
 *
 * Nothing here ever throws into the action that triggered it: a mail problem
 * must not stop a password page rendering or a join request being filed.
 */
declare(strict_types=1);

require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/pads.php';

const MB_RESET_SECONDS  = 900;         // a code is good for fifteen minutes
const MB_RESET_TRIES    = 5;           // wrong guesses before a code is cancelled
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
        // The body carries no address with a token in it: mail services rewrite links in a message and can break them.
        // The one-press route is the header below, which they leave alone; the body points at the signed-in page.
        "\n\n--\nYou're getting this because you own a pad on Mashpad.\nTo stop these emails, use your mail app's Unsubscribe button, or sign in and open Email settings: " . mb_site_url() . 'email/',
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

/** What is stored for a code: a hash that only matches for this account. */
function mb_reset_hash(int $principalId, string $code): string
{
    return hash('sha256', 'mashpad-reset:' . $principalId . ':' . $code, true);
}

/**
 * Someone asks for a reset code for $email. Always behaves the same from the outside, whether or not the
 * address has an account, so the page can't be used to find out who is registered. Throws MbAccountError
 * only for "slow down" and "email isn't set up".
 *
 * The email carries a six-digit code, and one link to the plain reset page (nothing in the address for a mail
 * service to break). Its subject states the code, which is what lets a browser or phone offer to fill it in.
 */
function mb_reset_request(PDO $pdo, string $email): void
{
    if (!mb_mail_config($pdo)['ready']) {
        throw new MbAccountError('Mashpad can\'t send email yet, so a reset code can\'t be sent. Write to ' . MB_RESET_CONTACT . ' and we\'ll help you back in.');
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
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE principal_id = ? AND used_at IS NULL')->execute([$p['id']]);   // one live code at a time
    $pdo->prepare('INSERT INTO password_resets (principal_id, code_hash, expires_at) VALUES (?, ?, FROM_UNIXTIME(?))')
        ->execute([$p['id'], mb_reset_hash((int) $p['id'], $code), time() + MB_RESET_SECONDS]);
    $link = mb_site_url() . 'reset/?step=code';          // the plain reset page: nothing in the address for a mail service to break
    $say  = 'Click here to enter your code and reset your password';
    $tail = "If you didn't ask for this, ignore this email. Your password stays as it is, and nobody can use the code without access to your inbox.";
    mb_mail($pdo, (string) $p['email'], (string) $p['display_name'], 'Your Mashpad password reset code is ' . $code,
        "Your Mashpad password reset code is $code\n\n$say:\n$link\n\nThis code expires in 15 minutes.\n\n$tail",
        '<p>Your Mashpad password reset code is <strong>' . $code . '</strong></p>'
        . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">' . $say . '</a></p>'
        . '<p>This code expires in 15 minutes.</p><p>' . htmlspecialchars($tail, ENT_QUOTES) . '</p>');
    mb_audit($pdo, (int) $p['id'], 'password.reset_requested');
}

/**
 * Check a code for $email and, if it is right, set the new password. The code is used up and every existing
 * sign-in for the account is ended. Every way of being wrong gives the same message, so a guesser learns
 * nothing about which part was wrong, or whether the address has an account. Returns the account's id.
 */
function mb_reset_complete(PDO $pdo, string $email, string $code, string $password): int
{
    $wrong  = new MbAccountError('That code isn\'t right, or it has expired. Codes last 15 minutes and stop working after ' . MB_RESET_TRIES . ' wrong tries. Check the newest email, or ask for a new code.');
    $normal = mb_normalize_email($email);
    $code   = (string) preg_replace('/\D+/', '', $code);
    // Guessing is bounded per code (MB_RESET_TRIES) and per address (MB_RESET_PER_HOUR codes an hour), so at most
    // a handful of guesses an hour can be made against any one account, out of a million possible codes.
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT r.id, r.principal_id, r.code_hash, r.attempts FROM password_resets r JOIN principals p ON p.id = r.principal_id
                             WHERE p.kind = 'human' AND p.email_normalized = ? AND p.disabled_at IS NULL AND r.used_at IS NULL AND r.expires_at > UTC_TIMESTAMP()
                             ORDER BY r.id DESC LIMIT 1 FOR UPDATE");
        $q->execute([$normal]);
        $r = $q->fetch();
        if (!$r || (int) $r['attempts'] >= MB_RESET_TRIES || strlen($code) !== 6) {
            $pdo->commit();
            throw $wrong;
        }
        if (!hash_equals((string) $r['code_hash'], mb_reset_hash((int) $r['principal_id'], $code))) {
            // A wrong guess is counted; at the limit the code is cancelled outright.
            $pdo->prepare('UPDATE password_resets SET attempts = attempts + 1, used_at = IF(attempts + 1 >= ?, UTC_TIMESTAMP(), used_at) WHERE id = ?')->execute([MB_RESET_TRIES, $r['id']]);
            $pdo->commit();
            throw $wrong;
        }
        mb_validate_password($password);        // a too-short password doesn't cost the code: the person just tries again
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
