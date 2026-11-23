<?php
/** Pad items: types, values, versions, permissions, limits, events. */
declare(strict_types=1);

$root = dirname(__DIR__);
$env  = fn ($k, $d = '') => getenv($k) !== false ? (string) getenv($k) : $d;
$db   = ['host' => $env('MASHPAD_TEST_DB_HOST', '127.0.0.1'), 'port' => (int) $env('MASHPAD_TEST_DB_PORT', '3306'),
         'name' => $env('MASHPAD_TEST_DB_NAME'), 'user' => $env('MASHPAD_TEST_DB_USER'), 'pass' => $env('MASHPAD_TEST_DB_PASS')];
if (!str_starts_with($db['name'], 'mashpad_test')) {
    fwrite(STDERR, "skipped: set MASHPAD_TEST_DB_NAME to a disposable database named mashpad_test*\n");
    exit(0);
}
$files = ["$root/data/config.php", "$root/data/schema-version", "$root/data/.last-pad-prune"];
$saved = [];
foreach ($files as $f) { if (is_file($f)) { $saved[$f] = file_get_contents($f); unlink($f); } }
register_shutdown_function(function () use ($files, $saved) {
    foreach ($files as $f) { @unlink($f); if (isset($saved[$f])) file_put_contents($f, $saved[$f]); }
});
$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail; $ok ? $pass++ : $fail++;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
function err(callable $fn): string {
    try { $fn(); } catch (Throwable $t) { return get_class($t) . ': ' . $t->getMessage(); }
    return '(no error)';
}
require "$root/src/db.php";
$pdo = mb_db_connect($db);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec("DROP TABLE `$t`"); }
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
mb_migrate($pdo);
mb_write_config($db);
require "$root/src/oauth.php";

$b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
// What the world looks like to the code: DNS and HTTP are replaced, everything else is real.
$calls = []; $reply = null; $ips = ['93.184.216.34'];
$GLOBALS['mb_cimd_resolver'] = function ($host) use (&$ips) { return $ips; };
$GLOBALS['mb_cimd_http']     = function ($url, $host, $ip) use (&$calls, &$reply) { $calls[] = [$url, $host, $ip]; return is_callable($reply) ? $reply($url) : $reply; };
$url = 'https://claude.example/oauth/client.json';
$doc = fn (array $over = []) => array_merge(['client_id' => $url, 'client_name' => 'Claude', 'redirect_uris' => ['https://claude.example/api/mcp/auth_callback'], 'token_endpoint_auth_method' => 'none',
                                              'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']], $over);
$ok = fn (array $d, array $over = []) => array_merge(['status' => 200, 'headers' => ['content-type' => 'application/json', 'cache-control' => 'max-age=3600'], 'body' => json_encode($d), 'error' => null, 'transient' => false], $over);
$why = fn (callable $f) => err($f);

