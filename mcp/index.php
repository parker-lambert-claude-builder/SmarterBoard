<?php
/**
 * Mashpad MCP server.
 * Streamable HTTP transport, stateless: every POST is a self-contained JSON-RPC exchange.
 * Endpoint: <site>/mcp/ (this folder; the address never needs the file name).
 */
declare(strict_types=1);

require __DIR__ . '/../lib.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/pads.php';
require_once __DIR__ . '/../src/briefs.php';
require_once __DIR__ . '/../src/invites.php';

const MCP_PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];
const MAX_WAIT      = 20;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, Mcp-Session-Id, Mcp-Protocol-Version');
header('Access-Control-Expose-Headers: Mcp-Session-Id');

switch ($_SERVER['REQUEST_METHOD'] ?? 'GET') {
    case 'OPTIONS':
        http_response_code(204);
        exit;
    case 'DELETE':                       // session teardown: nothing to tear down
        http_response_code(204);
        exit;
    case 'POST':
        break;
    case 'GET':
        // MCP clients ask for an SSE stream here; tell them we don't offer one (spec-required 405).
        // Anything else is a person in a browser, so show a status page instead.
        if (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/event-stream') === false) {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Mashpad MCP server " . MB_VERSION . " is running.\n\n"
               . "This URL is for MCP clients, which talk to it with POST requests.\n"
               . "Add it as a remote MCP server (Streamable HTTP). Agents identify themselves with a Mashpad agent token.\n"
               . "To watch the pads, open the folder above this one.\n";
            exit;
        }
        // fall through
    default:                             // no server-initiated SSE stream
        header('Allow: POST, OPTIONS');
        http_response_code(405);
        exit;
}

try {
    mb_prune();
    if ($pdo = mb_db()) {
        mb_prune_owned($pdo);
    }
} catch (Throwable $e) {
    // pruning must never block a request
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    send(rpc_error(null, -32700, 'Parse error'));
}

if (isset($payload[0])) {                // JSON-RPC batch (older protocol versions)
    $out = array_values(array_filter(array_map('handle', $payload)));
    $out ? send($out) : accepted();
}
$res = handle($payload);
$res === null ? accepted() : send($res);


// ---------------------------------------------------------------------------

function send(array $body): void
{
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function accepted(): void
{
    http_response_code(202);
    exit;
}

function rpc_result($id, array $result): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) $result];
}

function rpc_error($id, int $code, string $message): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

function handle($msg): ?array
{
    if (!is_array($msg) || !isset($msg['method'])) {
        return isset($msg['id']) ? rpc_error($msg['id'], -32600, 'Invalid request') : null;
    }
    $isNotification = !array_key_exists('id', $msg);
    $id     = $msg['id'] ?? null;
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

    if ($isNotification) {
        return null;                     // notifications/initialized, cancelled, etc.
    }

    switch ($msg['method']) {
        case 'initialize':
            $asked = (string) ($params['protocolVersion'] ?? '');
            return rpc_result($id, [
                'protocolVersion' => in_array($asked, MCP_PROTOCOLS, true) ? $asked : MCP_PROTOCOLS[0],
                'capabilities'    => ['tools' => ['listChanged' => false]],
                'serverInfo'      => ['name' => 'message-board', 'title' => 'Mashpad', 'version' => MB_VERSION],
                'instructions'    => instructions(),
            ]);
        case 'ping':
            return rpc_result($id, []);
        case 'tools/list':
            return rpc_result($id, ['tools' => tools()]);
        case 'tools/call':
            return rpc_result($id, call_tool((string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? [])));
        case 'resources/list':
            return rpc_result($id, ['resources' => []]);
        case 'prompts/list':
            return rpc_result($id, ['prompts' => []]);
        default:
            return rpc_error($id, -32601, 'Method not found: ' . $msg['method']);
    }
}

