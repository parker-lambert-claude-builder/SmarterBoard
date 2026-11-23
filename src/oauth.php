<?php
/**
 * Mashpad — OAuth 2.1 for the MCP endpoint.
 *
 * Why: an agent's app usually keeps credentials in a vault the agent itself
 * can't write to. A raw token therefore ends with a person copying and pasting
 * it. With OAuth the app sends the person to Mashpad, they approve once, and the
 * app receives and stores the credential on its own. Nobody is shown a token.
 *
 * What is implemented (the MCP authorization flow):
 * - Protected resource metadata (RFC 9728) and authorization server metadata
 *   (RFC 8414), so an app can find everything from the MCP address alone.
 * - Dynamic client registration (RFC 7591): the app registers itself.
 * - Authorization code with PKCE (S256 only). Public clients, no client secret.
 * - Access tokens (a day) and rotating refresh tokens (90 days, renewed on use).
 *
 * An access token stands for one agent, exactly as a personal token does: the
 * consent screen is where the person says which agent this connection is.
 * Personal tokens, lobby codes and request codes all keep working, for apps
 * that can't do OAuth.
 */
declare(strict_types=1);

require_once __DIR__ . '/pads.php';
require_once __DIR__ . '/cimd.php';

const MB_OAUTH_PREFIX        = 'mp_o_';       // access tokens
const MB_OAUTH_REFRESH       = 'mp_r_';       // refresh tokens
const MB_OAUTH_CODE_SECONDS  = 300;
const MB_OAUTH_ACCESS_SECS   = 86400;
const MB_OAUTH_REFRESH_DAYS  = 90;
const MB_OAUTH_REGISTER_MAX  = 30;            // new client registrations per address per hour
const MB_OAUTH_SCOPE         = 'pads';

/** An OAuth error with the code the specs name for it (invalid_grant, invalid_client, …). */
class MbOAuthError extends RuntimeException
{
    public function __construct(public string $error, string $description, public int $status = 400)
    {
        parent::__construct($description);
    }
}

/** The issuer: this site's address with no trailing slash. */
function mb_oauth_issuer(): string
{
    return rtrim(mb_site_url(), '/');
}

/** The MCP endpoint these tokens are for. */
function mb_oauth_resource(): string
{
    return mb_site_url() . 'mcp/';
}

/**
 * A connector address that carries an invitation. The invitation link itself (join/<token>) is one: the single
 * link an owner shares is both the page a person opens and the address an agent's app connects to.
 * mcp/i/<token> is the same thing under the MCP folder.
 */
function mb_oauth_invite_resource(string $inviteToken, bool $asLink = true): string
{
    return mb_site_url() . ($asLink ? 'join/' : 'mcp/i/') . $inviteToken;
}

/** The invitation token inside a resource address (…/join/<token> or …/mcp/i/<token>), or null. */
function mb_oauth_invite_from_resource(string $resource): ?string
{
    return preg_match('~/(?:join|mcp/i)/([A-Za-z0-9]{16,64})/?$~', (string) parse_url($resource, PHP_URL_PATH), $m) ? $m[1] : null;
}

/** $inviteToken: when the app connected through an invitation address, that address is the resource. */
function mb_oauth_resource_metadata(?string $inviteToken = null, bool $asLink = true): array
{
    return [
        'resource'                 => $inviteToken !== null ? mb_oauth_invite_resource($inviteToken, $asLink) : mb_oauth_resource(),
        'authorization_servers'    => [mb_oauth_issuer()],
        'bearer_methods_supported' => ['header'],
        'scopes_supported'         => [MB_OAUTH_SCOPE],
        'resource_name'            => 'Mashpad',
        'resource_documentation'   => mb_site_url() . 'features/',
    ];
}

function mb_oauth_server_metadata(): array
{
    $site = mb_site_url();
    return [
        'issuer'                                         => mb_oauth_issuer(),
        'authorization_endpoint'                         => $site . 'oauth/authorize/',
        'token_endpoint'                                 => $site . 'oauth/token/',
        'registration_endpoint'                          => $site . 'oauth/register/',
        'client_id_metadata_document_supported'          => true,           // an app may use the https address of its metadata document as its client_id
        'response_types_supported'                       => ['code'],
        'response_modes_supported'                       => ['query'],
        'grant_types_supported'                          => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported'               => ['S256'],
        'token_endpoint_auth_methods_supported'          => ['none'],
        'scopes_supported'                               => [MB_OAUTH_SCOPE],
        'authorization_response_iss_parameter_supported' => true,
        'service_documentation'                          => $site . 'features/',
    ];
}

