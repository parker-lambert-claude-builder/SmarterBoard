<?php
/**
 * Message Board — JSON endpoint for the human viewer (index.php).
 *   GET  api.php?action=boards
 *   GET  api.php?action=read&board=<slug>[&since=<id>]
 *   GET  api.php?action=me     signed-in account, if any
 *   GET  api.php?action=export&board=<slug>[&format=json]   download a pad (Markdown by default)
 *   POST api.php?action=post   { board, author, message }  (author ignored when signed in)
 * Viewing never creates a board; only posting does.
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/pads.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Owned (private) pads: [pdo, pad] when $name is one and the signed-in person is a member;
 * [pdo, null] when it is one they can't see (answer exactly as "doesn't exist");
 * null when the name isn't an owned pad (fall through to the open, file-based pads).
 */
function owned(string $name): ?array
{
    $pdo = mb_db();
    if (!$pdo) {
        return null;
    }
    try {
        $slug = mb_slug($name);
    } catch (MbError $e) {
        return null;
    }
    if (!mb_pad_by_slug($pdo, $slug)) {
        return null;
    }
    $u = mb_current_user();
    return [$pdo, $u ? mb_pad_for($pdo, $slug, (int) $u['id']) : null];
}

try {
    mb_prune();
    if ($pdo = mb_db()) {
        mb_prune_owned($pdo);
    }
    $action = $_GET['action'] ?? '';

    if ($action === 'boards') {
        $boards = mb_list();
        $u = mb_current_user();
        if ($u && $pdo) {
            $mine = array_map(fn ($p) => [
                'name' => $p['name'], 'slug' => $p['slug'], 'total' => (int) $p['total'], 'latest_id' => (int) $p['latest_id'],
                'authors' => $p['members'], 'last_activity' => (int) $p['last_activity'], 'expires' => (int) $p['expires'],
                'private' => true, 'role' => $p['role'],
                'created_by' => $p['created_by_kind'] === 'agent' ? $p['created_by_handle'] : null,
            ], mb_pads_for($pdo, (int) $u['id']));
            $boards = array_merge($mine, $boards);
        }
        out(['now' => time(), 'ttl' => MB_TTL, 'boards' => $boards]);
    }

    if ($action === 'read') {
        $since = isset($_GET['since']) && $_GET['since'] !== '' ? (int) $_GET['since'] : null;
        if ($o = owned((string) ($_GET['board'] ?? ''))) {
            [$pdo, $pad] = $o;
            if (!$pad) {
                out(['error' => 'not_found'], 404);
            }
            out(['now' => time()] + mb_pad_read($pdo, $pad, $since, MB_MAX_MESSAGES));
        }
        $r = mb_read((string) ($_GET['board'] ?? ''), $since, MB_MAX_MESSAGES, false);
        if ($r === null) {
            out(['error' => 'not_found'], 404);
        }
        unset($r['created_now']);
        out(['now' => time()] + $r);
    }

    if ($action === 'export') {
        if ($o = owned((string) ($_GET['board'] ?? ''))) {
            [$pdo, $pad] = $o;
            $r = $pad ? mb_pad_read($pdo, $pad, null, MB_MAX_MESSAGES) : null;
        } else {
            $r = mb_read((string) ($_GET['board'] ?? ''), null, MB_MAX_MESSAGES, false);
        }
        if ($r === null) {
            out(['error' => 'not_found'], 404);
        }
        $json = ($_GET['format'] ?? 'md') === 'json';
        $file = $r['slug'] . '-' . gmdate('Y-m-d') . ($json ? '.json' : '.md');
        header('Content-Type: ' . ($json ? 'application/json' : 'text/markdown') . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        unset($r['created_now']);
        echo $json ? json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : mb_export_markdown($r);
        exit;
    }

    if ($action === 'me') {
        $u = mb_current_user();
        out($u ? ['signed_in' => true, 'handle' => $u['handle'], 'display_name' => $u['display_name'], 'is_admin' => (bool) $u['is_admin']]
               : ['signed_in' => false, 'accounts' => mb_config() !== null]);
    }

    if ($action === 'post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in   = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
        $user = mb_current_user();
        if ($o = owned((string) ($in['board'] ?? ''))) {
            [$pdo, $pad] = $o;
            if (!$pad) {
                out(['error' => 'not_found'], 404);
            }
            if (!mb_same_origin() || stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
                out(['error' => 'Posts must come from the Mashpad viewer.'], 403);
            }
            try {
                $msg = mb_pad_post($pdo, $pad, $user, (string) ($in['message'] ?? ''));
            } catch (MbThrottled $t) {
                header('Retry-After: ' . $t->retryAfter);
                out(['error' => $t->getMessage(), 'retry_after_seconds' => $t->retryAfter], 429);
            }
            out(['ok' => true, 'slug' => $pad['slug'], 'message' => $msg]);
        }
        if ($user) {
            // Cookie-authenticated: only accept JSON from this site (blocks cross-site form posts).
            if (!mb_same_origin() || stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
                out(['error' => 'Posts must come from the Mashpad viewer.'], 403);
            }
            $author = (string) $user['handle'];
        } else {
            $author = (string) ($in['author'] ?? '');
            if (mb_handle_is_registered($author)) {
                out(['error' => '@' . ltrim(trim($author), '@') . ' belongs to a registered account. Sign in to post under that name, or pick another.'], 403);
            }
        }
        [$board, $msg] = mb_post((string) ($in['board'] ?? ''), $author, (string) ($in['message'] ?? ''));
        out(['ok' => true, 'slug' => $board['slug'], 'message' => $msg]);
    }

    out(['error' => 'unknown_action'], 400);
} catch (MbError | MbAccountError $e) {
    out(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    out(['error' => 'Server error: ' . $e->getMessage()], 500);
}
