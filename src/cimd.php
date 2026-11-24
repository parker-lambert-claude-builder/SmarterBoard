<?php
/**
 * Mashpad — Client ID Metadata Documents.
 *
 * An app can identify itself to Mashpad's sign-in with a client ID that is an https address. The address returns a
 * small JSON document with the app's name and the addresses it may send people back to. Mashpad fetches it at sign-in,
 * checks it, and keeps what it found (the same table as registered apps), so nothing is registered by the app. Dynamic
 * registration still works for any app that uses it. The MCP specification calls registration deprecated and says
 * servers should support this.
 *
 * This is the careful part, because Mashpad fetches an address an app supplies:
 * - The address must be https on port 443 with a real hostname (no IP address, localhost, .local, .internal), no user
 *   name or password, no fragment, no "." or ".." segments, a path, and at most 191 characters.
 * - Every address the name resolves to must be a public one. The connection is then pinned to an address that was
 *   checked, so the name can't be resolved again to an internal one in between.
 * - No redirects are followed. At most 5 KB, 5 seconds, and JSON.
 * - The document's client_id must equal the address it was fetched from, exactly. It must be a public client: a
 *   document that asks for a shared secret or key-based client authentication is refused.
 * - Fetches are rate limited per requesting address and per host, and expired unused entries are cleaned up.
 * - The sign-in page shows which address identifies the app: its name is its own claim, the address isn't.
 *
 * Tests and the end-to-end environment can't reach the internet, so $GLOBALS['mb_cimd_resolver'] and
 * $GLOBALS['mb_cimd_http'] replace the DNS lookup and the HTTP request, and MASHPAD_TEST_CIMD_DIR (a server environment
 * variable that production never sets) names a directory of fixtures. Everything else is the real code.
 */
declare(strict_types=1);

require_once __DIR__ . '/accounts.php';

const MB_CIMD_MAX_URL      = 191;
const MB_CIMD_MAX_BYTES    = 5120;
const MB_CIMD_TIMEOUT      = 5;
const MB_CIMD_TTL_MIN      = 300;       // 5 minutes: however briefly it says it may be cached
const MB_CIMD_TTL_MAX      = 86400;     // a day
const MB_CIMD_TTL_DEFAULT  = 3600;
const MB_CIMD_STALE_GRACE  = 86400;     // an expired copy may be used this long if the host can't be reached
const MB_CIMD_PER_IP       = 60;        // fetches an hour, per address asking
const MB_CIMD_PER_HOST     = 120;       // and per host fetched from
const MB_CIMD_KEEP_DAYS    = 30;        // unused entries older than this are removed

/** Why a client's details couldn't be had. $transient: worth trying again later (the host was unreachable or busy). */
class MbCimdError extends MbAccountError
{
    public bool $transient = false;
    public static function make(string $message, bool $transient = false): self
    {
        $e = new self($message);
        $e->transient = $transient;
        return $e;
    }
}

/** Whether a client ID is the address of a metadata document rather than a registered ID. */
function mb_cimd_is_id(string $clientId): bool
{
    return str_starts_with($clientId, 'https://');
}

/** Check a client ID address and return [host, path]. Throws MbCimdError saying what is wrong. */
function mb_cimd_check_url(string $url): array
{
    return mb_public_url_check($url, 'This app\'s address can\'t be used to identify it');
}

/**
 * The rules for any address Mashpad is asked to call that isn't its own: https, port 443, a real hostname, no
 * credentials or fragment or dot segments, a path, at most 191 characters. Returns [host, path]. $prefix starts the
 * message that says what is wrong.
 */
