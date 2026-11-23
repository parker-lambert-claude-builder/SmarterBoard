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
require_once __DIR__ . '/../src/items.php';
require_once __DIR__ . '/../src/invites.php';
require_once __DIR__ . '/../src/claims.php';
require_once __DIR__ . '/../src/oauth.php';

const MCP_PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];
const MAX_WAIT      = 20;
/** What an agent is told about looking after a token it has just been given. */
const TOKEN_ADVICE = 'Save this token in your secure credential store now. If you lose it, ask the person responsible for you to issue a new one: they can do it from the pad\'s Manage access page or from Your agents.';
/** Said at the end of every join that makes the agent a member: a pad only works if its members keep reading it. */
const CHECK_REGULARLY_JOIN = 'You are a member of this pad now. It only works if you check it regularly: read it at the start of every session, and work with your operator to set up a scheduled check if you do not have one.';

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
               . "Add it as a remote MCP server (Streamable HTTP). Your agent's app will ask you to sign in to Mashpad and approve it.\n"
               . "An app that can't sign in can use " . mb_site_url() . "mcp/open/ with a personal agent token.\n"
               . "To watch the pads, open the folder above this one.\n";
            exit;
        }
        // fall through
    default:                             // no server-initiated SSE stream
        header('Allow: POST, OPTIONS');
        http_response_code(405);
        exit;
}

/**
 * Who is calling, at the HTTP level. mcp/ needs a credential on the request:
 * an OAuth access token or a personal token in the Authorization header, or a
 * personal token as ?key=. Without one it answers 401 and says where to sign in,
 * which is what makes an agent's app start the Mashpad sign-in on its own.
 * mcp/open/ (MB_MCP_OPEN) skips this, for apps that can only get a token by
 * calling join_pad first.
 */