// ---- the address
foreach (['https://claude.example/oauth/client.json', 'https://app.example.com/.well-known/mcp-client', 'https://a.example/x?y=1', 'https://sub.domain.example:443/path/client.json'] as $good) {
    check("an address that is fine: $good", mb_cimd_check_url($good)[1] !== '', $good);
}
foreach ([['http://claude.example/client.json', 'https address'], ['https://claude.example/', 'needs a path'], ['https://claude.example', 'needs a path'], ['https://user:pw@claude.example/c.json', 'user name or password'],
          ['https://claude.example:8443/c.json', 'port other than 443'], ['https://claude.example/c.json#frag', 'fragment'], ['https://127.0.0.1/c.json', 'IP address'], ['https://[::1]/c.json', 'IP address'], ['https://10.0.0.1/c.json', 'IP address'],
          ['https://localhost/c.json', 'public one'], ['https://printer.local/c.json', 'internal'], ['https://db.internal/c.json', 'internal'], ['https://nas.lan/c.json', 'internal'], ['https://x.localhost/c.json', 'internal'],
          ['https://claude.example/a/../c.json', 'has "." or ".."'], ['https://claude.example/a/%2e%2e/c.json', 'has "." or ".."'], ['https://claude.example/./c.json', 'has "." or ".."'],
          ['https://claude.example/c json', 'spaces'], ["https://claude.example/c\x00.json", 'control'], ['https://claude.example/' . str_repeat('a', 200), 'longer than 191'], ['https://nodots/c.json', 'public one'],
          ['ftp://claude.example/c.json', 'https address'], ['https://-bad.example/c.json', 'public one'], ['https://claude.example/c.json"', 'spaces']] as [$bad, $needle]) {
    check("an address that is refused: " . substr($bad, 0, 60) . " ($needle)", str_contains($e = err(fn () => mb_cimd_check_url($bad)), 'MbCimdError') && str_contains($e, $needle), $e);
}
check('what it says about an address is plain', str_contains(err(fn () => mb_cimd_check_url('https://127.0.0.1/c.json')), 'This app\'s address can\'t be used to identify it: it is an IP address, not a hostname.'));
check('what makes an app known is the string it gave: case in the path is kept', mb_cimd_check_url('https://Claude.Example/OAuth/Client.json') === ['claude.example', '/OAuth/Client.json']);

