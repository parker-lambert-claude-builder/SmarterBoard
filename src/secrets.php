<?php
/**
 * Mashpad — keeping secrets the site must be able to use again (a token it sends, a secret it signs with).
 *
 * They are sealed with libsodium's secretbox under a key that lives in data/config.php, not in the database, so a copy
 * of the database alone doesn't give them up. The key is made the first time one is needed. A deployment may set it in
 * the MASHPAD_SECRET_KEY environment variable instead (32 bytes, base64).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Whether this server has sodium. ($GLOBALS['mb_no_sodium'] lets a test behave as a server that doesn't.) */
function mb_sodium_ready(): bool
{
    return empty($GLOBALS['mb_no_sodium']) && function_exists('sodium_crypto_secretbox') && defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES');
}

/** Say so, in a RuntimeException, before any sodium function or constant is used: a missing constant is a fatal Error. */
function mb_require_sodium(): void
{
    if (!mb_sodium_ready()) {
        throw new RuntimeException('This server has no sodium extension, which Mashpad needs to keep tokens safely.');
    }
}

/** The 32-byte key. Throws if the server can't keep one. */
function mb_secret_key(): string
{
    static $key = null;
    mb_require_sodium();
    if ($key !== null) {
        return $key;
    }
    $env = getenv('MASHPAD_SECRET_KEY');
    if ($env !== false && $env !== '') {
        $raw = base64_decode($env, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('MASHPAD_SECRET_KEY must be 32 bytes, base64 encoded.');
        }
        return $key = $raw;
    }
    $read = function (): ?array {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(MB_CONFIG_FILE, true);
        }
        $c = is_file(MB_CONFIG_FILE) ? (include MB_CONFIG_FILE) : null;
        return is_array($c) && !empty($c['db']) ? $c : null;
    };
    $cfg = $read();
    if ($cfg === null) {
        throw new RuntimeException('Mashpad isn\'t set up on this server yet.');
    }
    $valid = fn (array $c) => !empty($c['secret_key']) && strlen((string) base64_decode((string) $c['secret_key'], true)) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
    if ($valid($cfg)) {
        return $key = (string) base64_decode($cfg['secret_key'], true);
    }
    $lock = @fopen(MB_CONFIG_FILE . '.lock', 'c');                    // two requests must not each make a key
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not make a key for keeping tokens. Check that data/ is writable by PHP.');
    }
    try {
        $cfg = $read() ?? $cfg;
        if (!$valid($cfg)) {
            $cfg['secret_key'] = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            $tmp = MB_CONFIG_FILE . '.tmp';
            $body = "<?php\n// Written by setup/. Database credentials and the key for stored tokens: never commit this file.\nreturn " . var_export($cfg, true) . ";\n";
            if (@file_put_contents($tmp, $body, LOCK_EX) === false || !@chmod($tmp, 0600) || !@rename($tmp, MB_CONFIG_FILE)) {
                @unlink($tmp);
                throw new RuntimeException('Could not save the key for keeping tokens. Check that data/ is writable by PHP.');
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $key = (string) base64_decode($cfg['secret_key'], true);
}

/** Seal a secret for storage. */
function mb_seal(string $plain): string
{
    mb_require_sodium();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'v1.' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, mb_secret_key()));
}

/** The secret back, or null if it isn't one of ours or the key has changed. */
function mb_unseal(?string $sealed): ?string
{
    if ($sealed === null || !str_starts_with($sealed, 'v1.')) {
        return null;
    }
    mb_require_sodium();
    $raw = base64_decode(substr($sealed, 3), true);
    if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        return null;
    }
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), mb_secret_key());
    return $plain === false ? null : $plain;
}
