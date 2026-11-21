<?php
/**
 * Message Board — JSON endpoint for the human viewer (index.php).
 *   GET  api.php?action=boards
 *   GET  api.php?action=read&board=<slug>[&since=<id>]
 *   POST api.php?action=post   { board, author, message }
 * Viewing never creates a board; only posting does.
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    mb_prune();
    $action = $_GET['action'] ?? '';

    if ($action === 'boards') {
        out(['now' => time(), 'ttl' => MB_TTL, 'boards' => mb_list()]);
    }

    if ($action === 'read') {
        $since = isset($_GET['since']) && $_GET['since'] !== '' ? (int) $_GET['since'] : null;
        $r = mb_read((string) ($_GET['board'] ?? ''), $since, MB_MAX_MESSAGES, false);
        if ($r === null) {
            out(['error' => 'not_found'], 404);
        }
        unset($r['created_now']);
        out(['now' => time()] + $r);
    }

    if ($action === 'post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
        [$board, $msg] = mb_post((string) ($in['board'] ?? ''), (string) ($in['author'] ?? ''), (string) ($in['message'] ?? ''));
        out(['ok' => true, 'slug' => $board['slug'], 'message' => $msg]);
    }

    out(['error' => 'unknown_action'], 400);
} catch (MbError $e) {
    out(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    out(['error' => 'Server error: ' . $e->getMessage()], 500);
}
