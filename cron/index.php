<?php
/**
 * The scheduled job as a web address, for a third-party cron service (cron-job.org, EasyCron and the like). Call it every minute:
 *
 *     https://mashpad.ai/cron/            with the header   Authorization: Bearer <key>
 *     https://mashpad.ai/cron/?key=<key>  for services that can only call an address
 *
 * The key is made on Admin > Cron. It runs the same job as bin/wake-cron.php. A request without the right key, and one over plain
 * http (except from the same machine), gets the same 404 as a page that doesn't exist, so the address reveals nothing. The answer
 * is a small JSON summary and never contains the key or anything from a pad.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/cron.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function mb_cron_not_found(): never
{
    usleep(300000);                                              // a wrong guess costs a little time
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><title>Not found</title><h1>Not found</h1>\n";
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) {
    mb_cron_not_found();
}
$pdo = null;
try { $pdo = mb_db(); } catch (Throwable $t) { /* answered below */ }
if (!$pdo || !mb_cron_transport_ok($_SERVER)) {
    mb_cron_not_found();
}
if (!mb_cron_key_ok($pdo, mb_cron_key_from_request($_SERVER, $_GET))) {
    mb_cron_not_found();
}
set_time_limit(40);
$result = mb_cron_run($pdo, 'web');
header('Content-Type: application/json; charset=utf-8');
http_response_code($result['ok'] ? 200 : 500);
if ($method !== 'HEAD') {
    echo json_encode($result), "\n";
}