function mb_public_url_check(string $url, string $prefix): array
{
    $bad = fn (string $why) => MbCimdError::make($prefix . ': ' . $why . '.');
    if (strlen($url) > MB_CIMD_MAX_URL) {
        throw $bad('it is longer than ' . MB_CIMD_MAX_URL . ' characters');
    }
    if (preg_match('/[\x00-\x20\x7F"<>\\\\^`{|}]/', $url) || str_contains($url, '#')) {
        throw $bad('it has spaces, control characters or a fragment');
    }
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
        throw $bad('it must be an https address');
    }
    if (isset($p['user']) || isset($p['pass'])) {
        throw $bad('it has a user name or password');
    }
    if (isset($p['port']) && (int) $p['port'] !== 443) {
        throw $bad('it uses a port other than 443');
    }
    $host = strtolower($p['host']);
    if (str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP)) {
        throw $bad('it is an IP address, not a hostname');
    }
    if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
        throw $bad('its hostname isn\'t a public one');
    }
    foreach (['.localhost', '.local', '.internal', '.lan', '.home.arpa', '.corp', '.intranet'] as $suffix) {
        if (str_ends_with($host, $suffix)) {
            throw $bad('its hostname is internal');
        }
    }
    $path = (string) ($p['path'] ?? '');
    if (strlen($path) < 2 || $path[0] !== '/') {
        throw $bad('it needs a path, such as /client.json');
    }
    foreach (explode('/', $path) as $seg) {
        $s = strtolower($seg);
        if ($s === '.' || $s === '..' || $s === '%2e' || $s === '%2e%2e' || $s === '.%2e' || $s === '%2e.') {
            throw $bad('its path has "." or ".." in it');
        }
    }
    return [$host, $path];
}

/** Whether $ip is inside $cidr ("10.0.0.0/8", "fc00::/7"). */
function mb_cimd_in_cidr(string $ip, string $cidr): bool
{
    [$net, $bits] = explode('/', $cidr);
    $a = inet_pton($ip);
    $b = inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) {
        return false;
    }
    $bits  = (int) $bits;
    $whole = intdiv($bits, 8);
    if ($whole && substr($a, 0, $whole) !== substr($b, 0, $whole)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($a[$whole]) & $mask) === (ord($b[$whole]) & $mask);
}

/**
 * Whether an address a server may connect to: a public one. Anything internal, reserved or ambiguous is refused.
 * An address that carries another inside it (IPv4-mapped, NAT64, 6to4) is judged by the address inside, and that
 * comes first: PHP's own reserved-range filter refuses the whole of ::ffff:0:0/96, and which ranges it covers varies
 * by PHP version, so Mashpad's own lists decide and PHP's filter is only a second opinion at the end.
 */
function mb_cimd_ip_ok(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    if (str_contains($ip, ':')) {
        $bin = inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {                // IPv4-mapped
            return mb_cimd_ip_ok((string) inet_ntop(substr($bin, 12)));
        }
        if (mb_cimd_in_cidr($ip, '64:ff9b::/96')) {                                     // NAT64
            return mb_cimd_ip_ok((string) inet_ntop(substr($bin, 12)));
        }
        if (mb_cimd_in_cidr($ip, '2002::/16')) {                                        // 6to4: the address is in bytes 2 to 5
            return mb_cimd_ip_ok((string) inet_ntop(substr($bin, 2, 4)));
        }
        foreach (['::/128', '::1/128', '64:ff9b:1::/48', '100::/64', '2001::/32', '2001:2::/48', '2001:10::/28', '2001:20::/28', '2001:db8::/32', '2620:4f:8000::/48', '3fff::/20',
                  'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8'] as $block) {
            if (mb_cimd_in_cidr($ip, $block)) {
                return false;
            }
        }
    } else {
        foreach (['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24',
                  '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'] as $block) {
            if (mb_cimd_in_cidr($ip, $block)) {
                return false;
            }
        }
    }
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);       // PHP's own list, as a second opinion
}

/** The addresses a hostname resolves to. */
function mb_cimd_resolve(string $host): array
{
    if (isset($GLOBALS['mb_cimd_resolver'])) {
        return array_values((array) ($GLOBALS['mb_cimd_resolver'])($host));
    }
    $ips = [];
    foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
        if (!empty($r['ip'])) {
            $ips[] = $r['ip'];
        } elseif (!empty($r['ipv6'])) {
            $ips[] = $r['ipv6'];
        }
    }
    return $ips;
}

