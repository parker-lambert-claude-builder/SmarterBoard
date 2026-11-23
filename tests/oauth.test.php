<?php
/** OAuth 2.1 for the MCP endpoint: registration, PKCE, codes, access and refresh tokens, and what gets refused. */
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
function oerr(callable $fn): string {
    try { $fn(); } catch (MbOAuthError $t) { return $t->error . ': ' . $t->getMessage(); } catch (Throwable $t) { return get_class($t) . ': ' . $t->getMessage(); }
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
require "$root/src/invites.php";
$_SERVER['HTTP_HOST'] = 'mashpad.example'; $_SERVER['HTTPS'] = 'on'; $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

$dylanId = mb_create_human($pdo, 'Dylan', 'dylan', 'd@example.com', 'correct horse battery', true);
[$agentId, $personal] = mb_create_agent($pdo, $dylanId, ['handle' => 'bodhi']);
$b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$verifier  = $b64(random_bytes(40));
$challenge = $b64(hash('sha256', $verifier, true));

// Discovery
$rm = mb_oauth_resource_metadata(); $am = mb_oauth_server_metadata();
check('resource metadata names the MCP endpoint and this site as its authorization server', $rm['resource'] === 'https://mashpad.example/mcp/' && $rm['authorization_servers'] === ['https://mashpad.example']);
check('server metadata: endpoints, code flow, S256 only, public clients, registration', $am['issuer'] === 'https://mashpad.example' && $am['authorization_endpoint'] === 'https://mashpad.example/oauth/authorize/'
      && $am['token_endpoint'] === 'https://mashpad.example/oauth/token/' && $am['registration_endpoint'] === 'https://mashpad.example/oauth/register/'
      && $am['code_challenge_methods_supported'] === ['S256'] && $am['token_endpoint_auth_methods_supported'] === ['none'] && $am['response_types_supported'] === ['code']);

check('the scopes are advertised in both documents, with offline_access, which a refresh token always grants', $rm['scopes_supported'] === ['pads', 'offline_access'] && $am['scopes_supported'] === ['pads', 'offline_access'] && in_array('refresh_token', $am['grant_types_supported'], true), json_encode([$rm['scopes_supported'], $am['scopes_supported']]));
check('a registered app is told it has both scopes', mb_oauth_register($pdo, ['redirect_uris' => ['https://scope.example/cb']])['scope'] === 'pads offline_access');

$tok32 = str_repeat('Ab3', 11) . 'x';
check('an invitation link is its own resource: the one link is the page and the connector address', mb_oauth_invite_resource($tok32) === "https://mashpad.example/join/$tok32"
      && mb_oauth_resource_metadata($tok32)['resource'] === "https://mashpad.example/join/$tok32" && mb_oauth_resource_metadata($tok32)['authorization_servers'] === ['https://mashpad.example']
      && mb_oauth_resource_metadata($tok32, false)['resource'] === "https://mashpad.example/mcp/i/$tok32");
check('the invitation can be read back out of either form of the address', mb_oauth_invite_from_resource("https://mashpad.example/join/$tok32") === $tok32 && mb_oauth_invite_from_resource("https://mashpad.example/join/$tok32/") === $tok32
      && mb_oauth_invite_from_resource("https://mashpad.example/mcp/i/$tok32") === $tok32 && mb_oauth_invite_from_resource("/.well-known/oauth-protected-resource/join/$tok32") === $tok32);
check('the ordinary address, and anything malformed, carries no invitation', mb_oauth_invite_from_resource('https://mashpad.example/mcp/') === null && mb_oauth_invite_from_resource('https://mashpad.example/join/short') === null
      && mb_oauth_invite_from_resource('https://mashpad.example/join/has-dashes-in-it-0000000000') === null && mb_oauth_invite_from_resource('https://mashpad.example/pads/join/x') === null && mb_oauth_invite_from_resource('') === null);

// Registration
$reg = mb_oauth_register($pdo, ['client_name' => "Bodhi\r\n<b>app</b>", 'redirect_uris' => ['https://app.example/callback', 'http://localhost:5173/cb']]);
check('an app registers itself and gets a public client id', str_starts_with($reg['client_id'], 'mpc_') && $reg['token_endpoint_auth_method'] === 'none' && !isset($reg['client_secret']) && $reg['client_name'] === 'Bodhi <b>app</b>');
foreach (['http://evil.example/cb' => 'plain http off localhost', 'javascript:alert(1)' => 'a script address', 'https://app.example/cb#frag' => 'a fragment', 'not a url' => 'not an address', 'data:text/html,x' => 'a data address'] as $bad => $why) {
    check("registration refuses $why", str_starts_with(oerr(fn () => mb_oauth_register($pdo, ['redirect_uris' => [$bad]])), 'invalid_redirect_uri'));
}
check('registration refuses no redirect addresses, and a confidential client', str_starts_with(oerr(fn () => mb_oauth_register($pdo, ['client_name' => 'x'])), 'invalid_redirect_uri')
      && str_starts_with(oerr(fn () => mb_oauth_register($pdo, ['redirect_uris' => ['https://a.example/cb'], 'token_endpoint_auth_method' => 'client_secret_basic'])), 'invalid_client_metadata'));
check('a native app\'s own scheme is accepted', str_starts_with(mb_oauth_register($pdo, ['redirect_uris' => ['com.example.app:/oauth']])['client_id'], 'mpc_'));

// The authorization request
$client = mb_oauth_client($pdo, $reg['client_id']);
$req = ['response_type' => 'code', 'client_id' => $reg['client_id'], 'redirect_uri' => 'https://app.example/callback', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'xyz'];
[$c, $redirect] = mb_oauth_check_request($pdo, $req);
check('a well-formed request is accepted', (int) $c['id'] === (int) $client['id'] && $redirect === 'https://app.example/callback');
check('an unknown app is stopped on our page, not redirected', str_starts_with(oerr(fn () => mb_oauth_check_request($pdo, ['client_id' => 'mpc_nope'] + $req)), 'MbAccountError'));
check('a redirect address the app didn\'t register is stopped on our page', str_starts_with(oerr(fn () => mb_oauth_check_request($pdo, ['redirect_uri' => 'https://app.example/callback/extra'] + $req)), 'MbAccountError')
      && str_starts_with(oerr(fn () => mb_oauth_check_request($pdo, ['redirect_uri' => 'https://evil.example/callback'] + $req)), 'MbAccountError'));
check('PKCE is required, and only S256', str_starts_with(oerr(fn () => mb_oauth_check_request($pdo, array_diff_key($req, ['code_challenge' => 1]))), 'invalid_request')
      && str_starts_with(oerr(fn () => mb_oauth_check_request($pdo, ['code_challenge_method' => 'plain'] + $req)), 'invalid_request'));
check('only the code flow', str_starts_with(oerr(fn () => mb_oauth_check_request($pdo, ['response_type' => 'token'] + $req)), 'unsupported_response_type'));
$back = mb_oauth_redirect('https://app.example/callback?x=1', ['code' => 'abc', 'state' => 'xyz']);
check('the answer goes back on the registered address with the state and the issuer', $back === 'https://app.example/callback?x=1&code=abc&state=xyz&iss=' . rawurlencode('https://mashpad.example'));

// Code -> tokens
$code = mb_oauth_make_code($pdo, $client, $agentId, $redirect, $challenge);
$form = ['grant_type' => 'authorization_code', 'client_id' => $reg['client_id'], 'code' => $code, 'redirect_uri' => $redirect, 'code_verifier' => $verifier];
check('only the code\'s hash is stored', !$pdo->query('SELECT COUNT(*) FROM oauth_codes WHERE code_hash = ' . $pdo->quote($code))->fetchColumn());
check('the wrong PKCE verifier is refused', str_starts_with(oerr(fn () => mb_oauth_token($pdo, ['code_verifier' => $b64(random_bytes(40))] + $form)), 'invalid_grant'));
check('a different app can\'t use the code', str_starts_with(oerr(fn () => mb_oauth_token($pdo, ['client_id' => mb_oauth_register($pdo, ['redirect_uris' => ['https://other.example/cb']])['client_id']] + $form)), 'invalid_grant'));
check('a different redirect address is refused', str_starts_with(oerr(fn () => mb_oauth_token($pdo, ['redirect_uri' => 'http://localhost:5173/cb'] + $form)), 'invalid_grant'));
$tok = mb_oauth_token($pdo, $form);
check('the right verifier gets an access token and a refresh token', $tok['token_type'] === 'Bearer' && str_starts_with($tok['access_token'], 'mp_o_') && str_starts_with($tok['refresh_token'], 'mp_r_') && $tok['expires_in'] === 86400);
check('and the response says it granted both scopes, since a refresh token always comes with it', $tok['scope'] === 'pads offline_access' && isset($tok['refresh_token']));
check('only token hashes are stored', !$pdo->query('SELECT COUNT(*) FROM oauth_tokens WHERE access_hash = ' . $pdo->quote($tok['access_token']) . ' OR refresh_hash = ' . $pdo->quote($tok['refresh_token']))->fetchColumn());
$who = mb_oauth_agent($pdo, $tok['access_token']);
check('the access token stands for the agent that was approved', $who && (int) $who['id'] === $agentId && $who['handle'] === 'bodhi');
check('a personal token is not an OAuth token, and the reverse', mb_oauth_agent($pdo, $personal) === null && mb_agent_by_token($pdo, $tok['access_token']) === null && mb_oauth_agent($pdo, 'mp_o_nope') === null);
check('a refresh token is not an access token', mb_oauth_agent($pdo, $tok['refresh_token']) === null);
check('a code works once; using it again withdraws what it produced', str_starts_with(oerr(fn () => mb_oauth_token($pdo, $form)), 'invalid_grant') && mb_oauth_agent($pdo, $tok['access_token']) === null);

// Refresh
$code2 = mb_oauth_make_code($pdo, $client, $agentId, $redirect, $challenge);
$t1 = mb_oauth_token($pdo, ['code' => $code2] + $form);
$t2 = mb_oauth_token($pdo, ['grant_type' => 'refresh_token', 'client_id' => $reg['client_id'], 'refresh_token' => $t1['refresh_token']]);
check('refreshing gives new tokens and retires the old ones', $t2['access_token'] !== $t1['access_token'] && (int) mb_oauth_agent($pdo, $t2['access_token'])['id'] === $agentId && mb_oauth_agent($pdo, $t1['access_token']) === null);
check('a refresh token works once', str_starts_with(oerr(fn () => mb_oauth_token($pdo, ['grant_type' => 'refresh_token', 'client_id' => $reg['client_id'], 'refresh_token' => $t1['refresh_token']])), 'invalid_grant'));
$pdo->exec('UPDATE oauth_tokens SET access_expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE revoked_at IS NULL');
check('an expired access token is refused, and refreshing restores access', mb_oauth_agent($pdo, $t2['access_token']) === null
      && mb_oauth_agent($pdo, mb_oauth_token($pdo, ['grant_type' => 'refresh_token', 'client_id' => $reg['client_id'], 'refresh_token' => $t2['refresh_token']])['access_token']) !== null);
$exp = mb_oauth_make_code($pdo, $client, $agentId, $redirect, $challenge);
$pdo->exec('UPDATE oauth_codes SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE used_at IS NULL');
check('an expired code is refused', str_starts_with(oerr(fn () => mb_oauth_token($pdo, ['code' => $exp] + $form)), 'invalid_grant'));
check('unknown grant types and unknown apps are refused', str_starts_with(oerr(fn () => mb_oauth_token($pdo, ['grant_type' => 'password', 'client_id' => $reg['client_id']])), 'unsupported_grant_type')
      && str_starts_with(oerr(fn () => mb_oauth_token($pdo, ['grant_type' => 'authorization_code', 'client_id' => 'mpc_nope'])), 'invalid_client'));

// An agent whose app is signed in needs no token and no request code
$owner2 = mb_create_human($pdo, 'Olive', 'olive', 'o@example.com', 'correct horse battery');
$gpad   = mb_create_pad($pdo, mb_principal($pdo, $owner2), 'Scheduling');
$ginv   = mb_invite_make($pdo, $gpad, $owner2);
[$guest, $none] = mb_invite_guest_agent($pdo, $gpad, ['handle' => 'sam-assistant', 'acts_for' => 'Sam'], false);
mb_invite_use($pdo, $ginv, $gpad, $guest);
check('a guest created by the sign-in page has no token and is not yet signed in', $none === null && !mb_agent_has_token($pdo, (int) $guest['id']) && !mb_agent_signed_in($pdo, (int) $guest['id']));
$gt = mb_oauth_token($pdo, ['code' => mb_oauth_make_code($pdo, $client, (int) $guest['id'], $redirect, $challenge)] + $form);
check('once its app has collected its credential it counts as able to reach Mashpad, with no personal token', mb_agent_signed_in($pdo, (int) $guest['id']) && mb_agent_has_token($pdo, (int) $guest['id'])
      && (int) $pdo->query("SELECT COUNT(*) FROM tokens WHERE principal_id = {$guest['id']} AND revoked_at IS NULL")->fetchColumn() === 0);
$wait = mb_pending_for($pdo, (int) $guest['id']);
check('the pads it is waiting on can be named back to it', count($wait) === 1 && $wait[0]['ref'] === 'olive/scheduling' && $wait[0]['slug'] === 'scheduling' && $wait[0]['owner'] === 'olive' && mb_pending_for($pdo, $agentId) === []);
$pdo->exec("UPDATE oauth_tokens SET access_expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE principal_id = {$guest['id']}");
check('an expired access token with a live refresh token still counts as signed in', mb_agent_signed_in($pdo, (int) $guest['id']));
mb_revoke_agent_tokens($pdo, (int) $guest['id']);
check('disconnected, it is no longer signed in', !mb_agent_signed_in($pdo, (int) $guest['id']) && !mb_agent_has_token($pdo, (int) $guest['id']));

// Disconnecting
$t3 = mb_oauth_token($pdo, ['code' => mb_oauth_make_code($pdo, $client, $agentId, $redirect, $challenge)] + $form);
$conns = mb_oauth_connections($pdo, $agentId);
check('Your agents can show which app an agent is connected through', count($conns) === 1 && $conns[0]['name'] === 'Bodhi <b>app</b>' && (int) $conns[0]['since'] > 0);
mb_revoke_agent_tokens($pdo, $agentId);
check('disconnecting an agent signs out every app and kills its personal token', mb_oauth_agent($pdo, $t3['access_token']) === null && mb_agent_by_token($pdo, $personal) === null && mb_oauth_connections($pdo, $agentId) === []
      && str_starts_with(oerr(fn () => mb_oauth_token($pdo, ['grant_type' => 'refresh_token', 'client_id' => $reg['client_id'], 'refresh_token' => $t3['refresh_token']])), 'invalid_grant'));
$t4 = mb_oauth_token($pdo, ['code' => mb_oauth_make_code($pdo, $client, $agentId, $redirect, $challenge)] + $form);
mb_delete_agent($pdo, $dylanId, $agentId);
check('removing an agent ends its connections', mb_oauth_agent($pdo, $t4['access_token']) === null);
for ($i = 0; $i < MB_OAUTH_REGISTER_MAX; $i++) { try { mb_oauth_register($pdo, ['redirect_uris' => ["https://flood$i.example/cb"]]); } catch (MbOAuthError $e) { break; } }
check('registrations from one address are limited per hour', str_starts_with(oerr(fn () => mb_oauth_register($pdo, ['redirect_uris' => ['https://one-more.example/cb']])), 'temporarily_unavailable'));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