// ---- which addresses a server may connect to
foreach (['93.184.216.34', '8.8.8.8', '1.1.1.1', '2606:2800:220:1:248:1893:25c8:1946', '2001:4860:4860::8888', '151.101.1.69'] as $pub) { check("a public address is allowed: $pub", mb_cimd_ip_ok($pub) === true); }
foreach (['127.0.0.1', '127.1.2.3', '10.0.0.1', '10.255.255.255', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254', '100.64.0.1', '100.127.255.255', '0.0.0.0', '0.1.2.3', '192.0.0.8', '192.0.2.1',
          '198.18.0.1', '198.51.100.7', '203.0.113.9', '224.0.0.1', '239.255.255.250', '240.0.0.1', '255.255.255.255', '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'ff02::1', '2001:db8::1', '100::1',
          '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '64:ff9b::7f00:1', '2002:7f00:1::1', '2002:c0a8:101::1', 'not an address', ''] as $priv) {
    check("an internal or reserved address is refused: $priv", mb_cimd_ip_ok($priv) === false);
}
check('172.15 and 172.32 are public, 100.63 and 100.128 are public: the blocks are exact', mb_cimd_ip_ok('172.15.255.255') === true && mb_cimd_ip_ok('172.32.0.1') === true && mb_cimd_ip_ok('100.63.255.255') === true && mb_cimd_ip_ok('100.128.0.1') === true);
check('an IPv4-mapped public address is judged by the address inside', mb_cimd_ip_ok('::ffff:93.184.216.34') === true && mb_cimd_ip_ok('2002:5db8:d822::1') === true);

// ---- the fetch: DNS first, every address checked, the connection pinned
$calls = []; $reply = $ok($doc());
mb_cimd_fetch($url, 'claude.example');
check('a name that resolves to a public address is fetched, connecting to that address', count($calls) === 1 && $calls[0] === [$url, 'claude.example', '93.184.216.34']);
foreach ([['10.0.0.5'], ['127.0.0.1'], ['169.254.169.254'], ['93.184.216.34', '10.0.0.5'], ['10.0.0.5', '93.184.216.34'], ['::ffff:127.0.0.1'], ['::1']] as $mix) {
    $ips = $mix; $calls = [];
    $e = err(fn () => mb_cimd_fetch($url, 'claude.example'));
    check('a name that resolves to anything internal is refused, whichever address comes first: ' . implode(' ', $mix), str_contains($e, 'private or reserved') && $calls === [], $e);
}
$ips = []; $calls = [];
check('a name that does not resolve is said so, and worth trying again', str_contains($e = err(fn () => mb_cimd_fetch($url, 'claude.example')), 'couldn\'t find claude.example') && $calls === []);
try { mb_cimd_fetch($url, 'claude.example'); } catch (MbCimdError $x) { $t = $x->transient; }
check('and that is marked transient', ($t ?? false) === true);
$ips = ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']; $calls = []; mb_cimd_fetch($url, 'claude.example');
check('an IPv6 address is pinned as given, and the first one resolved is the one used', $calls[0][2] === '2606:2800:220:1:248:1893:25c8:1946');
$ips = ['93.184.216.34'];

// ---- the document
$p = fn (array $d, array $over = []) => mb_cimd_parse($url, 'claude.example', $ok($d, $over));
$r = $p($doc());
check('a good document gives the name, the return addresses, and how long to keep it', $r['name'] === 'Claude' && $r['uris'] === ['https://claude.example/api/mcp/auth_callback'] && $r['ttl'] === 3600 && $r['client_uri'] === null);
check('a different client_id is refused: it must be the address it came from', str_contains($why(fn () => $p($doc(['client_id' => 'https://evil.example/client.json']))), 'its client_id isn\'t the address it was fetched from')
      && str_contains($why(fn () => $p($doc(['client_id' => $url . '/']))), 'client_id') && str_contains($why(fn () => $p(array_diff_key($doc(), ['client_id' => 1]))), 'client_id') && str_contains($why(fn () => $p($doc(['client_id' => strtoupper($url)]))), 'client_id'));
foreach ([['redirect_uris', null], ['redirect_uris', []], ['redirect_uris', 'https://a.example/cb'], ['redirect_uris', ['https://a.example/cb#x']], ['redirect_uris', ['javascript:alert(1)']], ['redirect_uris', ['http://evil.example/cb']],
          ['redirect_uris', [5]], ['redirect_uris', array_map(fn ($i) => "https://a.example/cb$i", range(1, 11))], ['redirect_uris', ['a' => 'https://a.example/cb']]] as [$f, $v]) {
    check('a bad redirect_uris is refused: ' . json_encode($v), str_contains($why(fn () => $p($doc([$f => $v]))), 'redirect'), $why(fn () => $p($doc([$f => $v]))));
}
check('http on localhost and an app\'s own scheme are accepted, as for registered apps', $p($doc(['redirect_uris' => ['http://localhost:5173/cb', 'com.example.app:/oauth']]))['uris'] === ['http://localhost:5173/cb', 'com.example.app:/oauth']);
foreach ([['token_endpoint_auth_method', 'client_secret_basic'], ['token_endpoint_auth_method', 'client_secret_post'], ['token_endpoint_auth_method', 'private_key_jwt'], ['client_secret', 'shh']] as [$f, $v]) {
    check("a document that asks for $f=$v is refused: public apps only", str_contains($why(fn () => $p($doc([$f => $v]))), 'public apps only'));
}
check('"none" or nothing is fine', $p($doc(['token_endpoint_auth_method' => 'none']))['name'] === 'Claude' && $p(array_diff_key($doc(), ['token_endpoint_auth_method' => 1]))['name'] === 'Claude');
check('grant and response types must be the code flow', str_contains($why(fn () => $p($doc(['grant_types' => ['implicit']]))), 'grant_types') && str_contains($why(fn () => $p($doc(['grant_types' => ['client_credentials']]))), 'grant_types')
      && str_contains($why(fn () => $p($doc(['grant_types' => ['refresh_token']]))), 'grant_types') && str_contains($why(fn () => $p($doc(['response_types' => ['token']]))), 'response_types') && $p($doc(['grant_types' => ['authorization_code']]))['name'] === 'Claude');
check('a name is cleaned: line breaks and tags are text, it is cut at 120, and none means a plain default', $p($doc(['client_name' => "Cl\r\naude <b>app</b>"]))['name'] === 'Cl aude <b>app</b>' && mb_strlen($p($doc(['client_name' => str_repeat('é', 300)]))['name']) === 120
      && $p($doc(['client_name' => '']))['name'] === 'An MCP client' && $p($doc(['client_name' => ['x']]))['name'] === 'An MCP client' && $p(array_diff_key($doc(), ['client_name' => 1]))['name'] === 'An MCP client');
check('a home page is kept only if it is a plain https address', $p($doc(['client_uri' => 'https://claude.example/']))['client_uri'] === 'https://claude.example/' && $p($doc(['client_uri' => 'javascript:alert(1)']))['client_uri'] === null
      && $p($doc(['client_uri' => 'https://a.example/"><script>']))['client_uri'] === null && $p($doc(['client_uri' => 'http://claude.example/']))['client_uri'] === null);
check('a logo is neither fetched nor kept', !array_key_exists('logo_uri', $p($doc(['logo_uri' => 'https://claude.example/logo.png']))));
check('unknown fields are ignored', $p($doc(['jwks_uri' => 'https://x.example/j', 'whatever' => [1, 2]]))['name'] === 'Claude');
check('it must come back as JSON, however it says so', str_contains($why(fn () => $p($doc(), ['headers' => ['content-type' => 'text/html']])), 'isn\'t served as JSON') && str_contains($why(fn () => $p($doc(), ['headers' => []])), 'JSON')
      && $p($doc(), ['headers' => ['content-type' => 'application/json; charset=utf-8']])['name'] === 'Claude' && $p($doc(), ['headers' => ['content-type' => 'application/jrd+json']])['name'] === 'Claude' && str_contains($why(fn () => $p($doc(), ['headers' => ['content-type' => 'application/jsonp']])), 'JSON'));
check('a document that is not a JSON object is refused', str_contains($why(fn () => $p($doc(), ['body' => 'not json'])), 'isn\'t a JSON object') && str_contains($why(fn () => $p($doc(), ['body' => '[1,2]'])), 'JSON object') && str_contains($why(fn () => $p($doc(), ['body' => '"x"'])), 'JSON object') && str_contains($why(fn () => $p($doc(), ['body' => ''])), 'JSON object'));
check('one over 5 KB is refused', str_contains($why(fn () => $p($doc(['client_name' => str_repeat('x', 6000)]))), 'larger than 5 KB'));
check('a redirect is refused, saying that redirects are not followed', str_contains($why(fn () => $p($doc(), ['status' => 301])), 'redirected, and Mashpad doesn\'t follow redirects') && str_contains($why(fn () => $p($doc(), ['status' => 307])), 'redirect'));
check('other statuses are named, and only a busy or broken host is worth retrying', str_contains($why(fn () => $p($doc(), ['status' => 404])), 'HTTP 404'));
foreach ([[404, false], [403, false], [500, true], [503, true], [429, true]] as [$st, $tr]) {
    try { $p($doc(), ['status' => $st]); } catch (MbCimdError $x) { check("HTTP $st is " . ($tr ? '' : 'not ') . 'transient', $x->transient === $tr); }
}
try { $p($doc(), ['error' => 'the host couldn\'t be reached', 'transient' => true, 'status' => 0]); } catch (MbCimdError $x) { check('a host that can\'t be reached is transient, and says so', $x->transient === true && str_contains($x->getMessage(), 'the host couldn\'t be reached')); }
check('the host is named in what a person is told', str_contains($why(fn () => $p($doc(), ['status' => 404])), 'claude.example'));

// ---- how long to keep it
check('cache time: a minute is lifted to five, a day is the most, no-store and no-cache mean the least, and no word means an hour',
      mb_cimd_ttl(['cache-control' => 'max-age=60']) === 300 && mb_cimd_ttl(['cache-control' => 'public, max-age=7200']) === 7200 && mb_cimd_ttl(['cache-control' => 'max-age=99999999']) === 86400
      && mb_cimd_ttl(['cache-control' => 'no-store']) === 300 && mb_cimd_ttl(['cache-control' => 'no-cache, max-age=9999']) === 300 && mb_cimd_ttl([]) === 3600);
check('an Expires header is used when there is no max-age, with the same limits', mb_cimd_ttl(['expires' => gmdate('D, d M Y H:i:s', time() + 7200) . ' GMT']) >= 7100 && mb_cimd_ttl(['expires' => gmdate('D, d M Y H:i:s', time() - 100) . ' GMT']) === 300 && mb_cimd_ttl(['expires' => 'garbage']) === 3600);

// ---- keeping it, and refreshing it
$calls = []; $reply = $ok($doc());
$c1 = mb_cimd_client($pdo, $url);
check('the first time, the document is fetched and kept as a client of its own kind', count($calls) === 1 && $c1['kind'] === 'metadata' && $c1['name'] === 'Claude' && $c1['uris'] === ['https://claude.example/api/mcp/auth_callback'] && $c1['client_id'] === $url && $c1['expires_at'] !== null);
$c2 = mb_cimd_client($pdo, $url);
check('the second time, while it is fresh, nothing is fetched and it is the same client', count($calls) === 1 && (int) $c2['id'] === (int) $c1['id']);
$pdo->exec("UPDATE oauth_clients SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE client_id = " . $pdo->quote($url));
$reply = $ok($doc(['client_name' => 'Claude (new name)', 'redirect_uris' => ['https://claude.example/new/callback']]));
$c3 = mb_cimd_client($pdo, $url);
check('once it has expired it is fetched again, and what changed is used: same client row, new name and addresses', count($calls) === 2 && (int) $c3['id'] === (int) $c1['id'] && $c3['name'] === 'Claude (new name)' && $c3['uris'] === ['https://claude.example/new/callback']);
check('there is one row for it, not one per fetch', (int) $pdo->query("SELECT COUNT(*) FROM oauth_clients WHERE client_id = " . $pdo->quote($url))->fetchColumn() === 1);
$pdo->exec("UPDATE oauth_clients SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE client_id = " . $pdo->quote($url));
$reply = $ok($doc(), ['status' => 503]);
$c4 = mb_cimd_client($pdo, $url);
check('if the host is down for a moment, the last good copy is used', $c4['name'] === 'Claude (new name)' && (int) $c4['id'] === (int) $c1['id']);
$reply = $ok($doc(), ['status' => 0, 'error' => 'the host couldn\'t be reached', 'transient' => true]);
check('and the same when it can\'t be reached at all', mb_cimd_client($pdo, $url)['name'] === 'Claude (new name)');
$reply = $ok($doc(['redirect_uris' => ['http://evil.example/cb']]));
check('but a document that is now invalid is refused, and the old copy is not quietly kept', str_contains(err(fn () => mb_cimd_client($pdo, $url)), 'redirect'));
$reply = $ok($doc(), ['status' => 404]);
check('as is one that has gone (a 404 is not a brief outage)', str_contains(err(fn () => mb_cimd_client($pdo, $url)), 'HTTP 404'));
$pdo->exec("UPDATE oauth_clients SET expires_at = UTC_TIMESTAMP() - INTERVAL 2 DAY WHERE client_id = " . $pdo->quote($url));
$reply = $ok($doc(), ['status' => 503]);
check('a copy that expired more than a day ago is not used even when the host is down', str_contains(err(fn () => mb_cimd_client($pdo, $url)), 'HTTP 503'));
$other = 'https://other.example/c.json'; $reply = $ok($doc(['client_id' => $other]), ['status' => 404]);
check('an address that has never been fetched and fails leaves no row behind', str_contains(err(fn () => mb_cimd_client($pdo, $other)), 'HTTP 404') && (int) $pdo->query("SELECT COUNT(*) FROM oauth_clients WHERE client_id = " . $pdo->quote($other))->fetchColumn() === 0);
check('an address that can\'t be used is refused before anything is fetched', ($n = count($calls)) >= 0 && str_contains(err(fn () => mb_cimd_client($pdo, 'https://127.0.0.1/c.json')), 'IP address') && count($calls) === $n);

// ---- rate limits
$pdo->exec("DELETE FROM auth_attempts");
for ($i = 0; $i < MB_CIMD_PER_IP; $i++) { mb_attempt($pdo, 'cimd', "host$i.example", true); }
$fresh = 'https://new-app.example/c.json'; $reply = $ok($doc(['client_id' => $fresh])); $n = count($calls);
$e = err(fn () => mb_cimd_client($pdo, $fresh));
check('one address asking for too many apps\' details an hour is told to wait, and nothing is fetched', str_contains($e, 'fetching a lot of app details') && count($calls) === $n, $e);
check('but an app already kept and fresh is still served, since nothing is fetched', ($pdo->exec("UPDATE oauth_clients SET expires_at = UTC_TIMESTAMP() + INTERVAL 1 HOUR WHERE client_id = " . $pdo->quote($url)) || true) && mb_cimd_client($pdo, $url)['name'] === 'Claude (new name)');
$pdo->exec("UPDATE oauth_clients SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE client_id = " . $pdo->quote($url));
check('and one that has just expired is served from its last copy rather than refused', mb_cimd_client($pdo, $url)['name'] === 'Claude (new name)' && count($calls) === $n);
$pdo->exec("DELETE FROM auth_attempts");
for ($i = 0; $i < MB_CIMD_PER_HOST; $i++) { $pdo->prepare("INSERT INTO auth_attempts (kind, ip, email_normalized, success) VALUES ('cimd', ?, 'busy.example', 1)")->execute(["203.0.113.$i"]); }
$busy = 'https://busy.example/c.json'; $reply = $ok($doc(['client_id' => $busy]));
check('one host can\'t be fetched from without limit, whoever asks', str_contains(err(fn () => mb_cimd_client($pdo, $busy)), 'fetching a lot') && count($calls) === $n);
$pdo->exec("DELETE FROM auth_attempts");

// ---- the rest of sign-in uses it like any client
$pdo->exec("DELETE FROM oauth_clients"); $calls = []; $reply = $ok($doc());
$humanId = mb_create_human($pdo, 'Dylan', 'dylan', 'd@example.com', 'correct horse battery'); [$agentId] = mb_create_agent($pdo, $humanId, ['handle' => 'claude-app']);
$verifier = $b64(random_bytes(40)); $challenge = $b64(hash('sha256', $verifier, true));
$req = ['response_type' => 'code', 'client_id' => $url, 'redirect_uri' => 'https://claude.example/api/mcp/auth_callback', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'xyz'];
[$client, $redirect] = mb_oauth_check_request($pdo, $req);
check('an authorization request from an app named by its document is accepted, and is the kept client', $client['client_id'] === $url && $client['kind'] === 'metadata' && $redirect === 'https://claude.example/api/mcp/auth_callback' && count($calls) === 1);
check('the return address must be one the document lists', str_contains(err(fn () => mb_oauth_check_request($pdo, ['redirect_uri' => 'https://claude.example/api/mcp/other'] + $req)), 'an address it didn\'t register')
      && str_contains(err(fn () => mb_oauth_check_request($pdo, ['redirect_uri' => 'https://evil.example/cb'] + $req)), 'MbAccountError'));
check('one document can\'t be used for another app\'s address', str_contains(err(fn () => mb_oauth_check_request($pdo, ['client_id' => 'https://evil.example/c.json'] + $req)), 'MbCimdError') || str_contains(err(fn () => mb_oauth_check_request($pdo, ['client_id' => 'https://evil.example/c.json'] + $req)), 'MbAccountError'));
check('PKCE is still required', str_starts_with(err(fn () => mb_oauth_check_request($pdo, array_diff_key($req, ['code_challenge' => 1]))), 'MbOAuthError'));
$code = mb_oauth_make_code($pdo, $client, $agentId, $redirect, $challenge);
$before = count($calls);
$tok = mb_oauth_token($pdo, ['grant_type' => 'authorization_code', 'client_id' => $url, 'code' => $code, 'redirect_uri' => $redirect, 'code_verifier' => $verifier]);
check('the code is exchanged using the address as client_id, for an access token and a refresh token, without fetching again', str_starts_with($tok['access_token'], 'mp_o') && isset($tok['refresh_token']) && count($calls) === $before);
$t2 = mb_oauth_token($pdo, ['grant_type' => 'refresh_token', 'client_id' => $url, 'refresh_token' => $tok['refresh_token']]);
check('and refreshed the same way', str_starts_with($t2['access_token'], 'mp_o') && $t2['refresh_token'] !== $tok['refresh_token'] && mb_oauth_agent($pdo, $t2['access_token']) !== null);
check('a different app can\'t use the code or the refresh token', str_contains(err(fn () => mb_oauth_token($pdo, ['grant_type' => 'refresh_token', 'client_id' => 'https://other.example/c.json', 'refresh_token' => $t2['refresh_token']])), 'MbCimdError') || str_contains(err(fn () => mb_oauth_token($pdo, ['grant_type' => 'refresh_token', 'client_id' => 'https://other.example/c.json', 'refresh_token' => $t2['refresh_token']])), 'invalid'));
$reg = mb_oauth_register($pdo, ['client_name' => 'Old way', 'redirect_uris' => ['https://old.example/cb']]);
check('a registered app still works exactly as before, alongside', mb_oauth_client($pdo, $reg['client_id'])['kind'] === 'registered' && mb_oauth_client($pdo, $reg['client_id'])['name'] === 'Old way' && mb_oauth_client($pdo, 'mpc_nope') === null && mb_oauth_client($pdo, '') === null && mb_oauth_client($pdo, str_repeat('x', 100)) === null);
check('and a registered ID is never mistaken for an address, nor an address for a registered ID', mb_cimd_is_id($url) && !mb_cimd_is_id($reg['client_id']) && !mb_cimd_is_id('http://claude.example/c.json') && !mb_cimd_is_id('HTTPS://claude.example/c.json'));
$am = mb_oauth_server_metadata();
check('the server says it supports metadata documents, and still has registration', $am['client_id_metadata_document_supported'] === true && str_ends_with($am['registration_endpoint'], 'oauth/register/'));

// ---- tidying
$pdo->exec("UPDATE oauth_clients SET fetched_at = UTC_TIMESTAMP() - INTERVAL 40 DAY WHERE client_id = " . $pdo->quote($url));
$old = 'https://stale.example/c.json'; $reply = $ok($doc(['client_id' => $old])); mb_cimd_client($pdo, $old);
$pdo->exec("UPDATE oauth_clients SET fetched_at = UTC_TIMESTAMP() - INTERVAL 40 DAY WHERE client_id = " . $pdo->quote($old));
$young = 'https://young.example/c.json'; $reply = $ok($doc(['client_id' => $young])); mb_cimd_client($pdo, $young);
check('tidying removes an old entry nobody used, and keeps one with tokens, one that is recent, and registered apps', mb_cimd_prune($pdo) === 1
      && (int) $pdo->query("SELECT COUNT(*) FROM oauth_clients WHERE client_id = " . $pdo->quote($old))->fetchColumn() === 0 && (int) $pdo->query("SELECT COUNT(*) FROM oauth_clients WHERE client_id = " . $pdo->quote($url))->fetchColumn() === 1
      && (int) $pdo->query("SELECT COUNT(*) FROM oauth_clients WHERE client_id = " . $pdo->quote($young))->fetchColumn() === 1 && mb_oauth_client($pdo, $reg['client_id']) !== null);
check('the table takes an address of the longest length', strlen(($long = 'https://a.example/' . str_repeat('p', MB_CIMD_MAX_URL - 18))) === MB_CIMD_MAX_URL && ($reply = $ok($doc(['client_id' => $long]))) && mb_cimd_client($pdo, $long)['client_id'] === $long);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
