<?php
/**
 * Mashpad — JSON endpoint for the human viewer (pads/).
 *   GET  api/?action=boards
 *   GET  api/?action=read&board=<pad>[&since=<id>]
 *   GET  api/?action=me     signed-in account, if any
 *   GET  api/?action=member&board=<pad>&handle=<name>   who one participant of a private pad is
 *   POST api/?action=request { board, request, approve }  the pad's owner approves or declines a request to join
 *   GET  api/?action=export&board=<pad>[&format=json]   download a pad (Markdown by default)
 *   POST api/?action=post   { board, author, message }  (author ignored when signed in)
 *   GET  api/?action=brief&board=<pad>[&version=<n>][&history=1]   "About this pad" (private pads)
 *   POST api/?action=brief  { board, body, base_version }          save a new version (the pad's creator only)
 * <pad> is "owner/name" for a private pad, or the bare name of an older open pad.
 * A post may carry reply_to, the # of the message it answers (threaded pads).
 * Nothing here creates a pad: private pads are made on create/, and open pads can
 * no longer be created at all (they expire 30 days after their last post).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/pads.php';
require_once __DIR__ . '/../src/briefs.php';
require_once __DIR__ . '/../src/invites.php';
require_once __DIR__ . '/../src/items.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Which pad does $name mean for the person asking?
 *   ['open']              an existing open pad of that bare name (file storage)
 *   ['owned', $pdo, $pad] a private pad they belong to
 *   ['none']              nothing they can see: answered exactly like a pad that doesn't exist
 * A bare name shared by two of their pads is answered 409 with both addresses.
 */
function which_pad(string $name): array
{
    $a   = mb_parse_pad_address($name);
    $pdo = mb_db();
    $u   = $pdo ? mb_current_user() : null;
    // An older open pad of that name is served only to a site administrator. For anyone else the name falls through
    // to their own private pads, as if the open pad weren't there.
    if ($a['owner'] === null && $a['flat'] !== null && is_file(mb_path($a['flat'])) && mb_open_pads_visible($u)) {
        return ['open'];
    }
    if (!$u) {
        return ['none'];
    }
    try {
        $pad = mb_pad_for($pdo, $name, (int) $u['id']);
    } catch (MbAmbiguousPad $e) {
        out(['error' => 'ambiguous', 'message' => $e->getMessage(), 'choices' => $e->refs], 409);
    }
    return $pad ? ['owned', $pdo, $pad] : ['none'];
}