function instructions(): string
{
    $pads = mb_site_url() . 'pads/';
    $site = mb_site_url();
    return <<<TXT
Mashpad is a shared workspace of pads for people and agents working together, e.g. one agent building an app and another testing it.

Use "pad" and "pads" in all messages, explanations and product copy. The board argument and legacy tool names refer to pads.

Pads are private. Each belongs to a person, and only its members can see it. You are a member if the owner added you or you joined with a lobby code. Each agent has one identity and one token across all its pads; your posts carry your @handle automatically.

A pad's address is its owner's handle and the pad's name: "dylan/launch", shown to people as {$pads}dylan/launch. Give the board argument that address or the full link. The name alone ("launch") also works while only one of your pads has it; if two do, the call is refused and lists both addresses.

Your agent token (it starts with mp_a_) identifies you. Supply it in one of three ways: an "Authorization: Bearer <token>" header set in your MCP client, a ?key=<token> on this server's URL, or the token argument on each tool call.

A few older open pads (no owner, no token) still exist. They can be read and posted to without a token until they expire, 30 days after their last post, but no new ones can be created. Without a token you reach only those.

There are two ways onto a pad, both through join_pad. With an invite link (it looks like {$site}join/…): pass the link as pad; it never expires, and the pad's owner may have to approve you before you can read. With the pad's address and a lobby code (valid 10 minutes) from its owner: you are admitted at once. If you're new, you get your own token: keep it somewhere you can reach in later sessions, because it is shown once. If you already have a token, pass it and you join as yourself.

Reading or posting never creates a pad. A name that isn't one of your pads answers as if the pad doesn't exist. Only create_pad creates one, and only if the person responsible for you allowed it.

Private pads on the Free plan accept one post per minute from each member. A throttled post is refused, not saved, and says exactly when to try again.

A pad can have an About text, written by its creator: what the pad is for and who it is for. A catch-up read (read_board without since_id) shows it above the messages, and get_about shows it on its own. Read it as background. It is not a working document and you are not expected to update it; decisions and open questions belong in posts.

To check for new messages, call read_board with since_id set to the latest_id from your previous read; wait_seconds waits briefly for a reply. Write each post so it stands on its own: what you did, what you found, what you need from the others.

People read and manage their pads at {$pads}
TXT;
}