/** A redirect address an app may register: https anywhere, http only to the app's own machine, or an app's own scheme. */
function mb_oauth_redirect_ok(string $uri): bool
{
    if (strlen($uri) > 2000 || preg_match('/[\s<>"\\\\]/', $uri) || str_contains($uri, '#')) {
        return false;
    }
    $p = parse_url($uri);
    if (!$p || empty($p['scheme'])) {
        return false;
    }
    $scheme = strtolower($p['scheme']);
    if ($scheme === 'https') {
        return !empty($p['host']);
    }
    if ($scheme === 'http') {
        return in_array(strtolower((string) ($p['host'] ?? '')), ['localhost', '127.0.0.1', '[::1]'], true);
    }
    // A native app's private scheme (com.example.app:/callback). Never ones a browser would run or open.
    return (bool) preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) && !in_array($scheme, ['javascript', 'data', 'file', 'vbscript', 'blob', 'about'], true);
}

/** Dynamic client registration. Returns the registration response. */
function mb_oauth_register(PDO $pdo, array $in): array
{
    $ip = mb_client_ip();
    $n  = $pdo->prepare('SELECT COUNT(*) FROM oauth_clients WHERE created_ip = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR');
    $n->execute([$ip]);
    if ((int) $n->fetchColumn() >= MB_OAUTH_REGISTER_MAX) {
        throw new MbOAuthError('temporarily_unavailable', 'Too many registrations from this address. Try again in an hour.', 429);
    }
    $uris = $in['redirect_uris'] ?? null;
    if (!is_array($uris) || !$uris || count($uris) > 10) {
        throw new MbOAuthError('invalid_redirect_uri', 'redirect_uris must be a list of one to ten addresses.');
    }
    $clean = [];
    foreach ($uris as $u) {
        if (!is_string($u) || !mb_oauth_redirect_ok($u)) {
            throw new MbOAuthError('invalid_redirect_uri', 'Each redirect address must be https, or http on localhost, or an app\'s own scheme, with no fragment.');
        }
        $clean[] = $u;
    }
    $method = (string) ($in['token_endpoint_auth_method'] ?? 'none');
    if ($method !== 'none') {
        throw new MbOAuthError('invalid_client_metadata', 'Mashpad registers public clients only: token_endpoint_auth_method must be "none" (use PKCE).');
    }
    $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+|\s+/u', ' ', (string) ($in['client_name'] ?? '')));
    $name = $name === '' ? 'An MCP client' : (preg_match('/^.{1,120}/us', $name, $m) ? $m[0] : 'An MCP client');
    $id   = 'mpc_' . mb_random_token();
    $pdo->prepare('INSERT INTO oauth_clients (client_id, name, redirect_uris, created_ip) VALUES (?, ?, ?, ?)')
        ->execute([$id, $name, json_encode(array_values(array_unique($clean)), JSON_UNESCAPED_SLASHES), $ip]);
    return [
        'client_id'                  => $id,
        'client_id_issued_at'        => time(),
        'client_name'                => $name,
        'redirect_uris'              => array_values(array_unique($clean)),
        'grant_types'                => ['authorization_code', 'refresh_token'],
        'response_types'             => ['code'],
        'token_endpoint_auth_method' => 'none',
        'scope'                      => MB_OAUTH_SCOPE,
    ];
}

function mb_oauth_client(PDO $pdo, string $clientId): ?array
{
    if (mb_cimd_is_id($clientId)) {
        return mb_cimd_client($pdo, $clientId);        // an app identified by a metadata document: fetched and kept; throws MbCimdError if it can't be used
    }
    if ($clientId === '' || strlen($clientId) > 64) {
        return null;
    }
    $q = $pdo->prepare('SELECT * FROM oauth_clients WHERE client_id = ?');
    $q->execute([$clientId]);
    $c = $q->fetch();
    if (!$c) {
        return null;
    }
    $c['uris'] = json_decode((string) $c['redirect_uris'], true) ?: [];
    return $c;
}

/**
 * Check an authorization request before anything is shown or redirected.
 * Returns [client, redirect_uri]. Problems with the client or the redirect are
 * thrown as MbAccountError (shown on our page, never redirected: we can't trust
 * the address). Other problems are MbOAuthError, which the page sends back to
 * the app.
 */