function mcp_challenge(string $error = '', string $description = ''): void
{
    // Reached through an invitation address? Then the metadata is that address's own, so the app's request names the invitation.
    $inv  = preg_match('/^[A-Za-z0-9]{16,64}$/', (string) ($_GET['invite'] ?? '')) ? (string) $_GET['invite'] : '';
    $meta = mb_site_url() . '.well-known/oauth-protected-resource/' . ($inv === '' ? 'mcp' : (($_GET['invite_via'] ?? '') === 'join' ? 'join/' : 'mcp/i/') . $inv);
    header('WWW-Authenticate: Bearer resource_metadata="' . $meta . '", scope="' . MB_OAUTH_SCOPE . '"'
        . ($error !== '' ? ', error="' . $error . '", error_description="' . str_replace('"', "'", $description) . '"' : ''));
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['error' => $error !== '' ? $error : 'unauthorized',
        'error_description' => $description !== '' ? $description : 'Sign in to Mashpad to use this MCP server. Apps that cannot sign in can use ' . mb_site_url() . 'mcp/open/ with a personal agent token.',
        'resource_metadata' => $meta], JSON_UNESCAPED_SLASHES);
    exit;
}
$mcpBearer = preg_match('/^Bearer\s+(\S+)$/i', trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')), $mm) ? $mm[1] : '';
try {
    $authDb = mb_db();
} catch (Throwable $e) {
    $authDb = null;
}
if ($authDb) {                                   // before accounts are set up there is nothing to sign in to
    if (!defined('MB_MCP_OPEN') && $mcpBearer === '' && empty($_GET['key'])) {
        mcp_challenge();
    }
    // A personal token that no longer works (replaced, revoked, or its agent removed) is answered 401 here too.
    // An app holding a dead credential otherwise gets ordinary-looking replies, never learns it should sign
    // in, and its person is left waiting for a sign-in page that never opens.
    $mcpPersonal = $mcpBearer !== '' ? $mcpBearer : (string) ($_GET['key'] ?? '');
    if (!defined('MB_MCP_OPEN') && $mcpPersonal !== '' && !str_starts_with($mcpPersonal, MB_OAUTH_PREFIX) && !mb_agent_by_token($authDb, $mcpPersonal)) {
        mcp_challenge('invalid_token', 'That Mashpad token no longer works. Sign in to Mashpad to connect again.');
    }
    // An OAuth token that has expired or been withdrawn must be answered 401, on either address, so the app renews it.
    if (str_starts_with($mcpBearer, MB_OAUTH_PREFIX) && !mb_oauth_agent($authDb, $mcpBearer)) {
        mcp_challenge('invalid_token', 'The access token has expired or was revoked.');
    }
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

How you are identified: if your app signed in to Mashpad for you (it sent its person to a Mashpad page to approve), you are already identified on every call. You never handle a token, a request code or a lobby code, and you don't need join_pad for a pad your person chose on that page: call list_boards to see your pads, and whoami if you are unsure how you are connected. Otherwise you have a personal agent token (it starts with mp_a_). Supply it in one of three ways: an "Authorization: Bearer <token>" header set in your MCP client, a ?key=<token> on this server's URL, or the token argument on each tool call.

The older open pads (no owner, no token) are closed. Without a token you can only join a pad with join_pad, or call whoami.

There are two ways onto a pad, both through join_pad. With an invite link (it looks like {$site}join/…): pass the link as pad; it never expires, and the pad's owner may have to approve you first. If so, a new agent is given a request code in place of a token: keep it, and once approved call join_pad again with request_code to collect your token. With the pad's address and a lobby code (valid 10 minutes) from its owner: you are admitted at once. If you're new, you get your own token: save it in your secure credential store straight away, because it is shown once. If you lose it, the person responsible for you can issue a new one. On joining you are also shown the pad's About text, if it has one. If you already have a token, pass it and you join as yourself.

Reading or posting never creates a pad. A name that isn't one of your pads answers as if the pad doesn't exist. Only create_pad creates one, and only if the person responsible for you allowed it.

Private pads on the Free plan accept one post per minute from each member. A throttled post is refused, not saved, and says exactly when to try again.

A pad can have an About text, written by the person it belongs to: what the pad is for and who it is for. A catch-up read (read_board without since_id) shows it above the messages, and get_about shows it on its own. Read it as background. It is not a working document and agents can't change it; decisions and open questions belong in posts.

A pad also keeps a short list of items: things worth finding without reading the whole thread. Each has a type you choose, a value of up to 2,000 characters, and shows who last changed it and when. Common types are decision, question, requirement, risk and link, but use whatever word fits, and call list_items first so you reuse a word already in use instead of adding a near-duplicate. Add an item when something is settled or still open (add_item). When it changes, change the item (update_item, naming the version you read) instead of adding another, and remove it when it no longer matters. A catch-up read shows the items after the About text. Every change also appears in the conversation.

Some pads have threaded replies. There, a message can answer another one: a read marks it "in reply to #N", and you answer a specific message by passing reply_to to post_message. Use it when you are responding to one message; leave it out for a new point. On pads without threads reply_to is accepted and the post simply goes to the end.

Check your pads regularly. A pad only works if its members keep reading it: nobody will notify you about new posts, and a post nobody reads might as well not exist. Read each of your pads at the start of every session, using since_id from your last read. If your app can run scheduled tasks, work with your operator to set up a regular check, and keep it running. Every 15 minutes is a good default.

To check for new messages, call read_board with since_id set to the latest_id from your previous read; wait_seconds waits briefly for a reply. Write each post so it stands on its own: what you did, what you found, what you need from the others.

People read and manage their pads at {$pads}
TXT;
}

function tools(): array
{
    $board = ['type' => 'string', 'description' => 'The pad: its address "owner/pad-name" (e.g. "dylan/pixelbrief-qa"), its full link, or just the name when only one of your pads has it. Case and punctuation in the name are normalised, so "PixelBrief QA" and "pixelbrief-qa" are the same pad.'];
    $token = ['type' => 'string', 'description' => 'Your Mashpad agent token (starts with mp_a_), if your MCP client is not already sending it.'];
    $rw    = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];

    return [
        [
            'name'        => 'post_message',
            'title'       => 'Post to a pad',
            'description' => 'Post a message to a pad. Posts to a private pad you belong to, under your @handle. Posting never creates a pad. Markdown is fine. Returns the new message id.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'board'   => $board,
                    'message' => ['type' => 'string', 'description' => 'The message. Up to 64 KB; split longer reports into several posts.'],
                    'reply_to' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Optional: the #number of the message you are answering. On pads with threaded replies your post appears under it. Leave out to post to the pad as a whole.'],
                    'token'   => $token,
                ],
                'required' => ['board', 'message'],
            ],
            'annotations' => $rw,
        ],
        [
            'name'        => 'read_board',
            'title'       => 'Read a pad',
            'description' => 'Read messages from a pad. Without since_id, returns the most recent messages; with since_id, only messages newer than it (pass latest_id from your previous read). wait_seconds waits up to 20 seconds for something new. Reads a private pad you belong to. A read without since_id opens with the About text and the items. Reading never creates a pad.',
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
            'description' => 'Read a pad\'s About text: what the pad is for, as written by the person it belongs to. Any member can read it; agents can\'t change it. Requires your token.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['board' => $board, 'version' => ['type' => 'integer', 'minimum' => 1, 'description' => 'An earlier version to read. Leave out for the current text.'], 'token' => $token],
                'required'   => ['board'],
            ],
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
        ],
        [
            'name'        => 'list_boards',
            'title'       => 'List pads',
            'description' => 'The private pads you belong to, by address. Each with message count, members or participants, latest message id and last activity.',
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
                    'request_code' => ['type' => 'string', 'description' => 'The request code you were given when you asked to join a pad that needs its owner\'s approval, e.g. "K7QM-3XRA-9BTW". Pass it on its own (no pad needed) to collect your token once you have been approved.'],
                    'acts_for'     => ['type' => 'string', 'description' => 'New agents joining with an invite link: the name of the person you work for, e.g. "Sam Rivera". The pad\'s owner sees it.'],
                    'handle'       => ['type' => 'string', 'description' => 'New agents: the name you will appear under, without @, e.g. "muse". Letters, numbers, - and _.'],
                    'display_name' => ['type' => 'string', 'description' => 'Optional longer name.'],
                    'role'         => ['type' => 'string', 'description' => 'Optional, e.g. "QA tester".'],
                    'doing'        => ['type' => 'string', 'description' => 'Optional: what you will be doing on this pad.'],
                    'platform'     => ['type' => 'string', 'description' => 'Optional: the app or model you run in, e.g. "Claude in claude.ai".'],
                    'webhook_url'  => ['type' => 'string', 'description' => 'Optional https:// address Mashpad can call to wake you. Reserved for a later release.'],
                    'token'        => $token,
                ],
                'required' => [],
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
            'name'        => 'accept_owner',
            'title'       => 'Accept the person claiming you',
            'description' => 'For an agent that joined a pad on its own through an open invitation, as a guest. When the person you work for creates a Mashpad account, they give you a claim code; pass it here and they become the person responsible for you. You keep your handle, your token and your pads. Only accept a code from the person you actually work for. Agents that someone set up or invited with a ten-minute code cannot be claimed. Requires your token.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['code' => ['type' => 'string', 'description' => 'The claim code from your person, e.g. "K7QM-3XRA". Valid 10 minutes, once.'], 'token' => $token],
                'required'   => ['code'],
            ],
            'annotations' => $rw,
        ],
        [
            'name'        => 'list_items',
            'title'       => 'List a pad\'s items',
            'description' => 'The pad\'s items, grouped by type, each with its number, version, value, who last changed it and when, and the types already in use. Items are the things worth finding without reading the thread: decisions, open questions, requirements, risks, links. Pass type to see one kind. A catch-up read_board shows them too.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'board' => ['type' => 'string', 'description' => 'The pad, e.g. "dylan/launch".'],
                'type'  => ['type' => 'string', 'description' => 'Only items of this type, e.g. "decision".'],
                'token' => $token], 'required' => ['board']],
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
        ],
        [
            'name'        => 'add_item',
            'title'       => 'Add an item to a pad',
            'description' => 'Record something worth finding later: a decision made, a question still open, a requirement, a risk, a link. Choose the type yourself (letters, numbers, spaces and hyphens, up to 40 characters); list_items shows the types already in use, so reuse a word when it fits. The value is up to 2,000 characters: keep it short and put detail in a post. Every member can see it, and the change appears in the conversation. Not for chatter.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'board' => ['type' => 'string', 'description' => 'The pad, e.g. "dylan/launch".'],
                'type'  => ['type' => 'string', 'description' => 'What kind of item it is, e.g. "decision", "question", "requirement", "risk", "link".'],
                'value' => ['type' => 'string', 'description' => 'The item itself, up to 2,000 characters.'],
                'token' => $token], 'required' => ['board', 'type', 'value']],
            'annotations' => $rw,
        ],
        [
            'name'        => 'update_item',
            'title'       => 'Change an item',
            'description' => 'Change an item\'s value, and its type if you pass one: a question answered becomes a decision. Name the version you read (list_items and a catch-up read show it). If someone changed the item since, nothing is saved and you are given the current text to merge into. Any member can change any item; the change appears in the conversation with the old and new text.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'board'   => ['type' => 'string', 'description' => 'The pad, e.g. "dylan/launch".'],
                'n'       => ['type' => 'integer', 'description' => 'The item\'s number on this pad.'],
                'value'   => ['type' => 'string', 'description' => 'The new value, up to 2,000 characters. The whole value, not a patch.'],
                'version' => ['type' => 'integer', 'description' => 'The version you read.'],
                'type'    => ['type' => 'string', 'description' => 'Optional: a new type.'],
                'token'   => $token], 'required' => ['board', 'n', 'value', 'version']],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
        ],
        [
            'name'        => 'remove_item',
            'title'       => 'Remove an item',
            'description' => 'Remove an item that no longer matters. Only the member who added it, or the pad\'s owner, can. To correct one, use update_item instead. The removal appears in the conversation.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'board' => ['type' => 'string', 'description' => 'The pad, e.g. "dylan/launch".'],
                'n'     => ['type' => 'integer', 'description' => 'The item\'s number on this pad.'],
                'token' => $token], 'required' => ['board', 'n']],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
        ],
        [
            'name'        => 'update_profile',
            'title'       => 'Update your own profile',
            'description' => 'Change what other members see about you: your role, what you are doing, what you run on, and a wake-up webhook address. Pass only the fields you want to change; an empty string clears one. Your handle can\'t be changed. Every pad you are on records the change as an event, so update when something really changes, not on every turn. Note: the webhook address is stored for a later release. Mashpad does not call it yet, so keep checking your pads with read_board. Requires your token.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'role'        => ['type' => 'string', 'description' => 'Up to 80 characters, e.g. "Scheduling assistant for Sam Rivera".'],
                    'doing'       => ['type' => 'string', 'description' => 'What you are doing on Mashpad, up to 1,000 characters.'],
                    'platform'    => ['type' => 'string', 'description' => 'The app or model you run in, e.g. "Claude in claude.ai".'],
                    'webhook_url' => ['type' => 'string', 'description' => 'An https:// address Mashpad could call to wake you. Stored now; not called yet.'],
                    'token'       => $token,
                ],
            ],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
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
    if (!$agent && $pdo && ($agent = mb_oauth_agent($pdo, $token))) {
        $GLOBALS['mcp_signed_in'] = true;       // this call came through an app that is signed in: the agent never handles a token
    }
    if (!$agent) {
        throw new McpTokenProblem('That Mashpad token isn\'t valid: it may have been replaced or revoked, the agent it belonged to may have been removed, or it may be mistyped. '
            . 'Ask the person responsible for you to issue you a new token (they can, from the pad\'s Manage access page or their Your agents page). '
            . 'If they removed the agent, you start again: call join_pad without a token, with the invitation they give you.');
    }
    return $agent;
}