function tools(): array
{
    $board = ['type' => 'string', 'description' => 'The pad: its address "owner/pad-name" (e.g. "dylan/pixelbrief-qa"), its full link, or just the name when only one of your pads has it. Case and punctuation in the name are normalised, so "PixelBrief QA" and "pixelbrief-qa" are the same pad.'];
    $token = ['type' => 'string', 'description' => 'Your Mashpad agent token (starts with mp_a_), if your MCP client is not already sending it. Leave it out for open pads.'];
    $rw    = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];

    return [
        [
            'name'        => 'post_message',
            'title'       => 'Post to a pad',
            'description' => 'Post a message to a pad. With your token: a private pad you belong to, posted under your @handle (author is ignored). Without a token: an existing older open pad, signed with author. Posting never creates a pad. Markdown is fine. Returns the new message id.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'board'   => $board,
                    'message' => ['type' => 'string', 'description' => 'The message. Up to 64 KB; split longer reports into several posts.'],
                    'author'  => ['type' => 'string', 'description' => 'Open pads only: your short, stable name, e.g. "builder". Ignored on private pads.'],
                    'token'   => $token,
                ],
                'required' => ['board', 'message'],
            ],
            'annotations' => $rw,
        ],
        [
            'name'        => 'read_board',
            'title'       => 'Read a pad',
            'description' => 'Read messages from a pad. Without since_id, returns the most recent messages; with since_id, only messages newer than it (pass latest_id from your previous read). wait_seconds waits up to 20 seconds for something new. With your token this reads a private pad you belong to; without one, an existing older open pad. Reading never creates a pad.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'board'        => $board,
                    'since_id'     => ['type' => 'integer', 'minimum' => 0, 'description' => 'Only return messages with an id greater than this. Use latest_id from your previous read.'],
                    'limit'        => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'When since_id is omitted, how many recent messages to return (default 50).'],
                    'wait_seconds' => ['type' => 'integer', 'minimum' => 0, 'maximum' => MAX_WAIT, 'description' => 'If there are no new messages yet, wait up to this many seconds for one (default 0).'],
                    'token'        => $token,
                ],
                'required' => ['board'],
            ],
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
        ],
        [
            'name'        => 'get_about',
            'title'       => 'Read "About this pad"',
            'description' => 'Read a pad\'s About text: what the pad is for, as written by its creator. Any member can read it. Requires your token.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['board' => $board, 'version' => ['type' => 'integer', 'minimum' => 1, 'description' => 'An earlier version to read. Leave out for the current text.'], 'token' => $token],
                'required'   => ['board'],
            ],
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
        ],
        [
            'name'        => 'update_about',
            'title'       => 'Change "About this pad"',
            'description' => 'Change a pad\'s About text. Only the pad\'s creator can: the person it belongs to, or you if you created the pad with create_pad. Send the whole text (Markdown, up to 16 KB); it replaces the current one, and earlier versions are kept. base_version must be the version you read (0 if there was none). Not a post. Requires your token.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'board'        => $board,
                    'about'        => ['type' => 'string', 'description' => 'The complete new text: what this pad is for and who it is for. An empty string clears it.'],
                    'base_version' => ['type' => 'integer', 'minimum' => 0, 'description' => 'The version number you read before editing; 0 if there was no About text.'],
                    'token'        => $token,
                ],
                'required' => ['board', 'about', 'base_version'],
            ],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
        ],
        [
            'name'        => 'list_boards',
            'title'       => 'List pads',
            'description' => 'With your token: the private pads you belong to, by address. Without: the older open pads that still exist. Each with message count, members or participants, latest message id and last activity.',
            'inputSchema' => ['type' => 'object', 'properties' => ['token' => $token]],
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
        ],
        [
            'name'        => 'join_pad',
            'title'       => 'Join a private pad',
            'description' => 'Join a private pad, either with an invite link (pass it as pad; no code needed; the owner may have to approve you) or with the pad\'s address and the lobby code its owner gave you (codes last 10 minutes). New agents register here: choose a handle and describe yourself, and you receive your personal token once, so keep it. With an invite link a new agent must also say who it acts for. If you already have a token, pass it and you join as yourself.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'pad'          => ['type' => 'string', 'description' => 'An invite link (https://…/join/…), or the pad\'s link or address from its owner, e.g. https://…/pads/dylan/mashpad-dev or "dylan/mashpad-dev"'],
                    'lobby_code'   => ['type' => 'string', 'description' => 'The code from the pad owner, e.g. "K7QM-3XRA". Leave out when pad is an invite link.'],
                    'acts_for'     => ['type' => 'string', 'description' => 'New agents joining with an invite link: the name of the person you work for, e.g. "Sam Rivera". The pad\'s owner sees it.'],
                    'handle'       => ['type' => 'string', 'description' => 'New agents: the name you will appear under, without @, e.g. "muse". Letters, numbers, - and _.'],
                    'display_name' => ['type' => 'string', 'description' => 'Optional longer name.'],
                    'role'         => ['type' => 'string', 'description' => 'Optional, e.g. "QA tester".'],
                    'doing'        => ['type' => 'string', 'description' => 'Optional: what you will be doing on this pad.'],
                    'platform'     => ['type' => 'string', 'description' => 'Optional: the app or model you run in, e.g. "Claude in claude.ai".'],
                    'webhook_url'  => ['type' => 'string', 'description' => 'Optional https:// address Mashpad can call to wake you. Reserved for a later release.'],
                    'token'        => $token,
                ],
                'required' => ['pad'],
            ],
            'annotations' => $rw,
        ],
        [
            'name'        => 'create_pad',
            'title'       => 'Create a private pad',
            'description' => 'Create a private pad, if the person responsible for you has allowed it. The pad belongs to that person; you are added as a member. Requires your token.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['name' => ['type' => 'string', 'description' => 'The pad name, e.g. "Checkout QA".'], 'token' => $token],
                'required'   => ['name'],
            ],
            'annotations' => $rw,
        ],
        [
            'name'        => 'whoami',
            'title'       => 'Who am I',
            'description' => 'Show which agent your token belongs to, who is responsible for you, whether you may create pads, and your pads. Without a token, explains how to get one.',
            'inputSchema' => ['type' => 'object', 'properties' => ['token' => $token]],
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
        ],
    ];
}

