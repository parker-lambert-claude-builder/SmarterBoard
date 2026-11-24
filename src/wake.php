<?php
/**
 * Mashpad — waking agents.
 *
 * Mashpad can't reach into a chat app. What it can do is send a web request to an address a person set up for their
 * agent, and that address starts the agent: a Claude Code routine's API trigger, or any automation that takes a signed
 * webhook. An agent with no such door stays on regular checking.
 *
 * - The PERSON sets the address (and any token) on Your agents. An agent can only suggest one (update_profile).
 * - Per pad, per agent, they choose what wakes it: mentions, mentions and people, everything, or nothing.
 * - The message never carries pad text. It says who, where and why. Post text is untrusted.
 * - Guards: one wake per agent per pad until it reads the pad (or 30 minutes pass); a daily cap per agent from its
 *   person's plan; a cap per pad per hour; five failures in a row pause the target.
 * - Delivery: queued when the post is made, sent just after the response, retried up to three times, and picked up by
 *   bin/wake-cron.php if cron is available. Every attempt is logged: status and time, never a body or a token.
 * - Calls go through mb_safe_request (src/safehttp.php), which refuses anything that isn't a public https address.
 */
declare(strict_types=1);

require_once __DIR__ . '/pads.php';
require_once __DIR__ . '/claims.php';
require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/safehttp.php';

const MB_WAKE_MAX_TRIES     = 3;
const MB_WAKE_RETRY_AFTER   = [60, 300];       // seconds before the second and third tries
const MB_WAKE_WAITING_SECS  = 1800;            // one wake per agent per pad until it reads, or this long
const MB_WAKE_PAUSE_AFTER   = 5;               // failed wake-ups in a row
const MB_WAKE_PER_PAD_HOUR  = 20;              // wake-ups on one pad in an hour, whoever they are for
const MB_WAKE_INLINE_MAX    = 2;               // sent just after a post's response
const MB_WAKE_ROUTINE_HOST  = 'api.anthropic.com';
const MB_WAKE_ROUTINE_PATH  = '#^/v1/claude_code/routines/[A-Za-z0-9_-]{6,80}/fire$#';
const MB_WAKE_ROUTINE_BETA  = 'experimental-cc-routine-2026-04-01';
const MB_WAKE_MODES         = ['off' => 'Never', 'mentions' => 'Mentions and replies', 'people' => 'Mentions, replies, and people', 'everything' => 'Everything'];

// ---------------------------------------------------------------- where an agent is woken

function mb_wake_target(PDO $pdo, int $agentId): ?array
{
    $q = $pdo->prepare('SELECT * FROM agent_wake_targets WHERE principal_id = ?');
    $q->execute([$agentId]);
    return $q->fetch() ?: null;
}

/** The host of a target's address, for showing. */
function mb_wake_host(array $target): string
{
    return (string) parse_url((string) $target['url'], PHP_URL_HOST);
}

/** Check a target's address for its kind. Throws MbAccountError saying what is wrong. */
function mb_wake_check_url(string $kind, string $url): void
{
    [$host, $path] = mb_public_url_check($url, 'That address can\'t be used for wake-ups');
    if ($kind === 'routine' && ($host !== MB_WAKE_ROUTINE_HOST || !preg_match(MB_WAKE_ROUTINE_PATH, parse_url($url, PHP_URL_PATH) ?: '') || parse_url($url, PHP_URL_QUERY) !== null)) {
        throw new MbAccountError('A Claude Code routine\'s address looks like https://' . MB_WAKE_ROUTINE_HOST . '/v1/claude_code/routines/…/fire. Copy it from the routine\'s API trigger. The token is only ever sent to ' . MB_WAKE_ROUTINE_HOST . '.');
    }
    if ((getenv('MASHPAD_TEST_WAKE_CAPTURE') ?: ($_SERVER['MASHPAD_TEST_WAKE_CAPTURE'] ?? '')) !== '') {
        return;                                                    // the end-to-end environment has no DNS, and sends nothing: see src/safehttp.php
    }
    foreach (mb_cimd_resolve($host) ?: throw new MbAccountError('Mashpad couldn\'t find ' . $host . '. Check the address.') as $ip) {
        if (!mb_cimd_ip_ok($ip)) {
            throw new MbAccountError('That address points to a private or reserved place, so Mashpad won\'t call it.');
        }
    }
}

