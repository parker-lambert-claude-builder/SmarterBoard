<?php
/**
 * Mashpad — human sign-in, registration and sessions.
 *
 * A session is a random token in an HttpOnly cookie; only its SHA-256 hash is
 * stored (tokens table, kind 'session'). Sign-out revokes it on the server.
 * Forms are protected by a double-submit CSRF token plus a same-origin check.
 */
declare(strict_types=1);

require_once __DIR__ . '/accounts.php';

const MB_SESSION_COOKIE   = 'mashpad_session';
const MB_CSRF_COOKIE      = 'mashpad_csrf';
const MB_SESSION_DAYS     = 30;
const MB_APP_ROOT         = __DIR__ . '/..';

// Rate limits (failures inside the window).
const MB_SIGNIN_WINDOW    = 900;   // 15 minutes
const MB_SIGNIN_PER_EMAIL = 5;
const MB_SIGNIN_PER_IP    = 20;
const MB_REGISTER_WINDOW  = 3600;
const MB_REGISTER_PER_IP  = 5;

/** URL path of the Mashpad folder, e.g. "/message-board/", from the running script. */
function mb_base_path(): string
{
    static $base = null;
    if ($base === null) {
        $base = '/';
        $file = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $root = realpath(MB_APP_ROOT);
        $name = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if ($file && $root && str_starts_with($file, $root)) {
            $rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root)));
            if ($rel !== '' && str_ends_with($name, $rel)) {
                $base = rtrim(substr($name, 0, -strlen($rel)), '/') . '/';
            }
        }
    }
    return $base;
}

function mb_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** Absolute address of this installation's root, with a trailing slash. */
function mb_site_url(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    return (mb_is_https() ? 'https://' : 'http://') . $host . mb_base_path();
}

function mb_set_cookie(string $name, string $value, int $expires): void
{
    setcookie($name, $value, [
        'expires'  => $expires,
        'path'     => mb_base_path(),
        'secure'   => mb_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if ($expires > 0 && $expires < time()) {
        unset($_COOKIE[$name]);
    } else {
        $_COOKIE[$name] = $value;
    }
}

function mb_client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function mb_random_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

// ---------------------------------------------------------------- CSRF

function mb_csrf_token(): string
{
    $t = (string) ($_COOKIE[MB_CSRF_COOKIE] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $t)) {
        $t = mb_random_token();
        mb_set_cookie(MB_CSRF_COOKIE, $t, 0);
    }
    return $t;
}

/** False if the request plainly came from another site. */
function mb_same_origin(): bool
{
    $site = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
        return false;
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && $origin !== 'null') {
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        $here = (string) ($_SERVER['HTTP_HOST'] ?? '');
        return $here !== '' && strcasecmp($host . ($port ? ':' . $port : ''), $here) === 0;
    }
    return true;
}

function mb_csrf_ok(string $given): bool
{
    $cookie = (string) ($_COOKIE[MB_CSRF_COOKIE] ?? '');
    return mb_same_origin() && $cookie !== '' && $given !== '' && hash_equals($cookie, $given);
}

// ---------------------------------------------------------------- sessions

function mb_create_session(PDO $pdo, int $principalId): void
{
    $token = mb_random_token();
    $pdo->prepare("INSERT INTO tokens (principal_id, kind, token_hash, label, expires_at)
                   VALUES (?, 'session', ?, ?, UTC_TIMESTAMP() + INTERVAL " . MB_SESSION_DAYS . " DAY)")
        ->execute([$principalId, hash('sha256', $token, true), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 80)]);
    mb_set_cookie(MB_SESSION_COOKIE, $token, time() + MB_SESSION_DAYS * 86400);
}