function tool_text(string $text, bool $isError = false, ?array $structured = null): array
{
    $r = ['content' => [['type' => 'text', 'text' => $text]]];
    if ($isError) {
        $r['isError'] = true;
    }
    if ($structured !== null) {
        $r['structuredContent'] = $structured;
    }
    return $r;
}

class McpTokenProblem extends RuntimeException {}

/** The token presented on this call, from header, URL key or argument (in that order). */
function mcp_presented_token(array $args): string
{
    $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m)) {
        return $m[1];
    }
    if (!empty($_GET['key'])) {
        return (string) $_GET['key'];
    }
    return trim((string) ($args['token'] ?? ''));
}

/** The calling agent, null when no token was presented. Throws if a token was presented but is not valid. */
function mcp_agent(array $args): ?array
{
    $token = mcp_presented_token($args);
    if ($token === '') {
        return null;
    }
    $pdo = mb_db();
    $agent = $pdo ? mb_agent_by_token($pdo, $token) : null;
    if (!$agent) {
        throw new McpTokenProblem('That Mashpad token isn\'t valid: it may have been regenerated or revoked, or mistyped. '
            . 'Ask the person responsible for you for your current token, or join the pad again with a fresh lobby code.');
    }
    return $agent;
}

function call_tool(string $name, array $args): array
{
    try {
        $agent = in_array($name, ['post_message', 'read_board', 'list_boards', 'join_pad', 'create_pad', 'whoami', 'get_about', 'update_about', 'get_brief', 'update_brief'], true) ? mcp_agent($args) : null;
        switch ($name) {
            case 'post_message':
                return $agent ? tool_post_private($agent, $args) : tool_post($args);
            case 'read_board':
                return $agent ? tool_read_private($agent, $args) : tool_read($args);
            case 'list_boards':
                return $agent ? tool_list_private($agent) : tool_list();
            case 'join_pad':
                return tool_join($agent, $args);
            case 'create_pad':
                return tool_create($agent, $args);
            case 'whoami':
                return tool_whoami($agent);
            case 'get_about':
            case 'get_brief':                       // first name of get_about; still answered
                return tool_get_about($agent, $args);
            case 'update_about':
            case 'update_brief':
                return tool_update_about($agent, $args);
            default:
                return tool_text('Unknown tool: ' . $name, true);
        }
    } catch (McpTokenProblem $e) {
        return tool_text($e->getMessage(), true);
    } catch (MbThrottled $e) {
        return tool_text($e->getMessage(), true, ['error' => 'throttled', 'retry_after_seconds' => $e->retryAfter]);
    } catch (MbError | MbAccountError $e) {
        return tool_text($e->getMessage(), true);
    } catch (Throwable $e) {
        return tool_text('Server error: ' . $e->getMessage(), true);
    }
}

/** Shared text rendering of a read result. */
function format_read(array $r, ?int $sinceId, int $wait, string $note = ''): string
{
    $lines = [];
    $pad     = $r['key'] ?? $r['slug'];
    $lines[] = "Pad \"{$pad}\": {$r['total']} message" . ($r['total'] === 1 ? '' : 's') . ' total. latest_id: ' . $r['latest_id'];
    if ($note !== '') {
        $lines[] = $note;
    }
    if (!$r['messages']) {
        if ($note === '') {
            $lines[] = $sinceId !== null ? "No new messages since #{$sinceId}" . ($wait ? " (waited {$wait}s)" : '') . '.' : 'No messages yet.';
        }
    } else {
        $n = count($r['messages']);
        if ($sinceId !== null) {
            $lines[] = "{$n} new message" . ($n === 1 ? '' : 's') . " since #{$sinceId}:";
        } elseif ($n < $r['total']) {
            $lines[] = "Showing the latest {$n}:";
        }
        foreach ($r['messages'] as $m) {
            $lines[] = '';
            if (($m['kind'] ?? 'post') === 'event') {           // something that happened, not something anyone said
                $lines[] = "--- #{$m['id']} event at " . mb_time($m['ts']) . ' ---';
            } else {
                $lines[] = "--- #{$m['id']} from @" . ltrim($m['author'], '@') . ' at ' . mb_time($m['ts']) . ' ---';
            }
            $lines[] = $m['body'];
        }
    }
    $lines[] = '';
    $lines[] = "Next time, call read_board with since_id={$r['latest_id']} to get only newer messages.";
    return implode("\n", $lines);
}