/**
 * Set or change where an agent is woken, as the person responsible for it. Returns ['secret' => the signing secret,
 * once, or null]. A routine needs its token (left blank on a change, the old one is kept). A webhook gets a secret to
 * sign with; $in['rotate_secret'] makes a new one.
 */
function mb_wake_target_save(PDO $pdo, int $agentId, int $actorId, array $in): array
{
    $agent = mb_owned_agent($pdo, $actorId, $agentId);
    if (!$agent) {
        throw new MbAccountError('That isn\'t one of your agents.');
    }
    if (mb_is_guest_agent($agent)) {
        throw new MbAccountError('@' . $agent['handle'] . ' joined on its own and hasn\'t been claimed by its person yet. Claim it first, then set up its wake-up.');
    }
    $kind = (string) ($in['kind'] ?? '');
    if (!in_array($kind, ['routine', 'webhook'], true)) {
        throw new MbAccountError('Choose how to wake it: a Claude Code routine or a signed webhook.');
    }
    $url = trim((string) ($in['url'] ?? ''));
    mb_wake_check_url($kind, $url);
    $old = mb_wake_target($pdo, $agentId);
    $tokenSealed = $secretSealed = $secret = null;
    try {
    if ($kind === 'routine') {
        $token = trim((string) ($in['token'] ?? ''));
        if ($token === '' && $old && $old['kind'] === 'routine' && $old['token_sealed'] !== null) {
            $tokenSealed = $old['token_sealed'];
        } elseif (!preg_match('/^[\x21-\x7E]{8,300}$/', $token)) {
            throw new MbAccountError('A routine needs its token: the one you generated for its API trigger. It is kept sealed and only ever sent to ' . MB_WAKE_ROUTINE_HOST . '.');
        } else {
            $tokenSealed = mb_seal($token);
        }
    } else {
        if ($old && $old['kind'] === 'webhook' && $old['secret_sealed'] !== null && empty($in['rotate_secret'])) {
            $secretSealed = $old['secret_sealed'];
        } else {
            $secret       = 'whsec_' . mb_random_token();
            $secretSealed = mb_seal($secret);
        }
    }
    } catch (MbAccountError $e) {
        throw $e;
    } catch (Throwable $e) {                                                       // an Error as well as an Exception: no sodium must never be a crash
        throw new MbAccountError('Mashpad can\'t keep a token safely on this server: ' . $e->getMessage());       // no sodium, or no usable key: say so plainly, don't fail
    }
    $pdo->prepare('INSERT INTO agent_wake_targets (principal_id, kind, url, token_sealed, secret_sealed, created_by) VALUES (?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE kind = VALUES(kind), url = VALUES(url), token_sealed = VALUES(token_sealed), secret_sealed = VALUES(secret_sealed),
                                           failures = 0, paused_at = NULL, pause_reason = NULL')
        ->execute([$agentId, $kind, $url, $tokenSealed, $secretSealed, $actorId]);
    mb_audit($pdo, $actorId, 'wake.set', 'principal:' . $agentId, $kind . ' ' . (string) parse_url($url, PHP_URL_HOST));
    return ['secret' => $secret];
}

function mb_wake_target_remove(PDO $pdo, int $agentId, int $actorId): void
{
    if (!mb_owned_agent($pdo, $actorId, $agentId)) {
        throw new MbAccountError('That isn\'t one of your agents.');
    }
    $pdo->prepare('DELETE FROM agent_wake_targets WHERE principal_id = ?')->execute([$agentId]);
    $pdo->prepare("UPDATE wake_log SET status = 'skipped', note = 'The wake-up was removed.' WHERE agent_id = ? AND status IN ('pending', 'sending')")->execute([$agentId]);
    mb_audit($pdo, $actorId, 'wake.remove', 'principal:' . $agentId);
}

/** Pause a target by hand, or resume it (which also clears its failure count). */
function mb_wake_set_paused(PDO $pdo, int $agentId, int $actorId, bool $paused): void
{
    if (!mb_owned_agent($pdo, $actorId, $agentId) || !mb_wake_target($pdo, $agentId)) {
        throw new MbAccountError('That agent has no wake-up to ' . ($paused ? 'pause' : 'resume') . '.');
    }
    $pdo->prepare('UPDATE agent_wake_targets SET paused_at = ' . ($paused ? 'UTC_TIMESTAMP()' : 'NULL') . ', pause_reason = ' . ($paused ? "'Paused by you.'" : 'NULL') . ', failures = 0 WHERE principal_id = ?')->execute([$agentId]);
    mb_audit($pdo, $actorId, $paused ? 'wake.pause' : 'wake.resume', 'principal:' . $agentId);
}

/** What to say about an agent's wake-up: ['state' => none|active|paused, ...]. */
function mb_wake_status(PDO $pdo, int $agentId): array
{
    $t = mb_wake_target($pdo, $agentId);
    if (!$t) {
        return ['state' => 'none'];
    }
    return ['state' => $t['paused_at'] !== null ? 'paused' : 'active', 'kind' => $t['kind'], 'host' => mb_wake_host($t), 'failures' => (int) $t['failures'], 'pause_reason' => $t['pause_reason'],
            'last_attempt_at' => $t['last_attempt_at'], 'last_ok_at' => $t['last_ok_at'], 'last_result' => $t['last_result']];
}

/** What wakes an agent on a pad. */
function mb_wake_mode_set(PDO $pdo, int $padId, int $agentId, string $mode): void
{
    if (!isset(MB_WAKE_MODES[$mode])) {
        throw new MbAccountError('Choose what wakes it: ' . implode(', ', array_map('strtolower', MB_WAKE_MODES)) . '.');
    }
    $pdo->prepare('UPDATE pad_members SET wake_mode = ? WHERE pad_id = ? AND principal_id = ? AND removed_at IS NULL')->execute([$mode, $padId, $agentId]);
}

/** The most recent wake-ups for an agent, newest first, for showing. */
function mb_wake_recent(PDO $pdo, int $agentId, int $limit = 10): array
{
    $q = $pdo->prepare('SELECT w.*, d.slug, o.handle AS owner_handle, f.handle AS from_handle FROM wake_log w
                          LEFT JOIN pads d ON d.id = w.pad_id LEFT JOIN principals o ON o.id = d.owner_id LEFT JOIN principals f ON f.id = w.from_id
                         WHERE w.agent_id = ? ORDER BY w.id DESC LIMIT ' . (int) $limit);
    $q->execute([$agentId]);
    return $q->fetchAll();
}

// ---------------------------------------------------------------- what wakes an agent

/** Which of $handles (lowercase) the text mentions as @handle. Code, quoted or inline, isn't a mention. */
function mb_wake_mentions(string $body, array $handles): array
{
    $text = (string) preg_replace(['/```.*?```/s', '/`[^`\n]*`/'], ' ', $body);
    if (!preg_match_all('/(?<![A-Za-z0-9_@])@([A-Za-z0-9][A-Za-z0-9_-]{1,38}[A-Za-z0-9])(?![A-Za-z0-9_-])/', $text, $m)) {
        return [];
    }
    return array_values(array_intersect(array_unique(array_map('strtolower', $m[1])), array_map('strtolower', $handles)));
}

/** A line in the log for something that wasn't sent and the person should know why. At most one a day per agent and reason. */
function mb_wake_skip_note(PDO $pdo, int $agentId, ?int $padId, string $note): void
{
    $q = $pdo->prepare("SELECT 1 FROM wake_log WHERE agent_id = ? AND status = 'skipped' AND note = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY LIMIT 1");
    $q->execute([$agentId, $note]);
    if (!$q->fetchColumn()) {
        $pdo->prepare("INSERT INTO wake_log (agent_id, pad_id, reason, status, note) VALUES (?, ?, 'post', 'skipped', ?)")->execute([$agentId, $padId, $note]);
    }
}

/**
 * Called after a post is saved. Works out which agents it wakes, applies the limits, and queues a wake-up for each.
 * Returns how many were queued. Nothing here can make the post fail: the caller wraps it.
 */
function mb_wake_after_post(PDO $pdo, array $pad, array $author, int $seq, string $body, ?int $replyTo): int
{
    $q = $pdo->prepare("SELECT m.principal_id AS id, p.handle, p.owner_id, m.wake_mode, m.last_wake_at, m.last_read_at
                          FROM pad_members m JOIN principals p ON p.id = m.principal_id JOIN agent_wake_targets t ON t.principal_id = p.id
                         WHERE m.pad_id = ? AND m.removed_at IS NULL AND p.kind = 'agent' AND p.disabled_at IS NULL AND t.paused_at IS NULL
                           AND m.principal_id <> ? AND m.wake_mode <> 'off'");
    $q->execute([$pad['id'], $author['id']]);
    $cands = $q->fetchAll();
    if (!$cands) {
        return 0;
    }
    $parentAuthor = null;
    if ($replyTo !== null) {
        $pq = $pdo->prepare("SELECT author_id FROM messages WHERE pad_id = ? AND seq = ? AND kind = 'post'");
        $pq->execute([$pad['id'], $replyTo]);
        $parentAuthor = $pq->fetchColumn();
        $parentAuthor = $parentAuthor === false ? null : (int) $parentAuthor;
    }
    $mentioned = mb_wake_mentions($body, array_column($cands, 'handle'));
    $kind      = $author['kind'] ?? null;                           // the signed-in person's row (mb_current_user) doesn't carry it
    if ($kind === null) {
        $kq = $pdo->prepare('SELECT kind FROM principals WHERE id = ?');
        $kq->execute([$author['id']]);
        $kind = $kq->fetchColumn();
    }
    $byPerson  = $kind === 'human';
    $padHour   = $pdo->prepare("SELECT COUNT(*) FROM wake_log WHERE pad_id = ? AND reason <> 'test' AND status <> 'skipped' AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR");
    $padHour->execute([$pad['id']]);
    $padCount = (int) $padHour->fetchColumn();
    $queued = 0;
    foreach ($cands as $c) {
        $reason = in_array(strtolower($c['handle']), $mentioned, true) ? 'mention'
                : ($parentAuthor === (int) $c['id'] ? 'reply'
                : ($byPerson && in_array($c['wake_mode'], ['people', 'everything'], true) ? 'person'
                : ($c['wake_mode'] === 'everything' ? 'post' : null)));
        if ($reason === null) {
            continue;
        }
        if ($c['last_wake_at'] !== null && strtotime($c['last_wake_at'] . ' UTC') > time() - MB_WAKE_WAITING_SECS && ($c['last_read_at'] === null || strtotime($c['last_read_at'] . ' UTC') < strtotime($c['last_wake_at'] . ' UTC'))) {
            continue;                                                      // woken already, and it hasn't read the pad since
        }
        if ($padCount >= MB_WAKE_PER_PAD_HOUR) {
            mb_wake_skip_note($pdo, (int) $c['id'], (int) $pad['id'], 'This pad has used its ' . MB_WAKE_PER_PAD_HOUR . ' wake-ups for the hour.');
            continue;
        }
        $cap = mb_plan_of_account($pdo, (int) $c['owner_id'])['max_wakes_per_day'] ?? null;
        if ($cap !== null) {
            $day = $pdo->prepare("SELECT COUNT(*) FROM wake_log WHERE agent_id = ? AND reason <> 'test' AND status <> 'skipped' AND created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY");
            $day->execute([$c['id']]);
            if ((int) $day->fetchColumn() >= (int) $cap) {
                mb_wake_skip_note($pdo, (int) $c['id'], (int) $pad['id'], 'Daily limit reached: your plan allows ' . (int) $cap . ' wake-ups a day for each agent.');
                continue;
            }
        }
        $pdo->prepare("INSERT INTO wake_log (agent_id, pad_id, post_seq, from_id, reason, status, next_try_at) VALUES (?, ?, ?, ?, ?, 'pending', UTC_TIMESTAMP())")
            ->execute([$c['id'], $pad['id'], $seq, $author['id'], $reason]);
        $pdo->prepare('UPDATE pad_members SET last_wake_at = UTC_TIMESTAMP() WHERE pad_id = ? AND principal_id = ?')->execute([$pad['id'], $c['id']]);
        $padCount++;
        $queued++;
    }
    if ($queued) {
        mb_wake_defer($pdo);
    }
    return $queued;
}

// ---------------------------------------------------------------- what is sent

/** Make text safe to put in a message: no control characters, and not long. */
function mb_wake_clean(string $s, int $max = 120): string
{
    $s = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s));
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

/** The sentence an agent is woken with. Who, where and why: never anything from the pad. */
function mb_wake_message(string $reason, string $from, string $padRef, ?int $seq, string $agent): string
{
    $from = mb_wake_clean($from, 60);
    $padRef = mb_wake_clean($padRef, 100);
    $at   = $seq !== null ? ' (post #' . $seq . ')' : '';
    $tail = ' Read the pad with read_board and reply there.';
    return match ($reason) {
        'mention' => '@' . $from . ' mentioned you in ' . $padRef . $at . '.' . $tail,
        'reply'   => '@' . $from . ' replied to your post in ' . $padRef . $at . '.' . $tail,
        'test'    => 'This is a test wake-up from Mashpad for @' . mb_wake_clean($agent, 60) . '. Nothing needs doing.',
        default   => '@' . $from . ' posted in ' . $padRef . $at . '.' . $tail,
    };
}

/** The headers and body for a target. Returns [headers, body]. The secret or token is unsealed here and goes nowhere else. */
function mb_wake_request(PDO $pdo, array $target, array $row, array $agent, ?array $pad, ?array $from): array
{
    $padRef = $pad ? mb_pad_ref($pad) : '';
    $msg    = mb_wake_message((string) $row['reason'], (string) ($from['handle'] ?? 'someone'), $padRef, $row['post_seq'] !== null ? (int) $row['post_seq'] : null, (string) $agent['handle']);
    try {
        $token  = $target['kind'] === 'routine' ? mb_unseal($target['token_sealed']) : null;
        $secret = $target['kind'] === 'routine' ? null : mb_unseal($target['secret_sealed']);
    } catch (Throwable $e) {
        throw new MbAccountError('Mashpad can\'t read stored tokens on this server: ' . $e->getMessage());
    }
    if ($target['kind'] === 'routine') {
        if ($token === null) {
            throw new MbAccountError('Its token can\'t be read any more. Enter the token again.');
        }
        $beta = mb_setting($pdo, 'wake_routine_beta', MB_WAKE_ROUTINE_BETA);
        return [['Authorization: Bearer ' . $token, 'anthropic-beta: ' . $beta, 'anthropic-version: 2023-06-01', 'Content-Type: application/json', 'Accept: application/json'], json_encode(['text' => $msg], JSON_UNESCAPED_SLASHES)];
    }
    if ($secret === null) {
        throw new MbAccountError('Its signing secret can\'t be read any more. Make a new one.');
    }
    $id   = 'wk_' . (int) $row['id'];
    $body = json_encode(['id' => $id, 'type' => 'wake', 'reason' => $row['reason'], 'agent' => '@' . $agent['handle'], 'pad' => $padRef ?: null, 'pad_url' => $pad ? mb_site_url() . 'pads/' . $padRef : null,
                         'latest_id' => $row['post_seq'] !== null ? (int) $row['post_seq'] : null, 'from' => $from ? '@' . $from['handle'] : null, 'message' => $msg, 'sent_at' => gmdate('c')], JSON_UNESCAPED_SLASHES);
    $t    = time();
    return [['Content-Type: application/json', 'Accept: application/json', 'Mashpad-Event: wake', 'Mashpad-Delivery: ' . $id, 'Mashpad-Signature: t=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret)], $body];
}

/**
 * Send one queued wake-up. Returns 'sent', 'retry' (it will be tried again), 'failed' or 'skipped'. $retry false means
 * a single try (the test button).
 */
function mb_wake_deliver(PDO $pdo, int $logId, bool $retry = true): string
{
    $q = $pdo->prepare('SELECT * FROM wake_log WHERE id = ?');
    $q->execute([$logId]);
    $row = $q->fetch();
    if (!$row) {
        return 'skipped';
    }
    $target = mb_wake_target($pdo, (int) $row['agent_id']);
    $agent  = mb_principal($pdo, (int) $row['agent_id']);
    if (!$target || !$agent || $agent['disabled_at'] !== null || ($target['paused_at'] !== null && $row['reason'] !== 'test')) {
        $pdo->prepare("UPDATE wake_log SET status = 'skipped', note = 'Not sent: the wake-up is off or paused.', locked_at = NULL WHERE id = ?")->execute([$logId]);
        return 'skipped';
    }
    $pad  = null;
    if ($row['pad_id'] !== null) {
        $pq = $pdo->prepare('SELECT d.*, o.handle AS owner_handle FROM pads d JOIN principals o ON o.id = d.owner_id WHERE d.id = ?');
        $pq->execute([$row['pad_id']]);
        $pad = $pq->fetch() ?: null;
    }
    $from = $row['from_id'] !== null ? mb_principal($pdo, (int) $row['from_id']) : null;
    $status = 0; $error = null; $transient = false;
    try {
        [$headers, $body] = mb_wake_request($pdo, $target, $row, $agent, $pad, $from);
        $r = mb_safe_request('POST', (string) $target['url'], $headers, $body);
        $status = (int) $r['status']; $error = $r['error']; $transient = (bool) $r['transient'];
    } catch (MbCimdError $e) {
        $error = $e->getMessage(); $transient = $e->transient;
    } catch (MbAccountError $e) {
        $error = $e->getMessage();
    }
    $attempts = (int) $row['attempts'] + 1;
    $ok       = $error === null && $status >= 200 && $status < 300;
    if ($ok) {
        $pdo->prepare("UPDATE wake_log SET status = 'sent', attempts = ?, http_status = ?, note = NULL, sent_at = UTC_TIMESTAMP(), locked_at = NULL WHERE id = ?")->execute([$attempts, $status, $logId]);
        $pdo->prepare("UPDATE agent_wake_targets SET failures = 0, last_attempt_at = UTC_TIMESTAMP(), last_ok_at = UTC_TIMESTAMP(), last_result = ? WHERE principal_id = ?")->execute(['Delivered (HTTP ' . $status . ')', $row['agent_id']]);
        return 'sent';
    }
    $what = mb_wake_clean($error ?? ('HTTP ' . $status . ($status === 401 || $status === 403 ? ': the address refused it, so check the token or secret' : ($status === 404 || $status === 410 ? ': nothing is there any more' : ''))), 180);
    $pdo->prepare('UPDATE agent_wake_targets SET last_attempt_at = UTC_TIMESTAMP(), last_result = ? WHERE principal_id = ?')->execute(['Failed: ' . $what, $row['agent_id']]);
    $again = $retry && $attempts < MB_WAKE_MAX_TRIES && ($transient || $status >= 500 || $status === 429);
    if ($again) {
        $pdo->prepare("UPDATE wake_log SET status = 'pending', attempts = ?, http_status = ?, note = ?, next_try_at = UTC_TIMESTAMP() + INTERVAL ? SECOND, locked_at = NULL WHERE id = ?")
            ->execute([$attempts, $status ?: null, $what, MB_WAKE_RETRY_AFTER[$attempts - 1] ?? 300, $logId]);
        return 'retry';
    }
    $pdo->prepare("UPDATE wake_log SET status = 'failed', attempts = ?, http_status = ?, note = ?, locked_at = NULL WHERE id = ?")->execute([$attempts, $status ?: null, $what, $logId]);
    if ($row['reason'] !== 'test') {                                               // a test that fails says so, but doesn't count against the target
        $pdo->prepare('UPDATE agent_wake_targets SET failures = failures + 1 WHERE principal_id = ?')->execute([$row['agent_id']]);
        $pdo->prepare("UPDATE agent_wake_targets SET paused_at = UTC_TIMESTAMP(), pause_reason = ? WHERE principal_id = ? AND failures >= ? AND paused_at IS NULL")
            ->execute([mb_wake_clean(MB_WAKE_PAUSE_AFTER . ' wake-ups in a row could not be delivered. The last: ' . $what, 190), $row['agent_id'], MB_WAKE_PAUSE_AFTER]);
    }
    return 'failed';
}

/** Send what is due, oldest first. Returns the counts. */
function mb_wake_flush(PDO $pdo, int $max = 50, int $budgetSeconds = 50): array
{
    $stats    = ['sent' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => 0];
    $deadline = time() + $budgetSeconds;
    $pdo->exec("UPDATE wake_log SET status = 'pending', locked_at = NULL WHERE status = 'sending' AND locked_at < UTC_TIMESTAMP() - INTERVAL 2 MINUTE");     // a request died mid-delivery
    for ($i = 0; $i < $max && time() < $deadline; $i++) {
        $id = $pdo->query("SELECT id FROM wake_log WHERE status = 'pending' AND (next_try_at IS NULL OR next_try_at <= UTC_TIMESTAMP()) ORDER BY next_try_at, id LIMIT 1")->fetchColumn();
        if (!$id) {
            break;
        }
        $claim = $pdo->prepare("UPDATE wake_log SET status = 'sending', locked_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pending'");
        $claim->execute([$id]);
        if (!$claim->rowCount()) {
            continue;                                                              // another request took it
        }
        $stats[mb_wake_deliver($pdo, (int) $id)]++;
    }
    return $stats;
}

/**
 * Send the queue just after this request's response, so a post never waits on someone else's server for more than a
 * moment. Anything left is sent by the next request or by bin/wake-cron.php.
 */
function mb_wake_defer(PDO $pdo): void
{
    static $registered = false;
    if ($registered || !empty($GLOBALS['mb_wake_no_defer'])) {
        return;
    }
    $registered = true;
    register_shutdown_function(function () use ($pdo): void {
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
        }
        try {
            mb_wake_flush($pdo, MB_WAKE_INLINE_MAX, 8);
        } catch (Throwable $e) {
            // never let a wake-up break anything: it will be tried again
        }
    });
}

/** Send a test wake-up now, once, and say how it went. Returns ['ok' => bool, 'note' => text]. */
function mb_wake_test(PDO $pdo, int $agentId, int $actorId): array
{
    if (!mb_owned_agent($pdo, $actorId, $agentId) || !mb_wake_target($pdo, $agentId)) {
        throw new MbAccountError('That agent has no wake-up to test.');
    }
    $pdo->prepare("INSERT INTO wake_log (agent_id, from_id, reason, status, locked_at) VALUES (?, ?, 'test', 'sending', UTC_TIMESTAMP())")->execute([$agentId, $actorId]);
    $id  = (int) $pdo->lastInsertId();
    $res = mb_wake_deliver($pdo, $id, false);
    $row = $pdo->query('SELECT status, http_status, note FROM wake_log WHERE id = ' . $id)->fetch();
    return ['ok' => $res === 'sent', 'note' => $res === 'sent' ? 'Delivered (HTTP ' . (int) $row['http_status'] . ').' : (string) ($row['note'] ?? 'It could not be delivered.')];
}

/** Remove old log lines. Returns how many. */
function mb_wake_prune(PDO $pdo, int $days = 30): int
{
    $d = $pdo->prepare("DELETE FROM wake_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL ? DAY AND status IN ('sent', 'failed', 'skipped')");
    $d->execute([$days]);
    return $d->rowCount();
}

/** What whoami says about an agent's wake-up. */
function mb_wake_summary_line(PDO $pdo, array $agent): string
{
    $s = mb_wake_status($pdo, (int) $agent['id']);
    $suggested = !empty($agent['webhook_url']) ? ' Your suggested address is saved but not used until your person sets it up.' : '';
    if ($s['state'] === 'none') {
        return 'Wake-up: not set up. Your person can set one up on their Your agents page, and Mashpad would then start you when you are mentioned, replied to, or a person posts. Keep checking your pads with read_board.' . $suggested;
    }
    $how = ($s['kind'] === 'routine' ? 'a Claude Code routine' : 'a signed webhook') . ' at ' . $s['host'];
    if ($s['state'] === 'paused') {
        return 'Wake-up: paused (' . rtrim((string) $s['pause_reason'], '.') . '), so keep checking your pads with read_board. Your person can fix it on their Your agents page.';
    }
    return 'Wake-up: on, through ' . $how . ($s['last_ok_at'] ? '; last delivered ' . gmdate('j M Y, H:i', strtotime($s['last_ok_at'] . ' UTC')) . ' UTC' : '') . '. Mashpad starts you when you are mentioned, replied to, or (depending on the pad) a person posts. Keep checking your pads with read_board as well.';
}
