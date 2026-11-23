<?php
/**
 * Mashpad — one request to an address a person supplied, with the same guard as the app documents (src/cimd.php).
 *
 * The address must pass mb_public_url_check (https, port 443, a real hostname, no credentials...). Every address the
 * name resolves to must be a public one, and the connection is pinned to a checked address. No redirect is followed,
 * and only the start of the response is read: a wake-up cares about the status, not the answer.
 *
 * For tests and the end-to-end environment, which can't reach the internet: $GLOBALS['mb_cimd_resolver'] replaces the
 * DNS lookup, $GLOBALS['mb_wake_http'] replaces the request itself (after the address checks), and
 * MASHPAD_TEST_WAKE_CAPTURE (a server environment variable that production never sets) names a file each request is
 * written to instead of being sent; MASHPAD_TEST_WAKE_REPLY names a file holding the status to answer with.
 */
declare(strict_types=1);

require_once __DIR__ . '/cimd.php';

const MB_SAFEHTTP_READ_BYTES = 2048;

/**
 * Send $method to $url with $headers (a list of "Name: value") and $body. Returns ['status', 'error', 'transient'].
 * Throws MbCimdError for an address that may not be called. The error text never includes a header or the body.
 */
function mb_safe_request(string $method, string $url, array $headers, ?string $body, int $timeout = 5): array
{
    [$host] = mb_public_url_check($url, 'That address can\'t be used for wake-ups');
    $capture = getenv('MASHPAD_TEST_WAKE_CAPTURE') ?: ($_SERVER['MASHPAD_TEST_WAKE_CAPTURE'] ?? '');
    if ($capture !== '') {                                                     // end-to-end tests only: never set in production
        file_put_contents($capture, json_encode(['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        $replyFile = getenv('MASHPAD_TEST_WAKE_REPLY') ?: ($_SERVER['MASHPAD_TEST_WAKE_REPLY'] ?? '');
        $status = $replyFile !== '' && is_file($replyFile) ? (int) trim((string) file_get_contents($replyFile)) : 200;
        return ['status' => $status, 'error' => null, 'transient' => $status >= 500 || $status === 429];
    }
    $ips = mb_cimd_resolve($host);
    if (!$ips) {
        throw MbCimdError::make('Mashpad couldn\'t find ' . $host . '.', true);
    }
    foreach ($ips as $ip) {
        if (!mb_cimd_ip_ok($ip)) {
            throw MbCimdError::make('That address points to a private or reserved place, so Mashpad won\'t call it.');
        }
    }
    if (isset($GLOBALS['mb_wake_http'])) {
        return ($GLOBALS['mb_wake_http'])($method, $url, $headers, $body, $host, $ips[0]);
    }
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'error' => 'this server can\'t make outbound requests', 'transient' => true];
    }
    $ip = $ips[0];
    $got = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST   => $method,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_TIMEOUT         => $timeout,
        CURLOPT_CONNECTTIMEOUT  => 3,
        CURLOPT_RESOLVE         => [$host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],      // the address that was checked, and no other
        CURLOPT_HTTPHEADER      => $headers,
        CURLOPT_USERAGENT       => 'Mashpad-wake/1',
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_WRITEFUNCTION   => function ($c, string $chunk) use (&$got): int {
            $got += strlen($chunk);
            return $got > MB_SAFEHTTP_READ_BYTES ? 0 : strlen($chunk);                                        // enough: stop reading
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
    } elseif (defined('CURLPROTO_HTTPS')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    }
    $ok     = curl_exec($ch);
    $err    = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status > 0 && ($ok !== false || $err === CURLE_WRITE_ERROR)) {
        return ['status' => $status, 'error' => null, 'transient' => $status >= 500 || $status === 429];
    }
    return ['status' => 0, 'error' => $err === CURLE_OPERATION_TIMEDOUT ? 'the host took too long to answer' : 'the host couldn\'t be reached', 'transient' => true];
}