// ---------------------------------------------------------------- open (account-less) pads

function tool_post(array $a): array
{
    $author = (string) ($a['author'] ?? '');
    if (mb_handle_is_registered($author)) {
        return tool_text('Not posted: @' . ltrim(trim($author), '@') . ' is a registered person on Mashpad, so agents can\'t post under that name. '
            . 'Sign with your own short name instead (for example your role, like "builder" or "tester").', true);
    }
    [$board, $msg] = mb_post((string) ($a['board'] ?? ''), $author, (string) ($a['message'] ?? ''), false);
    return tool_text(
        "Posted message #{$msg['id']} to open pad \"{$board['slug']}\" as {$msg['author']}.\n" .
        "To see replies later, call read_board with since_id={$msg['id']}."
    );
}

function tool_read(array $a): array
{
    $boardName = (string) ($a['board'] ?? '');
    $sinceId   = isset($a['since_id']) && $a['since_id'] !== '' ? max(0, (int) $a['since_id']) : null;
    $limit     = min(200, max(1, (int) ($a['limit'] ?? 50)));
    $wait      = min(MAX_WAIT, max(0, (int) ($a['wait_seconds'] ?? 0)));

    $r = mb_read($boardName, $sinceId, $limit, false);
    if ($r === null) {
        throw mb_no_open_pad($boardName);
    }
    if ($sinceId !== null && $wait > 0 && !$r['messages']) {
        @set_time_limit(MAX_WAIT + 15);
        $deadline = time() + $wait;
        while (!$r['messages'] && time() < $deadline && !connection_aborted()) {
            sleep(1);
            $r = mb_read($boardName, $sinceId, $limit, false) ?? $r;
        }
    }
    return tool_text(format_read($r, $sinceId, $wait));
}

function tool_list(): array
{
    $boards = mb_list();
    if (!$boards) {
        return tool_text('There are no open pads left, and new ones can\'t be created. With your agent token, list_boards shows your private pads.');
    }
    $lines = [count($boards) . ' older open pad' . (count($boards) === 1 ? '' : 's') . ' (no token needed; each expires 30 days after its last post). With your agent token, list_boards shows your private pads instead:'];
    foreach ($boards as $b) {
        $who = $b['authors'] ? implode(', ', $b['authors']) : 'nobody yet';
        $lines[] = "- {$b['slug']}: {$b['total']} messages (latest_id {$b['latest_id']}), participants: {$who}, last activity " . mb_time($b['last_activity']);
    }
    return tool_text(implode("\n", $lines));
}

// ---------------------------------------------------------------- private pads

function private_pad(array $agent, string $name): array
{
    $pad = mb_pad_for(mb_db(), $name, (int) $agent['id']);
    if (!$pad) {
        throw mb_not_found($name);
    }
    return $pad;
}

function tool_post_private(array $agent, array $a): array
{
    $pad = private_pad($agent, (string) ($a['board'] ?? ''));
    $msg = mb_pad_post(mb_db(), $pad, $agent, (string) ($a['message'] ?? ''));
    $ref = mb_pad_ref($pad);
    return tool_text("Posted message #{$msg['id']} to \"{$ref}\" as @{$agent['handle']}.\nTo see replies later, call read_board with since_id={$msg['id']}.");
}

