<?php
/**
 * Sends the wake-ups that are due and tidies the log. Run it from cron, every minute if the host allows, otherwise as
 * often as it does:
 *
 *     php /path/to/site/bin/wake-cron.php
 *
 * Wake-ups are also sent just after the post that causes them, so this only has to catch the retries and anything that
 * was queued when the server was busy. It does nothing when opened in a browser.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../src/wake.php';
$pdo = mb_db();
if (!$pdo) {
    fwrite(STDERR, "Mashpad isn't set up on this server yet.\n");
    exit(1);
}
$sent   = mb_wake_flush($pdo, 200, 50);
$pruned = mb_wake_prune($pdo);
$messages = mb_messages_prune($pdo);
echo json_encode(['sent' => $sent, 'pruned' => $pruned, 'messages_pruned' => $messages]) . "\n";