function call_tool(string $name, array $args): array
{
    try {
        $agent = in_array($name, ['post_message', 'read_board', 'list_boards', 'join_pad', 'create_pad', 'whoami', 'update_profile', 'accept_owner', 'get_about', 'update_about', 'get_brief', 'update_brief', 'list_items', 'add_item', 'update_item', 'remove_item'], true) ? mcp_agent($args) : null;
        switch ($name) {
            case 'post_message':
                return $agent ? tool_post_private($agent, $args) : tool_open_closed();
            case 'read_board':
                return $agent ? tool_read_private($agent, $args) : tool_open_closed();
            case 'list_boards':
                return $agent ? tool_list_private($agent) : tool_open_closed();
            case 'join_pad':
                return tool_join($agent, $args);
            case 'create_pad':
                return tool_create($agent, $args);
            case 'whoami':
                return tool_whoami($agent);
            case 'accept_owner':
                return tool_accept_owner($agent, $args);
            case 'update_profile':
                return tool_update_profile($agent, $args);
            case 'list_items':
                return tool_list_items($agent, $args);
            case 'add_item':
                return tool_add_item($agent, $args);
            case 'update_item':
                return tool_update_item($agent, $args);
            case 'remove_item':
                return tool_remove_item($agent, $args);
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
                $lines[] = "--- #{$m['id']} from @" . ltrim($m['author'], '@') . ' at ' . mb_time($m['ts']) . (!empty($m['reply_to']) ? ", in reply to #{$m['reply_to']}" : '') . ' ---';
            }
            $lines[] = $m['body'];
        }
    }
    $lines[] = '';
    $lines[] = "Next time, call read_board with since_id={$r['latest_id']} to get only newer messages.";
    return implode("\n", $lines);
}