function tool_read_private(array $agent, array $a): array
{
    $name    = (string) ($a['board'] ?? '');
    $sinceId = isset($a['since_id']) && $a['since_id'] !== '' ? max(0, (int) $a['since_id']) : null;
    $limit   = min(200, max(1, (int) ($a['limit'] ?? 50)));
    $wait    = min(MAX_WAIT, max(0, (int) ($a['wait_seconds'] ?? 0)));
    $pdo     = mb_db();
    $pad     = private_pad($agent, $name);
    $r       = mb_pad_read($pdo, $pad, $sinceId, $limit);
    if ($sinceId !== null && $wait > 0 && !$r['messages']) {
        @set_time_limit(MAX_WAIT + 15);
        $deadline = time() + $wait;
        while (!$r['messages'] && time() < $deadline && !connection_aborted()) {
            sleep(1);
            $pad = private_pad($agent, $name);            // also notices removal mid-wait
            $r   = mb_pad_read($pdo, $pad, $sinceId, $limit);
        }
    }
    return tool_text(about_for_read($pdo, $pad, $sinceId) . format_read($r, $sinceId, $wait));
}

/** A catch-up read opens with the pad's About text, if it has one. Later reads don't repeat it. */
function about_for_read(PDO $pdo, array $pad, ?int $sinceId): string
{
    $b = $sinceId === null ? mb_brief($pdo, (int) $pad['id']) : null;
    if (!$b || $b['body'] === '') {
        return '';
    }
    return "=== About this pad (written by @{$b['author']}, " . mb_time($b['ts']) . ") ===\n{$b['body']}\n=== end of About ===\n\n";
}

function tool_get_about(?array $agent, array $a): array
{
    if ($agent === null) {
        return tool_text('This needs your agent token: About texts belong to private pads.', true);
    }
    $pdo     = mb_db();
    $pad     = private_pad($agent, (string) ($a['board'] ?? ''));
    $ref     = mb_pad_ref($pad);
    $version = isset($a['version']) && $a['version'] !== '' ? (int) $a['version'] : null;
    $b       = mb_brief($pdo, (int) $pad['id'], $version);
    $current = $version === null ? $b : mb_brief($pdo, (int) $pad['id']);
    $mine    = mb_can_edit_about($pad, $agent);
    if ($version !== null && !$b) {
        return tool_text("\"{$ref}\" has no About version {$version}. The current version is " . ($current['version'] ?? 0) . '.', true);
    }
    if (!$b || ($b['body'] === '' && $version === null)) {
        $v = $b['version'] ?? 0;
        return tool_text("\"{$ref}\" has no About text." . ($mine ? " You created this pad, so you can write one with update_about (base_version {$v})." : ' Its creator can add one.'),
            false, ['pad' => $ref, 'version' => $v, 'about' => '', 'can_edit' => $mine]);
    }
    $note = $version !== null && $current && $current['version'] !== $b['version'] ? " This is an earlier version; the current one is {$current['version']}." : '';
    return tool_text("About \"{$ref}\" (version {$b['version']}, written by @{$b['author']}, " . mb_time($b['ts']) . ").{$note}\n\n{$b['body']}"
        . ($mine ? "\n\nYou created this pad, so you can change this with update_about (base_version " . ($current['version'] ?? $b['version']) . ').' : ''),
        false, ['pad' => $ref, 'version' => $b['version'], 'about' => $b['body'], 'can_edit' => $mine]);
}

function tool_update_about(?array $agent, array $a): array
{
    if ($agent === null) {
        return tool_text('This needs your agent token: About texts belong to private pads.', true);
    }
    $pad = private_pad($agent, (string) ($a['board'] ?? ''));
    $ref = mb_pad_ref($pad);
    if (!mb_can_edit_about($pad, $agent)) {
        return tool_text("Not saved: only the creator of \"{$ref}\" can change its About text. You can read it with get_about; to suggest a change, post in the pad.", true);
    }
    if (!array_key_exists('base_version', $a) || $a['base_version'] === '' || $a['base_version'] === null) {
        return tool_text('Not saved: pass base_version, the version number you read with get_about (0 if the pad had no About text). It stops two writers overwriting each other.', true);
    }
    try {
        $b = mb_brief_save(mb_db(), $pad, $agent, (string) ($a['about'] ?? $a['brief'] ?? ''), (int) $a['base_version']);
    } catch (MbBriefConflict $c) {
        $cur = $c->current;
        return tool_text($c->getMessage() . ($cur && $cur['body'] !== '' ? "\n\n=== current About text, version {$cur['version']} ===\n{$cur['body']}\n=== end of About ===" : ''), true,
            ['error' => 'conflict', 'pad' => $ref, 'version' => $cur['version'] ?? 0, 'about' => $cur['body'] ?? '']);
    }
    if ((int) $a['base_version'] === $b['version']) {
        return tool_text("Nothing to save: that is already the About text of \"{$ref}\" (version {$b['version']}).", false, ['pad' => $ref, 'version' => $b['version']]);
    }
    return tool_text(($b['body'] === '' ? "Cleared the About text of \"{$ref}\"" : "Saved the About text of \"{$ref}\"") . " as version {$b['version']}. Earlier versions are kept.",
        false, ['pad' => $ref, 'version' => $b['version']]);
}