/** One GET to $url over https, connecting to $ip, following no redirect, reading at most 5 KB. */
function mb_cimd_http_get(string $url, string $host, string $ip): array
{
    if (isset($GLOBALS['mb_cimd_http'])) {
        return ($GLOBALS['mb_cimd_http'])($url, $host, $ip);
    }
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'this server can\'t make outbound requests', 'transient' => true];
    }
    $body = '';
    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_TIMEOUT         => MB_CIMD_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT  => 3,
        CURLOPT_RESOLVE         => [$host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],     // the address that was checked, and no other
        CURLOPT_HTTPHEADER      => ['Accept: application/json'],
        CURLOPT_USERAGENT       => 'Mashpad-client-metadata/1',
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_WRITEFUNCTION   => function ($c, string $chunk) use (&$body): int {
            $body .= $chunk;
            return strlen($body) > MB_CIMD_MAX_BYTES ? 0 : strlen($chunk);                                   // 0 stops the transfer
        },
        CURLOPT_HEADERFUNCTION  => function ($c, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
    } elseif (defined('CURLPROTO_HTTPS')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    }
    $ok     = curl_exec($ch);
    $err    = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($ok === false && $err === CURLE_WRITE_ERROR) {
        return ['status' => $status, 'headers' => $headers, 'body' => '', 'error' => 'the document is larger than ' . (MB_CIMD_MAX_BYTES / 1024) . ' KB', 'transient' => false];
    }
    if ($ok === false) {
        return ['status' => 0, 'headers' => $headers, 'body' => '', 'error' => 'the host couldn\'t be reached', 'transient' => true];
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'error' => null, 'transient' => false];
}

/** Fetch the document at $url. Throws MbCimdError if it can't be had. Returns ['status', 'headers', 'body', ...]. */
function mb_cimd_fetch(string $url, string $host): array
{
    $dir = getenv('MASHPAD_TEST_CIMD_DIR') ?: ($_SERVER['MASHPAD_TEST_CIMD_DIR'] ?? '');
    if ($dir !== '' && is_dir($dir)) {        // end-to-end tests only: never set in production
        $f = rtrim($dir, '/') . '/' . hash('sha256', $url) . '.json';
        return is_file($f) ? ['status' => 200, 'headers' => ['content-type' => 'application/json', 'cache-control' => 'max-age=3600'], 'body' => (string) file_get_contents($f), 'error' => null, 'transient' => false]
                           : ['status' => 404, 'headers' => [], 'body' => '', 'error' => null, 'transient' => false];
    }
    $ips = mb_cimd_resolve($host);
    if (!$ips) {
        throw MbCimdError::make('Mashpad couldn\'t find ' . $host . ' to read this app\'s details.', true);
    }
    foreach ($ips as $ip) {
        if (!mb_cimd_ip_ok($ip)) {
            throw MbCimdError::make('This app\'s address points to a private or reserved place, so Mashpad won\'t fetch it.');      // every address, not just the first
        }
    }
    return mb_cimd_http_get($url, $host, $ips[0]);
}

/** How long a response says it may be kept, clamped to something sensible. */
function mb_cimd_ttl(array $headers): int
{
    $cc = strtolower($headers['cache-control'] ?? '');
    if (preg_match('/\b(no-store|no-cache)\b/', $cc)) {
        return MB_CIMD_TTL_MIN;
    }
    if (preg_match('/\bmax-age=(\d+)/', $cc, $m)) {
        return max(MB_CIMD_TTL_MIN, min(MB_CIMD_TTL_MAX, (int) $m[1]));
    }
    if (!empty($headers['expires']) && ($t = strtotime($headers['expires'])) !== false) {
        return max(MB_CIMD_TTL_MIN, min(MB_CIMD_TTL_MAX, $t - time()));
    }
    return MB_CIMD_TTL_DEFAULT;
}