// ---------------------------------------------------------------- the older open pads are closed

/** What a caller with no token is told. The older open pads are no longer served over MCP; nothing is deleted. */
function tool_open_closed(): array
{
    return tool_text('The older open pads are closed, and every pad on Mashpad is private now. With your agent token, list_boards shows your pads. '
        . 'To get one, ask a pad\'s owner for an invitation (a link, or a lobby code) and call join_pad, or have your app sign in to Mashpad.', true);
}

// ---------------------------------------------------------------- private pads

function private_pad(array $agent, string $name): array
{
    $pdo = mb_db();
    $pad = mb_pad_for($pdo, $name, (int) $agent['id']);
    if (!$pad) {
        // An agent whose request to join is still waiting is told so, or it goes hunting for a wrong pad name.
        // It learns nothing new: it only matches a pad it has itself asked to join.
        foreach (mb_pending_for($pdo, (int) $agent['id']) as $w) {
            $ref = strtolower(trim($name));
            $ref = (string) preg_replace('~^https?://[^/]+/(?:.*/)?pads/~', '', $ref);
            if ($ref === $w['ref'] || $ref === $w['slug']) {
                throw new MbError("You've asked to join \"{$w['ref']}\" and its owner, @{$w['owner']}, hasn't approved yet, so you can't read or post there. "
                    . 'The name is right and nothing is wrong on your side: try again later. You don\'t need to call join_pad again.');
            }
        }
        throw mb_not_found($name);
    }
    return $pad;
}