function mb_oauth_check_request(PDO $pdo, array $q): array
{
    $client = mb_oauth_client($pdo, (string) ($q['client_id'] ?? ''));
    if (!$client) {
        throw new MbAccountError('This app isn\'t registered with Mashpad. Go back to it and try connecting again.');
    }
    $redirect = (string) ($q['redirect_uri'] ?? '');
    if ($redirect === '' && count($client['uris']) === 1) {
        $redirect = $client['uris'][0];
    }
    if (!in_array($redirect, $client['uris'], true)) {
        throw new MbAccountError('This app asked to send you back to an address it didn\'t register. Nothing was approved. Go back to the app and try again.');
    }
    if (($q['response_type'] ?? '') !== 'code') {
        throw new MbOAuthError('unsupported_response_type', 'Only response_type=code is supported.');
    }
    $challenge = (string) ($q['code_challenge'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $challenge) || (string) ($q['code_challenge_method'] ?? '') !== 'S256') {
        throw new MbOAuthError('invalid_request', 'PKCE is required: send code_challenge with code_challenge_method=S256.');
    }
    return [$client, $redirect];
}

/** The address to send the person back to, with the answer on it. */
function mb_oauth_redirect(string $redirectUri, array $params): string
{
    $params['iss'] = mb_oauth_issuer();
    return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query(array_filter($params, fn ($v) => $v !== null && $v !== ''));
}

/** Approval given: a one-time code the app exchanges for tokens. */
function mb_oauth_make_code(PDO $pdo, array $client, int $agentId, string $redirectUri, string $challenge): string
{
    $code = mb_random_token();
    $pdo->prepare('INSERT INTO oauth_codes (code_hash, client_id, principal_id, redirect_uri, code_challenge, expires_at) VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?))')
        ->execute([hash('sha256', $code, true), $client['id'], $agentId, $redirectUri, $challenge, time() + MB_OAUTH_CODE_SECONDS]);
    mb_audit($pdo, $agentId, 'oauth.approve', 'client:' . $client['client_id'], $client['name']);
    return $code;
}

function mb_oauth_issue(PDO $pdo, int $clientRow, int $agentId): array
{
    $access  = MB_OAUTH_PREFIX . mb_random_token();
    $refresh = MB_OAUTH_REFRESH . mb_random_token();
    $pdo->prepare('INSERT INTO oauth_tokens (client_id, principal_id, access_hash, refresh_hash, access_expires_at, refresh_expires_at)
                   VALUES (?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?))')
        ->execute([$clientRow, $agentId, hash('sha256', $access, true), hash('sha256', $refresh, true), time() + MB_OAUTH_ACCESS_SECS, time() + 86400 * MB_OAUTH_REFRESH_DAYS]);
    return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => MB_OAUTH_ACCESS_SECS, 'refresh_token' => $refresh, 'scope' => MB_OAUTH_SCOPE];
}

