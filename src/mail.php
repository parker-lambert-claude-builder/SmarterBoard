<?php
/**
 * Mashpad — outgoing email, sent through Brevo's HTTPS API.
 *
 * Why the API and not SMTP: it is one HTTPS request with a clear answer (a
 * message id, or a reason), it needs no mail library, and it doesn't depend on
 * the host allowing outbound SMTP ports. The key is entered on admin/email/ and
 * kept in the settings table; it is never written to a file or shown in full.
 *
 * Nothing here decides *when* to send: callers (password reset, invitations,
 * digests) do. mb_mail() returns what happened and never throws for a refused
 * or failed send, so a mail problem can't break the page that triggered it.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const MB_BREVO_API = 'https://api.brevo.com/v3';

function mb_setting(PDO $pdo, string $name, string $default = ''): string
{
    $q = $pdo->prepare('SELECT value FROM settings WHERE name = ?');
    $q->execute([$name]);
    $v = $q->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function mb_set_setting(PDO $pdo, string $name, string $value): void
{
    $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$name, $value]);
}

/**
 * ['api_key', 'from_email', 'from_name', 'api' => base URL, 'ready' => bool]
 * 'api' is Brevo's address. The brevo_api_base setting can point it at a stand-in
 * for testing; it has no form field and is empty in normal use.
 */
function mb_mail_config(PDO $pdo): array
{
    $c = ['api_key' => mb_setting($pdo, 'brevo_api_key'), 'from_email' => mb_setting($pdo, 'mail_from_email'), 'from_name' => mb_setting($pdo, 'mail_from_name', 'Mashpad'),
          'api' => rtrim(mb_setting($pdo, 'brevo_api_base') ?: MB_BREVO_API, '/')];
    return $c + ['ready' => $c['api_key'] !== '' && $c['from_email'] !== ''];
}

/** "…k3Qz": enough to recognise a key, never enough to use it. */
function mb_mask_key(string $key): string
{
    return $key === '' ? '' : '…' . substr($key, -4);
}

/**
 * One HTTPS request. Returns ['status' => int (0 if it never got an answer), 'body' => string, 'error' => string].
 * Tests replace it by setting $GLOBALS['mb_http'] to a callable with the same arguments.
 */
function mb_http(string $method, string $url, array $headers, ?string $body = null): array
{
    if (isset($GLOBALS['mb_http']) && is_callable($GLOBALS['mb_http'])) {
        return $GLOBALS['mb_http']($method, $url, $headers, $body);
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => false]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = $out === false ? curl_error($ch) : '';
        curl_close($ch);
        return ['status' => $status, 'body' => $out === false ? '' : (string) $out, 'error' => $err];
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'timeout' => 12, 'ignore_errors' => true]]);
    $out = @file_get_contents($url, false, $ctx);
    $status = isset($http_response_header[0]) && preg_match('~ (\d{3}) ~', $http_response_header[0] . ' ', $m) ? (int) $m[1] : 0;
    return ['status' => $status, 'body' => $out === false ? '' : (string) $out, 'error' => $out === false ? 'The request could not be made.' : ''];
}

/** Brevo's own words for a refusal, or a plain one of ours. */
function mb_brevo_error(array $r): string
{
    if ($r['status'] === 0) {
        return 'Brevo couldn\'t be reached' . ($r['error'] !== '' ? ' (' . $r['error'] . ')' : '') . '.';
    }
    $j = json_decode($r['body'], true);
    $said = is_array($j) && !empty($j['message']) ? (string) $j['message'] : '';
    return match (true) {
        $r['status'] === 401 => 'Brevo refused the API key' . ($said !== '' ? ': ' . $said : '.'),
        $said !== ''         => 'Brevo said: ' . $said,
        default              => 'Brevo answered with status ' . $r['status'] . '.',
    };
}

/** Ask Brevo whose key this is. ['ok', 'email', 'company', 'error'] */
function mb_brevo_account(string $apiKey, string $api = MB_BREVO_API): array
{
    $r = mb_http('GET', $api . '/account', ['api-key: ' . $apiKey, 'accept: application/json']);
    $j = json_decode($r['body'], true);
    if ($r['status'] === 200 && is_array($j)) {
        return ['ok' => true, 'email' => (string) ($j['email'] ?? ''), 'company' => (string) ($j['companyName'] ?? ''), 'error' => ''];
    }
    return ['ok' => false, 'email' => '', 'company' => '', 'error' => mb_brevo_error($r)];
}

/**
 * Send one email. $text is the plain-text body; $html is optional.
 * Returns ['ok' => bool, 'id' => Brevo's message id, 'error' => why not].
 */
function mb_mail(PDO $pdo, string $toEmail, string $toName, string $subject, string $text, ?string $html = null): array
{
    $c = mb_mail_config($pdo);
    if (!$c['ready']) {
        return ['ok' => false, 'id' => '', 'error' => 'Email isn\'t set up yet.'];
    }
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'id' => '', 'error' => 'That isn\'t an email address.'];
    }
    $clean   = fn (string $s) => trim((string) preg_replace('/[\r\n]+/', ' ', $s));          // no header injection through names or subjects
    $payload = ['sender' => ['name' => $clean($c['from_name']), 'email' => $c['from_email']],
                'to' => [array_filter(['email' => $toEmail, 'name' => $clean($toName)])],
                'subject' => $clean($subject), 'textContent' => $text] + ($html !== null ? ['htmlContent' => $html] : []);
    $r = mb_http('POST', $c['api'] . '/smtp/email', ['api-key: ' . $c['api_key'], 'accept: application/json', 'content-type: application/json'],
                 (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    $j  = json_decode($r['body'], true);
    $ok = $r['status'] >= 200 && $r['status'] < 300;
    // The log records that a mail went out and to which domain, never its text or the full address.
    mb_audit($pdo, null, $ok ? 'mail.sent' : 'mail.failed', 'to:@' . substr((string) strrchr($toEmail, '@'), 1), $clean($subject) . ($ok ? '' : ' | ' . mb_brevo_error($r)));
    return $ok ? ['ok' => true, 'id' => is_array($j) ? (string) ($j['messageId'] ?? '') : '', 'error' => '']
               : ['ok' => false, 'id' => '', 'error' => mb_brevo_error($r)];
}