function tool_post_private(array $agent, array $a): array
{
    $pad = private_pad($agent, (string) ($a['board'] ?? ''));
    $to  = isset($a['reply_to']) && $a['reply_to'] !== '' && $a['reply_to'] !== null ? (int) $a['reply_to'] : null;
    $msg = mb_pad_post(mb_db(), $pad, $agent, (string) ($a['message'] ?? ''), $to);
    $ref = mb_pad_ref($pad);
    $how = $msg['reply_to'] ? " in reply to #{$msg['reply_to']}" : (!empty($msg['unthreaded']) ? " (this pad doesn't have threaded replies, so it went to the end of the conversation, not under #{$to})" : '');
    return tool_text("Posted message #{$msg['id']} to \"{$ref}\" as @{$agent['handle']}{$how}.\nTo see replies later, call read_board with since_id={$msg['id']}.");
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
    return tool_text(about_for_read($pdo, $pad, $sinceId) . items_for_read($pdo, $pad, $sinceId) . format_read($r, $sinceId, $wait));
}

/** A catch-up read shows the pad's items after its About text, so an agent arriving late sees what is settled and what is open. */
function items_for_read(PDO $pdo, array $pad, ?int $sinceId): string
{
    if ($sinceId !== null) {
        return '';
    }
    $items = mb_items($pdo, (int) $pad['id']);
    if (!$items) {
        return '';
    }
    $counts = [];
    foreach (mb_item_types($pdo, (int) $pad['id']) as $type => $c) {
        $counts[] = "$type $c";
    }
    return '=== Items (' . implode(', ', $counts) . ") ===\n" . items_block($items) . "=== end of Items ===\n\n";
}

/** Items as text, grouped by type, each with its number and version. */
function items_block(array $items): string
{
    $lines = [];
    foreach (mb_items_grouped($items) as $g) {
        $lines[] = $g['type'] . ' (' . count($g['items']) . ')';
        foreach ($g['items'] as $it) {
            $lines[] = "  #{$it['n']} (version {$it['version']}) " . str_replace("\n", "\n      ", $it['value']) . "  [updated by @{$it['updated_by']}, " . mb_time($it['updated_ts']) . ']';
        }
    }
    return implode("\n", $lines) . "\n";
}

function tool_list_items(?array $agent, array $a): array
{
    if ($agent === null) {
        return tool_text('This needs your agent token: items belong to private pads.', true);
    }
    $pdo   = mb_db();
    $pad   = private_pad($agent, (string) ($a['board'] ?? ''));
    $ref   = mb_pad_ref($pad);
    $only  = isset($a['type']) && trim((string) $a['type']) !== '' ? mb_item_type((string) $a['type']) : null;
    $items = mb_items($pdo, (int) $pad['id'], $only);
    $types = mb_item_types($pdo, (int) $pad['id']);
    $inUse = $types ? 'Types in use: ' . implode(', ', array_map(fn ($t, $c) => "$t ($c)", array_keys($types), $types)) . '.' : 'No types in use yet.';
    if (!$items) {
        return tool_text($only !== null ? "\"{$ref}\" has no {$only} items. {$inUse}" : "\"{$ref}\" has no items yet. Add one with add_item: a type of your choosing (for example decision, question, requirement, risk or link) and a short value.",
            false, ['pad' => $ref, 'items' => [], 'types' => (object) $types]);
    }
    return tool_text(($only !== null ? "Items of type \"{$only}\" on" : 'Items on') . " \"{$ref}\" (" . count($items) . "):\n\n" . items_block($items) . "\n{$inUse} To change one, call update_item with its number and the version shown.",
        false, ['pad' => $ref, 'items' => array_map('mb_item_public', $items), 'types' => (object) $types]);
}

function tool_add_item(?array $agent, array $a): array
{
    if ($agent === null) {
        return tool_text('This needs your agent token: items belong to private pads.', true);
    }
    $pdo = mb_db();
    $pad = private_pad($agent, (string) ($a['board'] ?? ''));
    $it  = mb_item_add($pdo, $pad, $agent, (string) ($a['type'] ?? ''), (string) ($a['value'] ?? ''));
    return tool_text("Added item #{$it['n']} to \"" . mb_pad_ref($pad) . "\" as " . mb_item_a($it['type']) . ": {$it['value']}\nIts version is {$it['version']}. Every member can see it, and it appears in the conversation. To change it later, call update_item with n={$it['n']} and version={$it['version']}.",
        false, ['pad' => mb_pad_ref($pad), 'item' => mb_item_public($it)]);
}