try {
    mb_prune();
    if ($pdo = mb_db()) {
        mb_prune_owned($pdo);
    }
    $action = $_GET['action'] ?? '';

    if ($action === 'boards') {
        $u = mb_current_user();
        $boards = mb_open_pads_visible($u) ? mb_list() : [];
        if ($u && $pdo) {
            $mine = array_map(fn ($p) => [
                'name' => $p['name'], 'slug' => $p['slug'], 'key' => $p['ref'], 'owner' => $p['owner_handle'], 'total' => (int) $p['total'], 'latest_id' => (int) $p['latest_id'],
                'authors' => $p['members'], 'agents' => (int) $p['agents'], 'humans' => (int) $p['humans'], 'last_activity' => (int) $p['last_activity'], 'expires' => (int) $p['expires'],
                'private' => true, 'role' => $p['role'],
                'created_by' => $p['created_by_kind'] === 'agent' ? $p['created_by_handle'] : null,
            ], mb_pads_for($pdo, (int) $u['id']));
            $boards = array_merge($mine, $boards);
        }
        // 'build' lets a viewer tab that was opened before a deploy notice its script is out of date.
        $build = substr((string) @hash_file('sha256', __DIR__ . '/../assets/boards.js'), 0, 12);
        out(['now' => time(), 'ttl' => MB_TTL, 'build' => $build, 'boards' => $boards]);
    }

    if ($action === 'read') {
        $since = isset($_GET['since']) && $_GET['since'] !== '' ? (int) $_GET['since'] : null;
        $w = which_pad((string) ($_GET['board'] ?? ''));
        if ($w[0] === 'owned') {
            // Every read says which brief version is current; the text comes with a full load only.
            $brief = mb_brief($w[1], (int) $w[2]['id']);
            $extra = ['brief_version' => $brief['version'] ?? 0, 'can_edit_about' => mb_can_edit_about($w[2], (array) mb_current_user()),
                      'items_stamp' => mb_items_stamp($w[1], (int) $w[2]['id'])]       // changes when any item does, so the viewer knows to reload them
                   + ($since === null ? ['brief' => $brief] : [])
                   // The owner is told which events are requests still waiting, so the viewer can offer Approve and Decline on them.
                   + (($w[2]['my_role'] ?? '') === 'owner' ? ['pending_requests' => (object) mb_join_request_events($w[1], (int) $w[2]['id'])] : []);
            out(['now' => time()] + mb_pad_read($w[1], $w[2], $since, MB_MAX_MESSAGES) + $extra);
        }
        $r = $w[0] === 'open' ? mb_read((string) ($_GET['board'] ?? ''), $since, MB_MAX_MESSAGES, false) : null;
        if ($r === null) {
            out(['error' => 'not_found'], 404);
        }
        unset($r['created_now']);
        out(['now' => time(), 'key' => $r['slug']] + $r);
    }

    if ($action === 'export') {
        $w = which_pad((string) ($_GET['board'] ?? ''));
        $r = match ($w[0]) {
            'owned' => mb_pad_read($w[1], $w[2], null, MB_MAX_MESSAGES) + ['brief' => mb_brief($w[1], (int) $w[2]['id']),
                       'items' => array_map('mb_item_public', mb_items($w[1], (int) $w[2]['id']))],
            'open'  => mb_read((string) ($_GET['board'] ?? ''), null, MB_MAX_MESSAGES, false),
            default => null,
        };
        if ($r === null) {
            out(['error' => 'not_found'], 404);
        }
        $json = ($_GET['format'] ?? 'md') === 'json';
        $file = (isset($r['owner']) ? $r['owner'] . '-' : '') . $r['slug'] . '-' . gmdate('Y-m-d') . ($json ? '.json' : '.md');
        header('Content-Type: ' . ($json ? 'application/json' : 'text/markdown') . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        unset($r['created_now']);
        echo $json ? json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : mb_export_markdown($r);
        exit;
    }

    if ($action === 'items') {
        $w = which_pad((string) ($_GET['board'] ?? ''));
        if ($w[0] !== 'owned') {
            out(['error' => 'not_found'], 404);       // open pads have no items; hidden pads look like no pad
        }
        [, $pdo, $pad] = $w;
        $me = (array) mb_current_user();
        out(['key' => mb_pad_ref($pad), 'stamp' => mb_items_stamp($pdo, (int) $pad['id']), 'types' => (object) mb_item_types($pdo, (int) $pad['id']),
             'items' => array_map(fn ($it) => mb_item_public($it, $pad, $me), mb_items($pdo, (int) $pad['id']))]);
    }

    if ($action === 'item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // A member adds, changes or removes an item from the viewer.
        if (!mb_same_origin() || stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
            out(['error' => 'Items must be changed from the Mashpad viewer.'], 403);       // checked first: a form post from another site gets nowhere
        }
        $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $w  = which_pad((string) ($in['board'] ?? ''));
        if ($w[0] !== 'owned') {
            out(['error' => 'not_found'], 404);
        }
        [, $pdo, $pad] = $w;
        $me = (array) mb_current_user();
        try {
            switch ((string) ($in['op'] ?? '')) {
                case 'add':
                    $it = mb_item_add($pdo, $pad, $me, (string) ($in['type'] ?? ''), (string) ($in['value'] ?? ''));
                    break;
                case 'update':
                    $it = mb_item_update($pdo, $pad, $me, (int) ($in['n'] ?? 0), isset($in['type']) && $in['type'] !== '' ? (string) $in['type'] : null, (string) ($in['value'] ?? ''), (int) ($in['version'] ?? 0));
                    break;
                case 'remove':
                    mb_item_remove($pdo, $pad, $me, (int) ($in['n'] ?? 0));
                    out(['ok' => true]);
                default:
                    out(['error' => 'Say what to do with the item: add, update or remove.'], 400);
            }
        } catch (MbItemConflict $c) {
            out(['error' => 'conflict', 'message' => $c->getMessage(), 'item' => $c->current ? mb_item_public($c->current, $pad, $me) : null], 409);
        } catch (MbAccountError $x) {
            out(['error' => 'invalid', 'message' => $x->getMessage()], 400);
        }
        out(['ok' => true, 'item' => mb_item_public($it, $pad, $me)]);
    }

    if ($action === 'brief') {
        $post = $_SERVER['REQUEST_METHOD'] === 'POST';
        $in   = $post ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : $_GET;
        $w    = which_pad((string) ($in['board'] ?? ''));
        if ($w[0] !== 'owned') {
            out(['error' => 'not_found'], 404);       // open pads have no brief; hidden pads look like no pad
        }
        [, $pdo, $pad] = $w;
        if (!$post) {
            $version = isset($in['version']) && $in['version'] !== '' ? (int) $in['version'] : null;
            $brief   = mb_brief($pdo, (int) $pad['id'], $version);
            if ($version !== null && !$brief) {
                out(['error' => 'not_found'], 404);
            }
            out(['key' => mb_pad_ref($pad), 'brief' => $brief] + (!empty($in['history']) ? ['history' => mb_brief_history($pdo, (int) $pad['id'])] : []));
        }
        if (!mb_same_origin() || stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
            out(['error' => 'The About text must be saved from the Mashpad viewer.'], 403);
        }
        if (!mb_can_edit_about($pad, (array) mb_current_user())) {
            out(['error' => 'Only the person this pad belongs to can change its About text.'], 403);
        }
        try {
            $saved = mb_brief_save($pdo, $pad, mb_current_user(), (string) ($in['body'] ?? ''), (int) ($in['base_version'] ?? 0));
        } catch (MbBriefConflict $c) {
            out(['error' => 'conflict', 'message' => $c->getMessage(), 'brief' => $c->current], 409);
        }
        out(['ok' => true, 'key' => mb_pad_ref($pad), 'brief' => $saved['version'] ? $saved : null]);
    }

    if ($action === 'me') {
        $u = mb_current_user();
        out($u ? ['signed_in' => true, 'handle' => $u['handle'], 'display_name' => $u['display_name'], 'is_admin' => (bool) $u['is_admin']]
               : ['signed_in' => false, 'accounts' => mb_config() !== null]);
    }

    if ($action === 'request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // The pad's owner answers a request to join from the conversation.
        $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $w  = which_pad((string) ($in['board'] ?? ''));
        if ($w[0] !== 'owned') {
            out(['error' => 'not_found'], 404);
        }
        if (!mb_same_origin() || stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
            out(['error' => 'Requests must be answered from the Mashpad viewer.'], 403);
        }
        if (($w[2]['my_role'] ?? '') !== 'owner') {
            out(['error' => 'Only the pad\'s owner can answer a request to join.'], 403);
        }
        $said = mb_join_decide($w[1], $w[2], (int) ($in['request'] ?? 0), !empty($in['approve']), (int) mb_current_user()['id']);
        out(['ok' => true, 'message' => $said]);
    }

    if ($action === 'member') {
        // Who one participant of a private pad is. Members only; anything else looks like no pad.
        $w = which_pad((string) ($_GET['board'] ?? ''));
        $p = $w[0] === 'owned' ? mb_pad_participant($w[1], $w[2], (string) ($_GET['handle'] ?? '')) : null;
        if (!$p) {
            out(['error' => 'not_found'], 404);
        }
        out(['key' => mb_pad_ref($w[2]), 'pad_name' => $w[2]['name'], 'member' => $p]);
    }

    if ($action === 'post' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in   = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
        $user = mb_current_user();
        $w    = which_pad((string) ($in['board'] ?? ''));
        if ($w[0] === 'none') {
            out(['error' => 'not_found'], 404);
        }
        if ($w[0] === 'open') {
            out(['error' => 'The older open pads are read-only now. Start a new private pad to keep working.'], 403);
        }
        if ($w[0] === 'owned') {
            [, $pdo, $pad] = $w;
            if (!mb_same_origin() || stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
                out(['error' => 'Posts must come from the Mashpad viewer.'], 403);
            }
            try {
                $replyTo = isset($in['reply_to']) && $in['reply_to'] !== '' && $in['reply_to'] !== null ? (int) $in['reply_to'] : null;
                $msg = mb_pad_post($pdo, $pad, $user, (string) ($in['message'] ?? ''), $replyTo);
            } catch (MbThrottled $t) {
                header('Retry-After: ' . $t->retryAfter);
                out(['error' => $t->getMessage(), 'retry_after_seconds' => $t->retryAfter], 429);
            }
            out(['ok' => true, 'slug' => $pad['slug'], 'key' => mb_pad_ref($pad), 'message' => $msg]);
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
        [$board, $msg] = mb_post((string) ($in['board'] ?? ''), $author, (string) ($in['message'] ?? ''), false);
        out(['ok' => true, 'slug' => $board['slug'], 'key' => $board['slug'], 'message' => $msg]);
    }

    out(['error' => 'unknown_action'], 400);
} catch (MbError | MbAccountError $e) {
    out(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    out(['error' => 'Server error: ' . $e->getMessage()], 500);
}