/** Check a fetched document against the address it came from. Returns ['name', 'uris', 'client_uri', 'ttl']. */
function mb_cimd_parse(string $url, string $host, array $resp): array
{
    $bad = fn (string $why, bool $transient = false) => MbCimdError::make('Mashpad couldn\'t use the details ' . $host . ' gave for this app: ' . $why . '.', $transient);
    if (!empty($resp['error'])) {
        throw $bad($resp['error'], (bool) ($resp['transient'] ?? false));
    }
    $status = (int) ($resp['status'] ?? 0);
    if ($status >= 300 && $status < 400) {
        throw $bad('it redirected, and Mashpad doesn\'t follow redirects');
    }
    if ($status !== 200) {
        throw $bad('it answered with HTTP ' . $status, $status >= 500 || $status === 429);
    }
    if (!preg_match('~^application/(json|[\w.+-]+\+json)\s*(;|$)~i', (string) ($resp['headers']['content-type'] ?? ''))) {
        throw $bad('it isn\'t served as JSON');
    }
    $body = (string) ($resp['body'] ?? '');
    if (strlen($body) > MB_CIMD_MAX_BYTES) {
        throw $bad('the document is larger than ' . (MB_CIMD_MAX_BYTES / 1024) . ' KB');
    }
    $d = json_decode($body, true);
    if (!is_array($d) || ($d && array_is_list($d))) {
        throw $bad('it isn\'t a JSON object');
    }
    if (($d['client_id'] ?? null) !== $url) {
        throw $bad('its client_id isn\'t the address it was fetched from');
    }
    $uris = $d['redirect_uris'] ?? null;
    if (!is_array($uris) || !$uris || count($uris) > 10 || !array_is_list($uris)) {
        throw $bad('redirect_uris must be a list of one to ten addresses');
    }
    foreach ($uris as $u) {
        if (!is_string($u) || !mb_oauth_redirect_ok($u)) {
            throw $bad('each redirect address must be https, or http on localhost, or an app\'s own scheme, with no fragment');
        }
    }
    // Mashpad serves public clients (the code flow with PKCE) and says so: its metadata lists only "none". A document may
    // PREFER another method, as ChatGPT's does (private_key_jwt), and also list the methods the client can use
    // (token_endpoint_auth_methods_supported, MCP SEP-3149). ChatGPT's lists "none" and, when a server offers only that, uses it.
    // So a document is served when "none" is its choice or among the methods it can use, and refused when it can only
    // authenticate with a secret or a key. A document never carries a secret. (Mashpad does not check a signed client
    // assertion: it has none to check when the client uses "none".)
    $method  = $d['token_endpoint_auth_method'] ?? 'none';
    $offered = $d['token_endpoint_auth_methods_supported'] ?? null;
    if (isset($d['client_secret']) || !is_string($method) || ($method !== 'none' && !(is_array($offered) && in_array('none', $offered, true)))) {
        throw $bad('Mashpad serves public apps only, so it can\'t use a document that asks for a shared secret or key-based client authentication and doesn\'t say it can also use none');
    }
    foreach (['grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']] as $field => $allowed) {
        if (isset($d[$field])) {
            if (!is_array($d[$field]) || array_diff($d[$field], $allowed) || !in_array($allowed[0], $d[$field], true)) {
                throw $bad($field . ' must be ' . implode(' and ', $allowed) . ' (Mashpad supports the code flow with a refresh token)');
            }
        }
    }
    $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+|\s+/u', ' ', is_string($d['client_name'] ?? null) ? $d['client_name'] : ''));
    $name = $name === '' ? 'An MCP client' : (preg_match('/^.{1,120}/us', $name, $m) ? $m[0] : 'An MCP client');
    $home = null;
    if (is_string($d['client_uri'] ?? null) && strlen($d['client_uri']) <= 255 && str_starts_with($d['client_uri'], 'https://') && !preg_match('/[\s<>"\\\\]/', $d['client_uri']) && parse_url($d['client_uri'], PHP_URL_HOST)) {
        $home = $d['client_uri'];
    }
    return ['name' => $name, 'uris' => array_values(array_unique($uris)), 'client_uri' => $home, 'ttl' => mb_cimd_ttl($resp['headers'] ?? [])];
}