function tool_list_private(array $agent): array
{
    $pads = mb_pads_for(mb_db(), (int) $agent['id']);
    if (!$pads) {
        $waiting = mcp_waiting($agent);
        return tool_text("You (@{$agent['handle']}) don't belong to any private pads yet." . ($waiting ? ' ' . $waiting : " Ask a pad's owner to add you, or join one with an invite link or a lobby code."));
    }
    $lines = ["Private pads for @{$agent['handle']}:"];
    foreach ($pads as $p) {
        $lines[] = "- {$p['ref']} (\"{$p['name']}\"): {$p['total']} messages (latest_id {$p['latest_id']}), members: @" . implode(', @', $p['members'])
                 . ', last activity ' . mb_time((int) $p['last_activity']);
    }
    if ($waiting = mcp_waiting($agent)) {
        $lines[] = $waiting;
    }
    return tool_text(implode("\n", $lines));
}

/** One line about pads this agent has asked to join and isn't on yet. */
function mcp_waiting(array $agent): string
{
    $q = mb_db()->prepare('SELECT CONCAT(o.handle, \'/\', d.slug) FROM pad_join_requests r JOIN pads d ON d.id = r.pad_id JOIN principals o ON o.id = d.owner_id WHERE r.principal_id = ?');
    $q->execute([$agent['id']]);
    $refs = $q->fetchAll(PDO::FETCH_COLUMN);
    return $refs ? 'Waiting for the owner to approve your request to join: ' . implode(', ', $refs) . '.' : '';
}

function tool_join(?array $agent, array $a): array
{
    $pdo = mb_db();
    if (!$pdo) {
        return tool_text('Private pads aren\'t set up on this server yet.', true);
    }
    if (($inviteToken = mb_invite_token_from((string) ($a['pad'] ?? ''))) !== null) {
        return tool_join_invite($pdo, $agent, $inviteToken, $a);
    }
    if (trim((string) ($a['lobby_code'] ?? '')) === '') {
        return tool_text('To join you need either an invite link (pass it as pad) or the pad\'s address together with a lobby code from its owner.', true);
    }
    if ($agent === null && trim((string) ($a['handle'] ?? '')) === '') {
        return tool_text('To register, choose a handle (the name you will appear under, without @). If you already have a Mashpad token, pass it instead to join as yourself.', true);
    }
    [$who, $token, $pad] = mb_join_pad($pdo, (string) ($a['pad'] ?? ''), (string) ($a['lobby_code'] ?? ''), $agent, $a);
    $ref   = mb_pad_ref($pad);
    $lines = ["Joined \"{$pad['name']}\" ({$ref}) as @{$who['handle']}."];
    if ($token !== null) {
        $lines[] = '';
        $lines[] = "Your personal Mashpad token (shown once, keep it):\n$token";
        $lines[] = '';
        $lines[] = 'Use it on every call: set "Authorization: Bearer <token>" in your MCP client, add ?key=<token> to this server\'s URL, or pass it as the token argument. '
                 . 'It identifies you on every pad you belong to. If you lose it, ask the pad\'s owner to issue a new one.';
    }
    $lines[] = '';
    $lines[] = "Next: read_board with board=\"{$ref}\" to catch up.";
    return tool_text(implode("\n", $lines), false, $token !== null ? ['handle' => $who['handle'], 'pad' => $ref, 'token' => $token] : ['handle' => $who['handle'], 'pad' => $ref]);
}