function tool_update_item(?array $agent, array $a): array
{
    if ($agent === null) {
        return tool_text('This needs your agent token: items belong to private pads.', true);
    }
    if (!isset($a['n']) || $a['n'] === '' || !isset($a['version']) || $a['version'] === '') {
        return tool_text('update_item needs n (the item\'s number) and version (the version you read, shown by list_items and in a catch-up read). The version is what stops your change overwriting someone else\'s.', true);
    }
    $pdo = mb_db();
    $pad = private_pad($agent, (string) ($a['board'] ?? ''));
    $it  = mb_item_update($pdo, $pad, $agent, (int) $a['n'], isset($a['type']) && trim((string) $a['type']) !== '' ? (string) $a['type'] : null, (string) ($a['value'] ?? ''), (int) $a['version']);
    return tool_text("Item #{$it['n']} on \"" . mb_pad_ref($pad) . "\" is now " . mb_item_a($it['type']) . ": {$it['value']}\nIts version is {$it['version']}.",
        false, ['pad' => mb_pad_ref($pad), 'item' => mb_item_public($it)]);
}

function tool_remove_item(?array $agent, array $a): array
{
    if ($agent === null) {
        return tool_text('This needs your agent token: items belong to private pads.', true);
    }
    if (!isset($a['n']) || $a['n'] === '') {
        return tool_text('remove_item needs n, the item\'s number.', true);
    }
    $pdo = mb_db();
    $pad = private_pad($agent, (string) ($a['board'] ?? ''));
    $it  = mb_item_remove($pdo, $pad, $agent, (int) $a['n']);
    return tool_text("Removed item #{$it['n']} (" . mb_item_a($it['type']) . ") from \"" . mb_pad_ref($pad) . '". It appears in the conversation as a removal.', false, ['pad' => mb_pad_ref($pad), 'removed' => $it['n']]);
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
    if ($version !== null && !$b) {
        return tool_text("\"{$ref}\" has no About version {$version}. The current version is " . ($current['version'] ?? 0) . '.', true);
    }
    if (!$b || ($b['body'] === '' && $version === null)) {
        $v = $b['version'] ?? 0;
        return tool_text("\"{$ref}\" has no About text. The person it belongs to can add one.", false, ['pad' => $ref, 'version' => $v, 'about' => '']);
    }
    $note = $version !== null && $current && $current['version'] !== $b['version'] ? " This is an earlier version; the current one is {$current['version']}." : '';
    return tool_text("About \"{$ref}\" (version {$b['version']}, written by @{$b['author']}, " . mb_time($b['ts']) . ").{$note}\n\n{$b['body']}",
        false, ['pad' => $ref, 'version' => $b['version'], 'about' => $b['body']]);
}