/**
 * The client a metadata-document address stands for: from the kept copy if it is fresh, else fetched, checked and kept.
 * If the host can't be reached, an expired copy up to a day old is used; a document that is now invalid is refused.
 */
function mb_cimd_client(PDO $pdo, string $url): array
{
    [$host] = mb_cimd_check_url($url);
    $q = $pdo->prepare("SELECT * FROM oauth_clients WHERE client_id = ? AND kind = 'metadata'");
    $q->execute([$url]);
    $row = $q->fetch() ?: null;
    $fresh = fn (array $r) => $r['expires_at'] !== null && strtotime($r['expires_at'] . ' UTC') > time();
    if ($row && $fresh($row)) {
        $row['uris'] = json_decode((string) $row['redirect_uris'], true) ?: [];
        return $row;
    }
    if (mb_wait_seconds($pdo, 'cimd', 'ip', mb_client_ip(), 3600, MB_CIMD_PER_IP, false) || mb_wait_seconds($pdo, 'cimd', 'email_normalized', $host, 3600, MB_CIMD_PER_HOST, false)) {
        if ($row && strtotime($row['expires_at'] . ' UTC') + MB_CIMD_STALE_GRACE > time()) {
            $row['uris'] = json_decode((string) $row['redirect_uris'], true) ?: [];
            return $row;
        }
        throw MbCimdError::make('Mashpad is fetching a lot of app details right now. Try connecting again in a little while.', true);
    }
    mb_attempt($pdo, 'cimd', $host, true);
    try {
        $parsed = mb_cimd_parse($url, $host, mb_cimd_fetch($url, $host));
    } catch (MbCimdError $e) {
        if ($e->transient && $row && strtotime($row['expires_at'] . ' UTC') + MB_CIMD_STALE_GRACE > time()) {
            $row['uris'] = json_decode((string) $row['redirect_uris'], true) ?: [];
            return $row;                                                  // the host is down for a moment: keep working with what was last good
        }
        throw $e;
    }
    $pdo->prepare("INSERT INTO oauth_clients (client_id, name, redirect_uris, created_ip, kind, client_uri, fetched_at, expires_at)
                   VALUES (?, ?, ?, ?, 'metadata', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? SECOND)
                   ON DUPLICATE KEY UPDATE name = VALUES(name), redirect_uris = VALUES(redirect_uris), client_uri = VALUES(client_uri), fetched_at = VALUES(fetched_at), expires_at = VALUES(expires_at)")
        ->execute([$url, $parsed['name'], json_encode($parsed['uris'], JSON_UNESCAPED_SLASHES), mb_client_ip(), $parsed['client_uri'], $parsed['ttl']]);
    if (empty($GLOBALS['mb_cimd_no_random_prune']) && random_int(1, 50) === 1) {      // now and then; a test switches this off so it can count what is tidied
        mb_cimd_prune($pdo);
    }
    $q->execute([$url]);
    $row = $q->fetch();
    $row['uris'] = $parsed['uris'];
    return $row;
}

/** Remove metadata clients nobody has used for a month: no code, no token, and not fetched since. */
function mb_cimd_prune(PDO $pdo): int
{
    $d = $pdo->prepare("DELETE FROM oauth_clients WHERE kind = 'metadata' AND fetched_at < UTC_TIMESTAMP() - INTERVAL ? DAY
                           AND NOT EXISTS (SELECT 1 FROM oauth_tokens t WHERE t.client_id = oauth_clients.id)
                           AND NOT EXISTS (SELECT 1 FROM oauth_codes c WHERE c.client_id = oauth_clients.id)");
    $d->execute([MB_CIMD_KEEP_DAYS]);
    return $d->rowCount();
}
