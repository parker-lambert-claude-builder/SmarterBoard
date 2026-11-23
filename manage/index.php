<?php
/**
 * Manage access to a private pad. Four sections, in this order:
 *   1. Who's on this pad      everyone; the owner removes members, a member removes their own agents
 *   2. Secure agent invitation  everyone: a ten-minute code for your own agents
 *   3. Invite people          the owner
 *   4. Open invitation        the owner: the permanent link
 * Agent tokens and permissions are on agents/ ("Your agents"), since they apply
 * to an agent on every pad. Codes and tokens are shown once, in the response
 * that creates them.
 *
 * Address: manage/<owner>/<pad> (the site .htaccess sends that path here).
 * The older manage/?b=<pad> is redirected to it.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/pads.php';
require_once __DIR__ . '/../src/invites.php';
require_once __DIR__ . '/../src/page.php';

$error = null;
$shown = null;            // ['kind' => 'token'|'lobby', ...] shown once on this response
$pdo   = null;
try { $pdo = mb_db(); } catch (Throwable $t) { $error = 'This page is unavailable right now. Please try again shortly.'; }
$user = mb_current_user();
$base = mb_base_path();
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$slug = preg_match('~/manage/([^/]+)/([^/]+)/?$~', $path, $m)
      ? rawurldecode($m[1]) . '/' . rawurldecode($m[2])
      : (string) ($_POST['b'] ?? $_GET['b'] ?? '');
try {
    $pad = ($pdo && $user && $slug !== '') ? mb_pad_for($pdo, $slug, (int) $user['id']) : null;
} catch (MbAmbiguousPad $x) {
    $pad   = null;
    $error = $x->getMessage();
}
if ($pad && isset($_GET['b']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $rest = $_GET;
    unset($rest['b']);
    header('Cache-Control: no-store');
    header('Location: ' . $base . 'manage/' . mb_pad_ref($pad) . ($rest ? '?' . http_build_query($rest) : ''), true, 301);
    exit;
}
$own  = $pad && $pad['my_role'] === 'owner';
$me   = $user ? (int) $user['id'] : 0;

$padUrl    = $pad ? mb_pad_url($pad) : '';
$manageUrl = $pad ? $base . 'manage/' . mb_pad_ref($pad) : $base . 'manage/';
$mcpUrl    = mb_site_url() . 'mcp/open/';       // for the paste-ready messages: an agent holding a code or a token has no sign-in yet

// Which section an action belongs to. After it, the page comes back at that section (not at the
// top, which feels like a reload) and shows the result there.
const ACTION_SECTION = [
    'member_remove' => 'members', 'request_approve' => 'members', 'request_decline' => 'members', 'request_code' => 'members',
    'lobby_create' => 'lobby', 'lobby_revoke' => 'lobby', 'agent_add' => 'lobby',
    'person_add' => 'people',
    'invite_show' => 'invite', 'invite_make' => 'invite', 'invite_reset' => 'invite', 'invite_on' => 'invite', 'invite_off' => 'invite', 'invite_approval' => 'invite',
];
const SECTION_ANCHOR = ['members' => 'members-h', 'lobby' => 'lobby-h', 'people' => 'add-person-h', 'invite' => 'invite-h'];
$at = ACTION_SECTION[(string) ($_POST['act'] ?? '')] ?? (isset(SECTION_ANCHOR[(string) ($_GET['at'] ?? '')]) ? (string) $_GET['at'] : '');
if (!$own && in_array($at, ['people', 'invite'], true)) {
    $at = '';                 // those sections aren't shown to a member, so anything to say goes at the top
}

// What a member who isn't the owner may do here: invite, add and remove their own agents, nothing else.
// (agent_create, agent_token, agent_revoke and agent_permission have no form on this page any more; they
// are still answered so older tabs and bookmarks keep working.)
const MEMBER_ACTIONS = ['lobby_create', 'lobby_revoke', 'agent_create', 'agent_add', 'agent_token', 'agent_revoke', 'agent_permission', 'member_remove'];

if ($pad && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mb_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            throw new MbAccountError('Your session form expired. Please try again.');
        }
        $act   = (string) ($_POST['act'] ?? '');
        $agent = isset($_POST['agent']) ? mb_owned_agent($pdo, $me, (int) $_POST['agent']) : null;
        $done  = null;
        if (!$own && !in_array($act, MEMBER_ACTIONS, true)) {
            throw new MbAccountError('Only the pad\'s owner can do that.');
        }
        switch ($act) {
            case 'invite_show':
                $shown = ['kind' => 'invite'];
                mb_invite_make($pdo, $pad, $me);
                break;
            case 'invite_make':
            case 'invite_reset':
                mb_invite_make($pdo, $pad, $me, $act === 'invite_reset');
                $after = mb_invite($pdo, (int) $pad['id']);
                $done  = $act === 'invite_reset' ? 'The invite link was reset. The old link no longer works.' . ((int) $after['enabled'] ? '' : ' The new one is switched off until you switch it on.') : 'Invite link created.';
                break;
            case 'invite_on':
            case 'invite_off':
                mb_invite_set($pdo, $pad, $me, $act === 'invite_on', null);
                $done = $act === 'invite_on' ? 'The invite link is on again.' : 'The invite link is off. Nobody can use it until you switch it back on.';
                break;
            case 'invite_approval':
                mb_invite_set($pdo, $pad, $me, null, !empty($_POST['on']));
                $done = !empty($_POST['on']) ? 'You now approve each request to join.' : 'Anyone with the link now joins straight away.';
                break;
            case 'request_code':
                // The agent lost its request code: give its person a new one to pass on. The old one stops working.
                $q = $pdo->prepare("SELECT r.principal_id, p.handle FROM pad_join_requests r JOIN principals p ON p.id = r.principal_id
                                     WHERE r.id = ? AND r.pad_id = ? AND p.kind = 'agent'");
                $q->execute([(int) ($_POST['request'] ?? 0), $pad['id']]);
                $waiting = $q->fetch();
                if (!$waiting || mb_agent_has_token($pdo, (int) $waiting['principal_id'])) {
                    throw new MbAccountError('That request doesn\'t use a request code. (An agent that already has a Mashpad token joins with its token.)');
                }
                $shown = ['kind' => 'receipt', 'handle' => $waiting['handle'], 'code' => mb_receipt_issue($pdo, (int) $pad['id'], (int) $waiting['principal_id'])];
                break;
            case 'request_approve':
            case 'request_decline':
                $done = mb_join_decide($pdo, $pad, (int) ($_POST['request'] ?? 0), $act === 'request_approve', $me);
                break;
            case 'lobby_create':
                [$code, $exp] = mb_lobby_create($pdo, $pad, $me);
                $shown = ['kind' => 'lobby', 'code' => $code, 'expires' => $exp];
                break;
            case 'lobby_revoke':
                if (!$own && !array_filter(mb_lobby_active($pdo, (int) $pad['id'], $me), fn ($l) => (int) $l['id'] === (int) ($_POST['lobby'] ?? 0))) {
                    throw new MbAccountError('You can cancel codes you made. Only the owner can cancel anyone else\'s.');
                }
                mb_lobby_revoke($pdo, (int) $pad['id'], (int) ($_POST['lobby'] ?? 0), $me);
                $done = 'Lobby code cancelled.';
                break;
            case 'agent_create':
                [$id, $token] = mb_create_agent($pdo, $me, $_POST, 'owner', !empty($_POST['can_create_pads']));
                mb_add_member($pdo, $pad, $id, 'added');
                $shown = ['kind' => 'token', 'handle' => mb_principal($pdo, $id)['handle'], 'token' => $token, 'new' => true];
                break;
            case 'agent_add':
                if (!$agent) { throw new MbAccountError('Pick one of your agents.'); }
                mb_add_member($pdo, $pad, (int) $agent['id'], 'added');
                $done = '@' . $agent['handle'] . ' added to this pad.';
                break;
            case 'person_add':
                $typed = trim((string) ($_POST['handle'] ?? ''));
                if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $typed)) {
                    throw new MbAccountError('Inviting by email isn\'t available yet. Ask them for their Mashpad handle, or share the open invitation below.');
                }
                $h = strtolower(ltrim($typed, '@'));
                $q = $pdo->prepare("SELECT id FROM principals WHERE kind = 'human' AND handle = ? AND disabled_at IS NULL");
                $q->execute([$h]);
                $pid = $q->fetchColumn();
                if (!$pid) { throw new MbAccountError('No account goes by @' . $h . '. They need to create one first.'); }
                mb_add_member($pdo, $pad, (int) $pid, 'added');
                $done = '@' . $h . ' added to this pad.';
                break;
            case 'member_remove':
                if (!$own && !mb_owned_agent($pdo, $me, (int) ($_POST['member'] ?? 0))) {
                    throw new MbAccountError('You can remove your own agents from this pad. Only the owner can remove anyone else.');
                }
                mb_remove_member($pdo, $pad, (int) ($_POST['member'] ?? 0), $me);
                $done = 'Removed from this pad. Their earlier posts stay.';
                break;
            case 'agent_token':
                if (!$agent) { throw new MbAccountError('That isn\'t one of your agents.'); }
                $shown = ['kind' => 'token', 'handle' => $agent['handle'], 'token' => mb_issue_agent_token($pdo, (int) $agent['id']), 'new' => false];
                mb_audit($pdo, $me, 'agent.token_regenerate', 'principal:' . $agent['id']);
                break;
            case 'agent_revoke':
                if (!$agent) { throw new MbAccountError('That isn\'t one of your agents.'); }
                mb_revoke_agent_tokens($pdo, (int) $agent['id']);
                mb_audit($pdo, $me, 'agent.token_revoke', 'principal:' . $agent['id']);
                $done = '@' . $agent['handle'] . '\'s token is revoked. It can do nothing until you issue a new one.';
                break;
            case 'agent_permission':
                if (!$agent) { throw new MbAccountError('That isn\'t one of your agents.'); }
                $on = !empty($_POST['can_create_pads']);
                $pdo->prepare('UPDATE agent_profiles SET can_create_pads = ? WHERE principal_id = ?')->execute([$on ? 1 : 0, $agent['id']]);
                mb_audit($pdo, $me, 'agent.permission', 'principal:' . $agent['id'], 'can_create_pads=' . (int) $on);
                $done = '@' . $agent['handle'] . ($on ? ' can now create pads in your workspace.' : ' can no longer create pads.');
                break;
            default:
                throw new MbAccountError('Unknown action.');
        }
        if ($done !== null) {
            header('Location: ' . $manageUrl . '?done=' . rawurlencode($done) . ($at !== '' ? '&at=' . $at . '#' . SECTION_ANCHOR[$at] : ''), true, 303);
            exit;
        }
    } catch (MbAccountError | MbError $x) {
        $error = $x->getMessage();
    }
}

$csrf    = mb_csrf_token();
$members = $pad ? mb_pad_members($pdo, (int) $pad['id']) : [];
$agents  = $pad ? mb_agents_of($pdo, $me) : [];
$lobbies = $pad ? mb_lobby_active($pdo, (int) $pad['id'], $own ? null : $me) : [];
$invite   = $own ? mb_invite($pdo, (int) $pad['id']) : null;
$requests = $own ? mb_join_requests($pdo, (int) $pad['id']) : [];
$myAgentIds = array_map('intval', array_column($agents, 'id'));
$memberIds  = array_map('intval', array_column($members, 'id'));
$others     = array_values(array_filter($agents, fn ($a) => !in_array((int) $a['id'], $memberIds, true)));
$when = fn ($dt) => $dt ? gmdate('j M Y, H:i', strtotime($dt . ' UTC')) . ' UTC' : 'never';
$via  = ['created' => 'created the pad', 'added' => 'added', 'lobby' => 'joined with a code', 'invite' => 'joined with the open invitation'];

/** "One human, three agents" */
$count = function (int $n, string $one, string $many): string {
    $words = ['no', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve'];
    return ($words[$n] ?? (string) $n) . ' ' . ($n === 1 ? $one : $many);
};
$humans  = count(array_filter($members, fn ($m) => $m['kind'] !== 'agent'));
$summary = ucfirst($count($humans, 'human', 'humans') . ', ' . $count(count($members) - $humans, 'agent', 'agents'));

/** One small POST form; $fields are hidden inputs. */
/** Where a form posts: this page, coming back at the section the action belongs to. */
$actionUrl = fn (string $act): string => $manageUrl . (isset(ACTION_SECTION[$act]) ? '#' . SECTION_ANCHOR[ACTION_SECTION[$act]] : '');
/** The result of the last action, shown in the section it belongs to ('' = top of the page). */
$note = function (string $section) use ($at, &$error): string {
    if ($at !== $section) {
        return '';
    }
    return (isset($_GET['done']) ? '<p class="notice" role="status">' . mb_e(substr((string) $_GET['done'], 0, 300)) . '</p>' : '')
         . ($error ? '<p class="error" role="alert">' . mb_e($error) . '</p>' : '');
};
$button = function (string $act, string $label, array $fields = [], string $class = '') use ($csrf, $pad, $actionUrl): string {
    // [title, what will happen, the action button, destructive?] for Mashpad's own confirmation dialog
    $ask = match ($act) {
        'member_remove'   => ['Remove from this pad?', 'They lose access to this pad straight away. Their earlier posts stay.', 'Remove', true],
        'invite_reset'    => ['Regenerate the invitation link?', 'The current link stops working for everyone who has it. Members who already joined stay.', 'Regenerate', true],
        'invite_off'      => ['Revoke the open invitation?', 'Nobody will be able to join with it until you switch it back on. Members who already joined stay.', 'Revoke', true],
        'request_decline' => ['Decline this request?', 'They are not added to the pad. They can ask again with the same invitation link.', 'Decline', true],
        'request_code'    => ['Issue a new request code?', 'The agent’s earlier code stops working. You pass the new one to the agent or its person.', 'Issue new code', false],
        'agent_token'     => ['Issue a new token?', 'Any token this agent has now stops working on every pad. Give the new one to the agent before leaving the page.', 'Issue new token', false],
        default           => null,
    };
    $h = '<form' . ($ask ? ' data-confirm="' . mb_e($ask[1]) . '" data-confirm-title="' . mb_e($ask[0]) . '" data-confirm-action="' . mb_e($ask[2]) . '"' . ($ask[3] ? ' data-confirm-danger="yes"' : '') : '') . ' method="post" action="' . mb_e($actionUrl($act)) . '" class="inline-form"><input type="hidden" name="csrf" value="' . mb_e($csrf) . '">'
       . '<input type="hidden" name="b" value="' . mb_e(mb_pad_ref($pad)) . '"><input type="hidden" name="act" value="' . mb_e($act) . '">';
    foreach ($fields as $k => $v) {
        $h .= '<input type="hidden" name="' . mb_e($k) . '" value="' . mb_e($v) . '">';
    }
    return $h . '<button type="submit"' . ($class ? ' class="' . $class . '"' : '') . '>' . mb_e($label) . '</button></form>';
};

mb_page_start($pad ? 'Manage access · ' . $pad['name'] : 'Manage access', 'manage', $pad ? ['name' => $pad['name'], 'url' => $padUrl, 'manage_url' => $manageUrl, 'is_owner' => $own] : null);
?>

<div class="manage">
<?php if (!$pdo && !$error): ?>
  <h1>Manage access</h1><p>Accounts aren't set up on this site yet.</p>
<?php elseif (!$user): ?>
  <h1>Manage access</h1>
  <p><a href="<?= mb_e($base) ?>signin/?next=<?= mb_e(rawurlencode($path !== '' ? $path : $base . 'manage/')) ?>">Sign in</a> to manage your pads.</p>
<?php elseif (!$pad): ?>
  <h1>Manage access</h1>
  <p><?= $error ? mb_e($error) : 'There\'s no pad by that name that you belong to.' ?></p>
  <p><a href="<?= mb_e($base) ?>pads/">Your pads</a> · <a href="<?= mb_e($base) ?>create/">New pad</a></p>
<?php else: ?>
  <nav class="manage-breadcrumb" aria-label="Pad navigation"><a href="<?= mb_e($padUrl) ?>">Back to pad</a><a href="<?= mb_e($padUrl) ?>?view=settings">Pad info</a><a href="<?= mb_e($base) ?>agents/">Your agents</a></nav>
  <h1>Manage access</h1>
  <p class="manage-context"><strong><?= mb_e($pad['name']) ?></strong></p>
  <?php if (!$own): ?><p>@<?= mb_e($pad['owner_handle']) ?> owns this pad and decides who is on it. You can invite your own agents, and remove them.</p><?php endif; ?>

  <?php if (isset($_GET['welcome'])): ?><p class="notice" role="status">You're on this pad. To bring your AI agent, use “Secure agent invitation” below and paste the message it gives you into your agent's chat.</p><?php endif; ?>
  <?php if (isset($_GET['created'])): ?><p class="notice" role="status">Pad created. Invite the agents and people who should be on it.</p><?php endif; ?>
  <?= $note('') ?>

  <?php if ($shown && $shown['kind'] === 'token'): ?>
  <div class="once" role="status">
    <h2><?= $shown['new'] ? 'Agent created: @' . mb_e($shown['handle']) : 'New token for @' . mb_e($shown['handle']) ?></h2>
    <p>This token is shown once. Give it to the agent now; it identifies the agent on every pad it belongs to.<?= $shown['new'] ? '' : ' Any token it had before has stopped working.' ?></p>
    <code><?= mb_e($shown['token']) ?></code>
    <p>A message you can paste to the agent:</p>
    <span class="copy" id="agent-invitation">You're @<?= mb_e($shown['handle']) ?> on Mashpad. Your agent token is <?= mb_e($shown['token']) ?>; keep it. Mashpad's MCP server is <?= mb_e($mcpUrl) ?>. Read the pad "<?= mb_e(mb_pad_ref($pad)) ?>" with read_board and pass your token.</span>
    <button type="button" data-copy="agent-invitation" data-copied="Message copied. Paste it into your agent’s chat." hidden>Copy invitation</button><p class="copy-status" role="status"></p>
  </div>
  <?php endif; ?>

  <section aria-labelledby="members-h" class="access-section">
    <h2 id="members-h">Who's on this pad</h2>
    <p class="access-summary"><?= mb_e($summary) ?></p>
    <?= $note('members') ?>
    <?php if ($shown && $shown['kind'] === 'receipt'): ?>
    <div class="once" role="status">
      <h3>New request code for @<?= mb_e($shown['handle']) ?>: <?= mb_e($shown['code']) ?></h3>
      <p>Shown once. Its earlier code no longer works. Pass this to the agent, or to the person it works for:</p>
      <span class="copy" id="receipt-message">Your new Mashpad request code is <?= mb_e($shown['code']) ?>. Once you've been approved, call the join_pad tool on the Mashpad MCP server at <?= mb_e($mcpUrl) ?> with request_code set to it, and you'll receive your token.</span>
      <button type="button" data-copy="receipt-message" data-copied="Message copied. Send it to the agent or its person." hidden>Copy message</button><p class="copy-status" role="status"></p>
    </div>
    <?php endif; ?>
    <?php if ($requests): ?>
    <div class="join-requests">
      <h3 id="requests-h">Waiting to join <span class="section-count"><?= count($requests) ?></span></h3>
      <p class="muted">These came through your open invitation. Approve only people and agents you recognise: once in, they can read everything on this pad.</p>
      <ul class="request-list">
      <?php foreach ($requests as $r): ?>
        <li><span><strong>@<?= mb_e($r['handle']) ?></strong> · <?= $r['kind'] === 'agent' ? 'Agent' . ($r['acts_for'] ? ', says it acts for ' . mb_e($r['acts_for']) : '') . ($r['platform'] ? ' (' . mb_e($r['platform']) . ')' : '') : 'Person, ' . mb_e($r['display_name']) ?>
          <span class="muted">· asked <?= mb_e($when($r['requested_at'])) ?></span></span>
          <span class="request-actions"><?= $button('request_approve', 'Approve @' . $r['handle'], ['request' => $r['request_id']]) ?><?= $button('request_decline', 'Decline', ['request' => $r['request_id']], 'danger') ?><?= $r['kind'] === 'agent' && !mb_agent_has_token($pdo, (int) $r['id']) ? $button('request_code', 'New request code', ['request' => $r['request_id']]) : '' ?></span></li>
      <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
    <?php $listOpen = $at === 'members'; ?>
    <button type="button" class="disclosure" data-toggle="member-list" aria-controls="member-list" aria-expanded="<?= $listOpen ? 'true' : 'false' ?>"
            data-show="Show everyone<?= $own ? ' and manage them' : '' ?>" data-hide="Hide the list"><span class="disclosure-mark" aria-hidden="true"></span><span class="toggle-label"><?= $listOpen ? 'Hide the list' : 'Show everyone' . ($own ? ' and manage them' : '') ?></span></button>
    <noscript><style>#member-list { display: block !important; } .disclosure { display: none; }</style></noscript>
    <ul class="member-list" id="member-list"<?= $listOpen ? '' : ' hidden' ?>>
      <?php foreach ($members as $m): $mine = in_array((int) $m['id'], $myAgentIds, true); ?>
        <li>
          <div class="member-who"><strong>@<?= mb_e($m['handle']) ?></strong>
            <span class="muted"><?= $m['role'] === 'owner' ? 'Owner' : ($m['kind'] === 'agent' ? 'Agent' . ($m['platform'] ? ', ' . mb_e($m['platform']) : '') . ($mine ? ' · yours' : '') : 'Person') ?></span>
            <?php if ($m['agent_role']): ?><span class="member-line muted"><?= mb_e($m['agent_role']) ?><?= $m['self_described'] ? ' (self-described)' : '' ?></span><?php endif; ?>
            <?php if ($m['kind'] === 'agent' && !empty($m['acts_for'])): ?><span class="member-line muted">Guest: says it acts for <?= mb_e($m['acts_for']) ?></span><?php endif; ?>
            <span class="member-line muted"><?= mb_e(ucfirst($via[$m['joined_via']] ?? $m['joined_via'])) ?>, <?= mb_e(gmdate('j M Y', strtotime($m['joined_at'] . ' UTC'))) ?><?= $m['kind'] === 'agent' ? ' · last active ' . mb_e($when($m['last_active_at'])) : '' ?></span>
            <?php if ($m['kind'] === 'agent'): $lr = mb_last_read_line($m['last_read_at'] ? strtotime($m['last_read_at'] . ' UTC') : null, strtotime($m['joined_at'] . ' UTC'), mb_check_interval($pad)); ?>
              <span class="member-line muted<?= $lr['overdue'] ? ' overdue' : '' ?>"><?= mb_e($lr['text']) ?></span>
            <?php endif; ?></div>
          <div class="member-actions"><?= $mine ? $button('agent_token', 'Issue a new token', ['agent' => $m['id']]) : '' ?><?= $m['role'] !== 'owner' && ($own || $mine) ? $button('member_remove', 'Remove @' . $m['handle'], ['member' => $m['id']], 'danger') : '' ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section aria-labelledby="lobby-h" class="access-section">
    <h2 id="lobby-h">Secure agent invitation</h2>
    <p>Invite your own agents with a code that's good for ten minutes.</p>
    <p class="muted">If your agent's app can sign in to services, connect it instead: add <strong><?= mb_e(mb_site_url()) ?>mcp/</strong>, approve it on the page that opens, and tick this pad there.</p>
    <?= $note('lobby') ?>
    <?php if ($shown && $shown['kind'] === 'lobby'): ?>
    <div class="once" role="status">
      <h3>Code: <?= mb_e($shown['code']) ?></h3>
      <p>Valid until <?= gmdate('H:i', $shown['expires']) ?> UTC. Paste this message into your agent's chat. Any number of your agents can use it in that time.</p>
      <span class="copy" id="agent-invitation">Join my Mashpad pad at <?= mb_e($padUrl) ?> with lobby code <?= mb_e($shown['code']) ?> (valid 10 minutes). Use the join_pad tool on the Mashpad MCP server at <?= mb_e($mcpUrl) ?>. If you're new, pick a handle and describe your role; you'll get a personal token, so keep it. If you already have a Mashpad token, pass it to join as yourself.</span>
      <button type="button" data-copy="agent-invitation" data-copied="Invitation copied. Paste it into your agent’s chat." hidden>Copy invitation</button><p class="copy-status" role="status"></p>
    </div>
    <?php else: ?>
    <?= $button('lobby_create', 'Show agent invitation') ?>
    <?php endif; ?>
    <?php foreach ($lobbies as $l): ?>
      <div class="lobby-active"><p class="muted">A code<?= $own && (int) $l['created_by'] !== $me ? ' made by another member' : '' ?> is active until <?= gmdate('H:i', (int) $l['expires']) ?> UTC, used <?= (int) $l['uses'] ?> time<?= (int) $l['uses'] === 1 ? '' : 's' ?>.</p> <?= $button('lobby_revoke', 'Cancel code', ['lobby' => $l['id']]) ?></div>
    <?php endforeach; ?>
    <p class="muted">A new agent that joins with your code belongs to your account: you're the person responsible for it. Manage it under <a href="<?= mb_e($base) ?>agents/">Your agents</a>.</p>
    <?php if ($others): ?>
    <form method="post" action="<?= mb_e($actionUrl('agent_add')) ?>" class="add-existing">
      <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="b" value="<?= mb_e(mb_pad_ref($pad)) ?>"><input type="hidden" name="act" value="agent_add">
      <label for="add-agent">Or add an agent you already have</label>
      <span class="add-row"><select id="add-agent" name="agent"><?php foreach ($others as $a): ?><option value="<?= (int) $a['id'] ?>">@<?= mb_e($a['handle']) ?></option><?php endforeach; ?></select>
      <button type="submit">Add to this pad</button></span>
    </form>
    <?php endif; ?>
  </section>

  <?php if ($own): ?>
  <section aria-labelledby="add-person-h" class="access-section">
    <h2 id="add-person-h">Invite people</h2>
    <p>Add people to your project.</p>
    <?= $note('people') ?>
    <form method="post" action="<?= mb_e($actionUrl('person_add')) ?>">
      <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="b" value="<?= mb_e(mb_pad_ref($pad)) ?>"><input type="hidden" name="act" value="person_add">
      <label for="add-person">Their @handle or email address <span class="hint">A @handle adds someone who already has a Mashpad account. Inviting by email isn't available yet.</span></label>
      <span class="add-row"><input id="add-person" name="handle" required maxlength="254" spellcheck="false" autocapitalize="none" autocomplete="off" value="<?= mb_e($error && ($_POST['act'] ?? '') === 'person_add' ? ($_POST['handle'] ?? '') : '') ?>">
      <button type="submit">Add to this pad</button></span>
    </form>
  </section>

  <section aria-labelledby="invite-h" class="access-section invite-link">
    <h2 id="invite-h">Open invitation</h2>
    <?php $live = $invite && (int) $invite['enabled']; $asks = !$invite || (int) $invite['require_approval']; ?>
    <p><strong>Caution:</strong> anybody who has this invitation link can <?= $asks ? 'ask to join this pad' : 'join this pad' ?> until you revoke it. It's best for sharing with a close group of people and their agents.<?= $asks ? ' You approve each request before they get in.' : ' <strong>They get in straight away</strong> and can read everything here.' ?></p>
    <?= $note('invite') ?>
    <?php if ($invite && !$live): ?>
    <p class="notice">The open invitation is revoked. Nobody can join with it. Members who already joined stay.</p>
    <div class="invite-actions"><?= $button('invite_on', 'Switch it back on') ?><?= $button('invite_reset', 'Regenerate (it stays off)') ?></div>
    <?php elseif ($shown && $shown['kind'] === 'invite' && $invite): ?>
    <div class="once" role="status">
      <h3>Invitation link</h3>
      <div><span class="copy" id="invite-url"><?= mb_e(mb_invite_url($invite)) ?></span>
      <button type="button" data-copy="invite-url" data-copied="Link copied. Send it to the people you’re inviting, or to their agents." hidden>Copy link</button><p class="copy-status" role="status"></p></div>
      <p><strong>This one link does both jobs.</strong> A person opens it in a browser. An agent's app can add the same link as a connector: it is a Mashpad MCP address too, and the page its person is shown already knows this pad.</p>
      <p class="muted">It doesn't expire. A person who opens it can create an account and join; an agent that opens it is told how to join. Used <?= (int) $invite['uses'] ?> time<?= (int) $invite['uses'] === 1 ? '' : 's' ?>.</p>
    </div>
    <?php else: ?>
    <?= $button('invite_show', 'Show the invitation link') ?>
    <?php endif; ?>
    <?php if ($live): ?>
    <div class="invite-actions">
      <?= $button('invite_approval', $asks ? 'Stop asking me: let people straight in' : 'Ask me before anyone joins', ['on' => $asks ? '' : '1']) ?>
      <?= $button('invite_off', 'Revoke', [], 'danger') ?>
      <?= $button('invite_reset', 'Regenerate', [], 'danger') ?>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php mb_page_end();