/** The signed-in human, or null. Never throws: a broken database means "signed out". */
function mb_current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    $token = (string) ($_COOKIE[MB_SESSION_COOKIE] ?? '');
    if ($token === '' || strlen($token) > 100) {
        return null;
    }
    try {
        $pdo = mb_db();
        if ($pdo === null) {
            return null;
        }
        $q = $pdo->prepare("SELECT t.id AS token_id, t.last_used_at, p.id, p.handle, p.display_name, p.email, p.plan_code, p.is_admin
                              FROM tokens t JOIN principals p ON p.id = t.principal_id
                             WHERE t.token_hash = ? AND t.kind = 'session' AND t.revoked_at IS NULL
                               AND (t.expires_at IS NULL OR t.expires_at > UTC_TIMESTAMP())
                               AND p.kind = 'human' AND p.disabled_at IS NULL");
        $q->execute([hash('sha256', $token, true)]);
        $row = $q->fetch();
        if ($row) {
            if ($row['last_used_at'] === null || strtotime($row['last_used_at'] . ' UTC') < time() - 300) {
                $pdo->prepare('UPDATE tokens SET last_used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$row['token_id']]);
            }
            $user = $row;
        }
    } catch (Throwable $e) {
        $user = null;
    }
    return $user;
}

function mb_sign_out(): void
{
    $token = (string) ($_COOKIE[MB_SESSION_COOKIE] ?? '');
    if ($token !== '') {
        try {
            $pdo = mb_db();
            if ($pdo) {
                $user = mb_current_user();
                $pdo->prepare("UPDATE tokens SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = ? AND kind = 'session' AND revoked_at IS NULL")
                    ->execute([hash('sha256', $token, true)]);
                if ($user) {
                    mb_audit($pdo, (int) $user['id'], 'session.sign_out', 'principal:' . $user['id']);
                }
            }
        } catch (Throwable $e) {
            // the cookie is cleared regardless
        }
    }
    mb_set_cookie(MB_SESSION_COOKIE, '', time() - 3600);
}

// ---------------------------------------------------------------- rate limits

function mb_attempt(PDO $pdo, string $kind, ?string $email, bool $success): void
{
    $pdo->prepare('INSERT INTO auth_attempts (kind, ip, email_normalized, success) VALUES (?, ?, ?, ?)')
        ->execute([$kind, mb_client_ip(), $email, $success ? 1 : 0]);
}

/** Seconds until another attempt is allowed, or 0. */
function mb_wait_seconds(PDO $pdo, string $kind, string $column, string $value, int $window, int $limit, bool $failuresOnly): int
{
    $q = $pdo->prepare("SELECT COUNT(*) AS n, MIN(at) AS first FROM auth_attempts
                         WHERE kind = ? AND $column = ? AND at > UTC_TIMESTAMP() - INTERVAL $window SECOND"
                         . ($failuresOnly ? ' AND success = 0' : ''));
    $q->execute([$kind, $value]);
    $row = $q->fetch();
    if ((int) $row['n'] < $limit) {
        return 0;
    }
    return max(1, strtotime($row['first'] . ' UTC') + $window - time());
}

function mb_too_many(int $seconds, string $what): MbAccountError
{
    $minutes = (int) ceil($seconds / 60);
    return new MbAccountError("Too many $what attempts. Please wait $minutes minute" . ($minutes === 1 ? '' : 's') . ' and try again.');
}

// ---------------------------------------------------------------- sign-in and registration

/** Returns the principal id on success. Messages never reveal whether an email is registered. */
function mb_sign_in(PDO $pdo, string $email, string $password): int
{
    $normal = mb_normalize_email($email);
    if ($normal === '' || $password === '') {
        throw new MbAccountError('Enter your email address and password.');
    }
    $wait = max(
        mb_wait_seconds($pdo, 'signin', 'email_normalized', $normal, MB_SIGNIN_WINDOW, MB_SIGNIN_PER_EMAIL, true),
        mb_wait_seconds($pdo, 'signin', 'ip', mb_client_ip(), MB_SIGNIN_WINDOW, MB_SIGNIN_PER_IP, true)
    );
    if ($wait) {
        throw mb_too_many($wait, 'sign-in');
    }

    $q = $pdo->prepare("SELECT id, password_hash FROM principals WHERE kind = 'human' AND email_normalized = ? AND disabled_at IS NULL");
    $q->execute([$normal]);
    $row  = $q->fetch();
    // Verify against a dummy hash when the account doesn't exist, so timing doesn't reveal it.
    $hash = $row['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$VS5GQ1I2RW9Mbi5Db1dvLg$CEtgHR7m9BFN5NjE+tDx7CDPlE9LaPIptv02CG5+3fI';
    $ok   = strlen($password) <= MB_PASSWORD_MAX && password_verify($password, $hash) && $row;

    mb_attempt($pdo, 'signin', $normal, (bool) $ok);
    if (!$ok) {
        throw new MbAccountError('That email and password don\'t match an account.');
    }
    $id = (int) $row['id'];
    if (password_needs_rehash($row['password_hash'], mb_password_algo())) {
        $pdo->prepare('UPDATE principals SET password_hash = ? WHERE id = ?')->execute([password_hash($password, mb_password_algo()), $id]);
    }
    mb_create_session($pdo, $id);
    mb_audit($pdo, $id, 'session.sign_in', 'principal:' . $id);
    return $id;
}

function mb_registration_open(PDO $pdo): bool
{
    return (string) $pdo->query("SELECT value FROM settings WHERE name = 'registration_open'")->fetchColumn() === '1';
}

/** Create an account and sign it in. Returns the principal id. */
function mb_register(PDO $pdo, array $in): int
{
    if (!mb_registration_open($pdo)) {
        throw new MbAccountError('New accounts aren\'t being accepted right now.');
    }
    $wait = mb_wait_seconds($pdo, 'register', 'ip', mb_client_ip(), MB_REGISTER_WINDOW, MB_REGISTER_PER_IP, false);
    if ($wait) {
        throw mb_too_many($wait, 'registration');
    }
    if ((string) ($in['password'] ?? '') !== (string) ($in['password2'] ?? '')) {
        throw new MbAccountError('The two passwords don\'t match.');
    }
    if (in_array(strtolower(trim((string) ($in['handle'] ?? ''))), mb_legacy_authors(), true)) {
        throw new MbAccountError('That handle is already used by someone posting on existing pads. Please pick another.');
    }
    try {
        $id = mb_create_human($pdo, (string) ($in['display_name'] ?? ''), (string) ($in['handle'] ?? ''),
            (string) ($in['email'] ?? ''), (string) ($in['password'] ?? ''));
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {     // a concurrent registration won the race
            throw new MbAccountError('That email address or handle is already in use.');
        }
        throw $e;
    }
    mb_attempt($pdo, 'register', mb_normalize_email((string) ($in['email'] ?? '')), true);
    mb_create_session($pdo, $id);
    return $id;
}

/** Names already signing posts on the file-based pads, lowercased, without @. */
function mb_legacy_authors(): array
{
    require_once __DIR__ . '/../lib.php';
    $names = [];
    try {
        foreach (mb_list() as $pad) {
            foreach ($pad['authors'] as $a) {
                $names[strtolower(ltrim((string) $a, '@'))] = true;
            }
        }
    } catch (Throwable $e) {
        // storage unreadable: no reservations
    }
    return array_keys($names);
}

/** Is $name (with or without @) a registered human handle? Fails open if the database is unavailable. */
function mb_handle_is_registered(string $name): bool
{
    $h = strtolower(ltrim(trim($name), '@'));
    if ($h === '') {
        return false;
    }
    try {
        $pdo = mb_db();
        if ($pdo === null) {
            return false;
        }
        $q = $pdo->prepare("SELECT 1 FROM principals WHERE kind = 'human' AND handle = ?");
        $q->execute([$h]);
        return (bool) $q->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** Only allow redirects back into Mashpad. */
function mb_safe_next(string $next): string
{
    $base = mb_base_path();
    return (str_starts_with($next, $base) && !str_starts_with($next, '//') && !preg_match('/[\r\n\\\\]/', $next))
        ? $next : $base . 'pads/';
}