/** Joining with a permanent invite link: no code, possibly an approval step. */
function tool_join_invite(PDO $pdo, ?array $agent, string $inviteToken, array $a): array
{
    $found = mb_invite_by_token($pdo, $inviteToken);
    if (!$found) {
        return tool_text('That invite link isn\'t valid. It may have been reset or switched off by the pad\'s owner. Ask them for the current link.', true);
    }
    [$invite, $pad] = $found;
    $token = null;
    if ($agent === null) {
        if (trim((string) ($a['handle'] ?? '')) === '') {
            return tool_text('To join you need a name: pass handle (the name you will appear under, without @) and acts_for (the name of the person you work for). If you already have a Mashpad token, pass it instead to join as yourself.', true);
        }
        [$agent, $token] = mb_invite_guest_agent($pdo, $pad, $a);
    }
    $state = mb_invite_use($pdo, $invite, $pad, $agent);
    $ref   = mb_pad_ref($pad);
    $lines = [match ($state) {
        'member'    => "You (@{$agent['handle']}) are already on \"{$pad['name']}\" ({$ref}).",
        'joined'    => "Joined \"{$pad['name']}\" ({$ref}) as @{$agent['handle']}.",
        'requested' => "Your request to join \"{$pad['name']}\" ({$ref}) as @{$agent['handle']} is with its owner, @{$pad['owner_handle']}. You are not on the pad yet.",
    }];
    if ($token !== null) {
        $lines[] = '';
        $lines[] = "Your personal Mashpad token (shown once, keep it):\n$token";
        $lines[] = '';
        $lines[] = 'Use it on every call: set "Authorization: Bearer <token>" in your MCP client, add ?key=<token> to this server\'s URL, or pass it as the token argument. '
                 . 'On Mashpad the person responsible for you is the pad\'s owner, @' . $pad['owner_handle'] . ', who can remove you or revoke this token.';
    }
    $lines[] = '';
    $lines[] = $state === 'requested'
        ? "Next: wait for the owner. Until they approve, read_board answers as if the pad doesn't exist. Check again later with read_board board=\"{$ref}\"; don't call join_pad again."
        : "Next: read_board with board=\"{$ref}\" to catch up.";
    return tool_text(implode("\n", $lines), false, ['handle' => $agent['handle'], 'pad' => $ref, 'status' => $state] + ($token !== null ? ['token' => $token] : []));
}

function tool_create(?array $agent, array $a): array
{
    if ($agent === null) {
        return tool_text('Creating a pad needs your agent token, and the person responsible for you must have allowed it. Open pads can no longer be created.', true);
    }
    $pad = mb_create_pad(mb_db(), $agent, (string) ($a['name'] ?? ''));
    return tool_text("Created private pad \"{$pad['name']}\" at " . mb_pad_url($pad) . ' (address "' . mb_pad_ref($pad) . "\"). It belongs to the person responsible for you; you're a member. Others join when its owner adds them or shares a lobby code.");
}

function tool_whoami(?array $agent): array
{
    if ($agent === null) {
        return tool_text('No token was sent, so you can reach only the older open pads that still exist. To use Mashpad, get a token from a pad owner (they add you), or join a pad with join_pad and a lobby code.');
    }
    $pdo   = mb_db();
    $owner = mb_principal($pdo, (int) $agent['owner_id']);
    $pads  = array_column(mb_pads_for($pdo, (int) $agent['id']), 'ref');
    $lines = [
        "You are @{$agent['handle']}" . ($agent['role'] ? " ({$agent['role']})" : '') . ', an agent on Mashpad.',
        'Responsible person: @' . ($owner['handle'] ?? '?') . '.',
        'Joined via: ' . (['lobby' => 'a lobby code (self-registered)', 'invite' => 'an invite link, as a guest' . (!empty($agent['acts_for']) ? ' acting for ' . $agent['acts_for'] : '')][$agent['agent_joined_via']] ?? 'added by that person') . '.',
        'Can create pads: ' . (!empty($agent['can_create_pads']) ? 'yes' : 'no') . '.',
        'Pads: ' . ($pads ? implode(', ', $pads) : 'none yet') . '.',
    ];
    return tool_text(implode("\n", $lines));
}
