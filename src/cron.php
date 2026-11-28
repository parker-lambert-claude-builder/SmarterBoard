<?php
/**
 * Mashpad — the scheduled job, run from cron on the server or from a cron service over the web.
 *
 * One job does everything that has to happen on a schedule: send the wake-ups that are due (retries, and anything queued when the
 * server was busy), tidy the wake-up log, and delete private messages older than their plan keeps them. It is safe to run as often
 * as every minute and safe to overlap.
 *
 * Two ways in, both calling mb_cron_run():
 * - bin/wake-cron.php, from the host's own cron (command line only).
 * - cron/index.php, a web address for a third-party cron service, protected by a key. The key is made on Admin > Cron and shown
 *   once; only its SHA-256 is kept. It is sent as "Authorization: Bearer <key>" (best), or as ?key=<key> for services that can only
 *   call an address. Anything else, and a wrong key, gets the same 404 as a page that doesn't exist.
 */
declare(strict_types=1);

require_once __DIR__ . '/pads.php';
require_once __DIR__ . '/mail.php';

const MB_CRON_KEY_SETTING = 'cron_key_hash';
const MB_CRON_LAST_SETTING = 'cron_last_run';

/** A new key (shown once), replacing any earlier one. Administrators only. */
function mb_cron_key_create(PDO $pdo, int $adminId): string
{
    $key = 'mpc_' . bin2hex(random_bytes(24));
    mb_set_setting($pdo, MB_CRON_KEY_SETTING, hash('sha256', $key));
    mb_audit($pdo, $adminId, 'cron.key', 'settings', 'created');
    return $key;
}

function mb_cron_key_exists(PDO $pdo): bool
{
    return mb_setting($pdo, MB_CRON_KEY_SETTING) !== '';
}

/** Whether $presented is the current key. Constant time; false if there is no key yet. */
function mb_cron_key_ok(PDO $pdo, string $presented): bool
{
    $hash = mb_setting($pdo, MB_CRON_KEY_SETTING);
    return $hash !== '' && $presented !== '' && strlen($presented) < 200 && hash_equals($hash, hash('sha256', $presented));
}

/** Whether the request came over https, or from this machine (where there is no one to overhear a key). */
function mb_cron_transport_ok(array $server): bool
{
    $https = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') || strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    return $https || in_array($server['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/** The key as sent with a request: a Bearer header, an X-Cron-Key header, or ?key=. */
function mb_cron_key_from_request(array $server, array $query): string
{
    $auth = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
        return $m[1];
    }
    if (!empty($server['HTTP_X_CRON_KEY'])) {
        return trim((string) $server['HTTP_X_CRON_KEY']);
    }
    return is_string($query['key'] ?? null) ? trim($query['key']) : '';
}

/**
 * Run the job. Returns what it did, and records when and how it went for Admin > Cron. A second run that starts while one is going
 * returns ['skipped' => 'already running'] rather than doing the work twice.
 */
function mb_cron_run(PDO $pdo, string $via = 'cron'): array
{
    $lockFile = dirname(__DIR__) . '/data/cron.lock';
    $lock     = @fopen($lockFile, 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return ['ok' => true, 'skipped' => 'already running', 'ran_at' => time()];
    }
    $started = microtime(true);
    try {
        $wake     = mb_wake_flush($pdo, 200, 25);
        $pruned   = mb_wake_prune($pdo);
        $messages = mb_messages_prune($pdo);
        $result   = ['ok' => true, 'sent' => (int) ($wake['sent'] ?? 0), 'retry' => (int) ($wake['retry'] ?? 0), 'failed' => (int) ($wake['failed'] ?? 0),
                     'pruned' => (int) $pruned, 'messages_pruned' => (int) $messages, 'ran_at' => time()];
    } catch (Throwable $e) {
        $result = ['ok' => false, 'error' => 'The job stopped with an error. Check the server log.', 'ran_at' => time()];
        error_log('Mashpad cron: ' . $e->getMessage());
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    $result['ms'] = (int) round((microtime(true) - $started) * 1000);
    mb_set_setting($pdo, MB_CRON_LAST_SETTING, json_encode($result + ['via' => $via]));
    return $result;
}

/** When the job last ran, how, and what it did, or null if it never has. */
function mb_cron_status(PDO $pdo): ?array
{
    $j = json_decode(mb_setting($pdo, MB_CRON_LAST_SETTING), true);
    return is_array($j) && isset($j['ran_at']) ? $j : null;
}