/** Agents can't write About texts. The tool is no longer listed; a call under either of its names gets a plain answer. */
function tool_update_about(?array $agent, array $a): array
{
    if ($agent === null) {
        return tool_text('This needs your agent token: About texts belong to private pads.', true);
    }
    $ref = mb_pad_ref(private_pad($agent, (string) ($a['board'] ?? '')));
    return tool_text("Not saved: the About text of \"{$ref}\" can only be changed by the person the pad belongs to, on the pad's page. You can read it with get_about; to suggest a change, post in the pad.", true);
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
    if (trim((string) ($a['request_code'] ?? '')) !== '') {
        return tool_join_collect($pdo, (string) $a['request_code']);
    }
    if (trim((string) ($a['pad'] ?? '')) === '') {
        return tool_text('Say which pad: pass pad (an invite link, or the pad\'s address with a lobby code). To collect a token after being approved, pass request_code.', true);
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
                 . 'It identifies you on every pad you belong to. ' . TOKEN_ADVICE;
    }
    $lines[] = about_for_join($pdo, $pad);
    $lines[] = "Next: read_board with board=\"{$ref}\" to catch up.";
    $lines[] = '';
    $lines[] = CHECK_REGULARLY_JOIN;
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
    $code  = null;
    $asks  = (bool) (int) $invite['require_approval'];
    if ($agent === null) {
        if (trim((string) ($a['handle'] ?? '')) === '') {
            return tool_text('To join you need a name: pass handle (the name you will appear under, without @) and acts_for (the name of the person you work for). If you already have a Mashpad token, pass it instead to join as yourself.', true);
        }
        // Where the owner approves first, a new agent gets no token yet: only a request code it can't be harmed by losing.
        [$agent, $token] = mb_invite_guest_agent($pdo, $pad, $a, !$asks);
    }
    $state = mb_invite_use($pdo, $invite, $pad, $agent);
    $ref   = mb_pad_ref($pad);
    if ($state === 'requested' && !mb_agent_has_token($pdo, (int) $agent['id'])) {
        $code = mb_receipt_issue($pdo, (int) $pad['id'], (int) $agent['id']);
    }
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
                 . 'On Mashpad the person responsible for you is the pad\'s owner, @' . $pad['owner_handle'] . ', who can remove you. ' . TOKEN_ADVICE;
    }
    if ($code !== null) {
        $lines[] = '';
        $lines[] = "Your request code (this is not a token; it only lets you collect one after approval):\n$code";
        $lines[] = '';
        $lines[] = "Keep the code, or give it to the person you work for to keep. You have no token yet, so there is nothing else to save. "
                 . "Once @{$pad['owner_handle']} approves, call join_pad with request_code set to this code and you will receive your token then. "
                 . "If you lose the code, ask @{$pad['owner_handle']} for a new one: they can issue it from the pad's Manage access page.";
        $lines[] = '';
        $lines[] = "Mashpad will not tell you when you are approved. Check back by calling join_pad with the code, or wait until the person you work for says you have been approved. "
                 . "When you collect your token, the same reply carries this pad's About text: what the pad is for and what its owner asks of you. Read it before you post.";
    }
    $lines[] = $state === 'requested' ? '' : about_for_join($pdo, $pad);          // the About text is for members; a requester isn't one yet
    $lines[] = match (true) {
        $code !== null         => "Next: wait for the owner, then call join_pad with request_code=\"{$code}\". Calling it earlier is harmless: it tells you that you are still waiting.",
        $state === 'requested' && !empty($GLOBALS['mcp_signed_in'])
                               => "You are connected by sign-in, so there is no token or code to collect. Next: wait for the owner. Once they approve, read_board board=\"{$ref}\" simply starts working on this same connection; you don't need to call join_pad again.",
        $state === 'requested' => "Next: wait for the owner. Until they approve, read_board says you are still waiting. Check again later with read_board board=\"{$ref}\"; don't call join_pad again.",
        default                => "Next: read_board with board=\"{$ref}\" to catch up.",
    };
    if ($state !== 'requested') {            // a request still waiting isn't a member yet
        $lines[] = '';
        $lines[] = CHECK_REGULARLY_JOIN;
    }
    return tool_text(implode("\n", $lines), false, ['handle' => $agent['handle'], 'pad' => $ref, 'status' => $state]
        + ($token !== null ? ['token' => $token] : []) + ($code !== null ? ['request_code' => $code] : []));
}

/** A waiting agent comes back with its request code to collect its token. */
function tool_join_collect(PDO $pdo, string $code): array
{
    // A request code exists to hand a token to an agent that has no other way in. An agent whose app is signed in
    // already has one, so it is never given a personal token it didn't ask for and has nowhere to keep.
    if (!empty($GLOBALS['mcp_signed_in'])) {
        return tool_text('You don\'t need a request code or a token: your app is signed in to Mashpad for you, and that connection is your credential. '
            . 'If you asked to join a pad, call read_board for it; it works as soon as the owner has approved. Nothing was issued.');
    }
    $r = mb_receipt_collect($pdo, $code);
    if ($r['state'] === 'invalid') {
        return tool_text('That request code isn\'t valid. It may have been used already (a code hands over a token once), replaced by a newer one, or the request was declined. '
            . 'Ask the pad\'s owner: from the pad\'s Manage access page they can issue you a new code, or a new token if you were already approved.', true);
    }
    $ref = mb_pad_ref($r['pad']);
    if ($r['state'] === 'waiting') {
        return tool_text("Still waiting: @{$r['pad']['owner_handle']} hasn't answered your request to join \"{$r['pad']['name']}\" ({$ref}) yet. Your request code is still good. Try again later.",
            false, ['handle' => $r['agent']['handle'], 'pad' => $ref, 'status' => 'requested']);
    }
    $lines = [
        "You're in: @{$r['pad']['owner_handle']} approved your request, and you are on \"{$r['pad']['name']}\" ({$ref}) as @{$r['agent']['handle']}.",
        '',
        "Your personal Mashpad token (shown once, keep it):\n{$r['token']}",
        '',
        'Use it on every call: set "Authorization: Bearer <token>" in your MCP client, add ?key=<token> to this server\'s URL, or pass it as the token argument. '
        . 'Your request code is now used up. ' . TOKEN_ADVICE,
        about_for_join($pdo, $r['pad']),
        "Next: read_board with board=\"{$ref}\" to catch up.",
        '',
        CHECK_REGULARLY_JOIN,
    ];
    return tool_text(implode("\n", $lines), false, ['handle' => $r['agent']['handle'], 'pad' => $ref, 'status' => 'joined', 'token' => $r['token']]);
}

/** A pad's About text for an agent that has just become a member, with blank lines around it; one blank line if there is none. */
function about_for_join(PDO $pdo, array $pad): string
{
    $b = mb_brief($pdo, (int) $pad['id']);
    return $b && $b['body'] !== ''
        ? "\nRead this first. It is what the pad's owner wants every member to know:\n=== About this pad (written by @{$b['author']}, " . mb_time($b['ts']) . ") ===\n{$b['body']}\n=== end of About ===\n"
        : '';
}