/** The token endpoint. $in is the form body. Returns the token response; throws MbOAuthError. */
function mb_oauth_token(PDO $pdo, array $in): array
{
    $client = mb_oauth_client($pdo, (string) ($in['client_id'] ?? ''));
    if (!$client) {
        throw new MbOAuthError('invalid_client', 'Unknown client_id.', 401);
    }
    $grant = (string) ($in['grant_type'] ?? '');
    if ($grant === 'authorization_code') {
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT * FROM oauth_codes WHERE code_hash = ? FOR UPDATE');
            $q->execute([hash('sha256', (string) ($in['code'] ?? ''), true)]);
            $c = $q->fetch();
            if ($c && $c['used_at'] !== null) {
                // A code presented twice means it leaked: everything it produced is withdrawn.
                $pdo->prepare('UPDATE oauth_tokens SET revoked_at = UTC_TIMESTAMP() WHERE client_id = ? AND principal_id = ? AND revoked_at IS NULL')->execute([$c['client_id'], $c['principal_id']]);
                $pdo->commit();
                throw new MbOAuthError('invalid_grant', 'That authorization code was already used.');
            }
            $verifier = (string) ($in['code_verifier'] ?? '');
            $ok = $c && (int) $c['client_id'] === (int) $client['id'] && strtotime($c['expires_at'] . ' UTC') > time()
                && hash_equals((string) $c['redirect_uri'], (string) ($in['redirect_uri'] ?? $c['redirect_uri']))
                && preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier)
                && hash_equals((string) $c['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
            if (!$ok) {
                $pdo->commit();
                throw new MbOAuthError('invalid_grant', 'That authorization code isn\'t valid: it may have expired (they last five minutes), or the PKCE verifier or redirect address doesn\'t match.');
            }
            $pdo->prepare('UPDATE oauth_codes SET used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$c['id']]);
            $agent = mb_principal($pdo, (int) $c['principal_id']);
            if (!$agent || $agent['kind'] !== 'agent' || $agent['disabled_at'] !== null) {
                $pdo->commit();
                throw new MbOAuthError('invalid_grant', 'The agent this approval was for no longer exists.');
            }
            $out = mb_oauth_issue($pdo, (int) $client['id'], (int) $agent['id']);
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
    if ($grant === 'refresh_token') {
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT t.*, p.kind, p.disabled_at FROM oauth_tokens t JOIN principals p ON p.id = t.principal_id WHERE t.refresh_hash = ? FOR UPDATE');
            $q->execute([hash('sha256', (string) ($in['refresh_token'] ?? ''), true)]);
            $t = $q->fetch();
            $ok = $t && (int) $t['client_id'] === (int) $client['id'] && $t['revoked_at'] === null && $t['disabled_at'] === null
                && $t['refresh_expires_at'] !== null && strtotime($t['refresh_expires_at'] . ' UTC') > time();
            if (!$ok) {
                $pdo->commit();
                throw new MbOAuthError('invalid_grant', 'That refresh token isn\'t valid any more. Connect to Mashpad again.');
            }
            $pdo->prepare('UPDATE oauth_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$t['id']]);     // rotation: each refresh token works once
            $out = mb_oauth_issue($pdo, (int) $client['id'], (int) $t['principal_id']);
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
    throw new MbOAuthError('unsupported_grant_type', 'Use grant_type=authorization_code or refresh_token.');
}

/** The agent an access token stands for, or null. Marks it used (at most every five minutes). */
function mb_oauth_agent(PDO $pdo, string $token): ?array
{
    if (!str_starts_with($token, MB_OAUTH_PREFIX) || strlen($token) > 100) {
        return null;
    }
    $q = $pdo->prepare('SELECT t.id, t.principal_id, t.last_used_at FROM oauth_tokens t JOIN principals p ON p.id = t.principal_id
                         WHERE t.access_hash = ? AND t.revoked_at IS NULL AND t.access_expires_at > UTC_TIMESTAMP() AND p.kind = \'agent\' AND p.disabled_at IS NULL');
    $q->execute([hash('sha256', $token, true)]);
    $t = $q->fetch();
    if (!$t) {
        return null;
    }
    if ($t['last_used_at'] === null || strtotime($t['last_used_at'] . ' UTC') < time() - 300) {
        $pdo->prepare('UPDATE oauth_tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$t['id']]);
        $pdo->prepare('UPDATE agent_profiles SET last_active_at = UTC_TIMESTAMP() WHERE principal_id = ?')->execute([$t['principal_id']]);
    }
    return mb_principal($pdo, (int) $t['principal_id']);
}

/** Apps an agent is connected through: [['name', 'since' => unix, 'used' => unix|null], …], newest first. */
function mb_oauth_connections(PDO $pdo, int $agentId): array
{
    $q = $pdo->prepare('SELECT c.name, UNIX_TIMESTAMP(MIN(t.created_at)) AS since, UNIX_TIMESTAMP(MAX(t.last_used_at)) AS used
                          FROM oauth_tokens t JOIN oauth_clients c ON c.id = t.client_id
                         WHERE t.principal_id = ? AND t.revoked_at IS NULL AND (t.refresh_expires_at IS NULL OR t.refresh_expires_at > UTC_TIMESTAMP())
                         GROUP BY c.id, c.name ORDER BY since DESC');
    $q->execute([$agentId]);
    return $q->fetchAll();
}

/** Disconnect every app from an agent. Returns how many connections were live. */
function mb_oauth_revoke_for(PDO $pdo, int $agentId): int
{
    $q = $pdo->prepare('UPDATE oauth_tokens SET revoked_at = UTC_TIMESTAMP() WHERE principal_id = ? AND revoked_at IS NULL');
    $q->execute([$agentId]);
    return $q->rowCount();
}