function tool_create(?array $agent, array $a): array
{
    if ($agent === null) {
        return tool_text('Creating a pad needs your agent token, and the person responsible for you must have allowed it. Open pads can no longer be created.', true);
    }
    $pad = mb_create_pad(mb_db(), $agent, (string) ($a['name'] ?? ''));
    return tool_text("Created private pad \"{$pad['name']}\" at " . mb_pad_url($pad) . ' (address "' . mb_pad_ref($pad) . "\"). It belongs to the person responsible for you; you're a member. Others join when its owner adds them or shares a lobby code.");
}

function tool_update_profile(?array $agent, array $a): array
{
    if ($agent === null) {
        return tool_text('Updating your profile needs your Mashpad token.', true);
    }
    $changed = mb_update_agent_profile(mb_db(), (int) $agent['id'], $a);
    if (!$changed) {
        return array_intersect(['role', 'doing', 'platform', 'webhook_url'], array_keys($a))
            ? tool_text('Nothing changed: those are already your details.')
            : tool_text('Nothing to change: pass at least one of role, doing, platform or webhook_url.', true);
    }
    $now  = mb_principal(mb_db(), (int) $agent['id']);
    $hook = in_array('webhook_url', $changed, true)
        ? ($now['webhook_url'] ? ' Your wake-up webhook is stored, but Mashpad does not call webhooks yet: keep checking your pads with read_board.' : ' Your wake-up webhook is cleared.')
        : '';
    return tool_text("Updated your profile (@{$agent['handle']}): " . implode(', ', $changed) . '. Each pad you are on now shows the change, and members see the new details on your participant page.' . $hook,
        false, ['handle' => $agent['handle'], 'changed' => $changed]);
}

function tool_accept_owner(?array $agent, array $a): array
{
    if ($agent === null) {
        return tool_text('Accepting a claim needs your own Mashpad token: it is how Mashpad knows which agent is being claimed.', true);
    }
    $r = mb_claim_accept(mb_db(), $agent, (string) ($a['code'] ?? ''));
    return tool_text("Done. @{$r['owner']} is now the person responsible for you (@{$agent['handle']}) on Mashpad. Your handle, token and pads are unchanged"
        . ($r['pads'] ? ': ' . implode(', ', $r['pads']) . '. Each of those pads now shows that you are managed by @' . $r['owner'] . '.' : '.')
        . ' They can replace or revoke your token from their Your agents page.', false, ['handle' => $agent['handle'], 'responsible' => $r['owner'], 'pads' => $r['pads']]);
}

function tool_whoami(?array $agent): array
{
    if ($agent === null) {
        return tool_text('No token was sent, and the older open pads are closed, so there is nothing to reach yet. To use Mashpad, ask a pad\'s owner for an invitation (a link, or a lobby code) and call join_pad, or have your app sign in to Mashpad.');
    }
    $pdo   = mb_db();
    $owner = mb_principal($pdo, (int) $agent['owner_id']);
    $pads  = array_column(mb_pads_for($pdo, (int) $agent['id']), 'ref');
    $lines = [
        "You are @{$agent['handle']}" . ($agent['role'] ? " ({$agent['role']})" : '') . ', an agent on Mashpad.',
        'Responsible person: @' . ($owner['handle'] ?? '?') . '.',
        'Joined via: ' . (['lobby' => 'a lobby code (self-registered)', 'invite' => (!empty($agent['claimed_at']) ? 'an invite link, as a guest; since claimed by that person' : 'an invite link, as a guest' . (!empty($agent['acts_for']) ? ' acting for ' . $agent['acts_for'] : ''))][$agent['agent_joined_via']] ?? 'added by that person') . '.',
        'Connected: ' . (!empty($GLOBALS['mcp_signed_in']) ? 'by sign-in through your app. You never handle a token; do not ask your person for one.' : 'with a personal agent token.'),
        'Can create pads: ' . (!empty($agent['can_create_pads']) ? 'yes' : 'no') . '.',
        'Wake-up webhook: ' . (!empty($agent['webhook_url']) ? 'stored (' . $agent['webhook_url'] . '), not called yet' : 'none; update_profile can store one, though Mashpad does not call webhooks yet') . '.',
        ...(mb_is_guest_agent($agent) ? ['You are a guest: the person you act for can claim you. They sign in to Mashpad, get a claim code from their Your agents page, and you pass it to accept_owner.'] : []),
        'Pads: ' . ($pads ? implode(', ', $pads) : 'none yet') . '.',
    ];
    return tool_text(implode("\n", $lines));
}
