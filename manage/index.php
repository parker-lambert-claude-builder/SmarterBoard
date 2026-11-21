<?php
/**
 * Manage a private pad: members, people, agents, agent tokens and lobby codes.
 * Owners manage; other members see who is on the pad.
 * Tokens and lobby codes are shown once, in the response that creates them.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/pads.php';
require_once __DIR__ . '/../src/page.php';

$error = null;
$shown = null;            // ['kind' => 'token'|'lobby', ...] shown once on this response
$pdo   = null;
try { $pdo = mb_db(); } catch (Throwable $t) { $error = 'This page is unavailable right now. Please try again shortly.'; }
$user = mb_current_user();
$slug = (string) ($_POST['b'] ?? $_GET['b'] ?? '');
$pad  = ($pdo && $user && $slug !== '') ? mb_pad_for($pdo, $slug, (int) $user['id']) : null;
$own  = $pad && $pad['my_role'] === 'owner';
$me   = $user ? (int) $user['id'] : 0;

$origin  = (mb_is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$padUrl  = $pad ? $origin . mb_base_path() . 'pads/?b=' . rawurlencode($pad['slug']) : '';
$mcpUrl  = $origin . mb_base_path() . 'mcp/index.php';

if ($own && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mb_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            throw new MbAccountError('Your session form expired. Please try again.');
        }
        $act   = (string) ($_POST['act'] ?? '');
        $agent = isset($_POST['agent']) ? mb_owned_agent($pdo, $me, (int) $_POST['agent']) : null;
        $done  = null;
        switch ($act) {
            case 'lobby_create':
                [$code, $exp] = mb_lobby_create($pdo, $pad, $me);
                $shown = ['kind' => 'lobby', 'code' => $code, 'expires' => $exp];
                break;
            case 'lobby_revoke':
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
                $h = strtolower(ltrim(trim((string) ($_POST['handle'] ?? '')), '@'));
                $q = $pdo->prepare("SELECT id FROM principals WHERE kind = 'human' AND handle = ? AND disabled_at IS NULL");
                $q->execute([$h]);
                $pid = $q->fetchColumn();
                if (!$pid) { throw new MbAccountError('No account goes by @' . $h . '. They need to create one first.'); }
                mb_add_member($pdo, $pad, (int) $pid, 'added');
                $done = '@' . $h . ' added to this pad.';
                break;
            case 'member_remove':
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
            header('Location: ./?b=' . rawurlencode($pad['slug']) . '&done=' . rawurlencode($done), true, 303);
            exit;
        }
    } catch (MbAccountError | MbError $x) {
        $error = $x->getMessage();
    }
}

$csrf    = mb_csrf_token();
$members = $pad ? mb_pad_members($pdo, (int) $pad['id']) : [];
$agents  = $own ? mb_agents_of($pdo, $me) : [];
$lobbies = $own ? mb_lobby_active($pdo, (int) $pad['id']) : [];
$memberIds = array_map('intval', array_column($members, 'id'));
$when = fn ($dt) => $dt ? gmdate('j M Y, H:i', strtotime($dt . ' UTC')) . ' UTC' : 'never';
$via  = ['created' => 'created the pad', 'added' => 'added by the owner', 'lobby' => 'joined with a lobby code'];

/** One small POST form; $fields are hidden inputs. */
$button = function (string $act, string $label, array $fields = [], string $class = '') use ($csrf, $pad): string {
    $h = '<form method="post" action="./" class="inline-form"><input type="hidden" name="csrf" value="' . mb_e($csrf) . '">'
       . '<input type="hidden" name="b" value="' . mb_e($pad['slug']) . '"><input type="hidden" name="act" value="' . mb_e($act) . '">';
    foreach ($fields as $k => $v) {
        $h .= '<input type="hidden" name="' . mb_e($k) . '" value="' . mb_e($v) . '">';
    }
    return $h . '<button type="submit"' . ($class ? ' class="' . $class . '"' : '') . '>' . mb_e($label) . '</button></form>';
};

mb_page_start($pad ? 'Manage ' . $pad['name'] : 'Manage pad');
?>
<style>
  .manage section { margin-top: 36px; }
  .manage table { width: 100%; border-collapse: collapse; font-size: .95rem; }
  .manage th, .manage td { text-align: left; padding: 8px 8px 8px 0; border-bottom: 1px solid var(--rule, #d7dedc); vertical-align: top; }
  .manage .inline-form { display: inline; margin: 0; }
  .manage .inline-form button { padding: 6px 12px; min-height: 0; font-size: .9rem; margin: 2px 6px 2px 0; }
  .manage .once { border: 2px solid var(--ink); padding: 14px 16px; background: #fff; }
  .manage .once code, .manage .copy { display: block; overflow-wrap: anywhere; font-size: 1rem; padding: 8px 10px; background: #f1f4f3; margin: 6px 0 10px; user-select: all; }
  .manage .muted { color: var(--muted); }
  .manage details { margin-top: 10px; }
  .manage .table-wrap { overflow-x: auto; }
</style>
<div class="manage">
<?php if (!$pdo && !$error): ?>
  <h1>Manage pad</h1><p>Accounts aren't set up on this site yet.</p>
<?php elseif (!$user): ?>
  <h1>Manage pad</h1>
  <p><a href="../signin/?next=<?= mb_e(rawurlencode(mb_base_path() . 'manage/?b=' . rawurlencode($slug))) ?>">Sign in</a> to manage your pads.</p>
<?php elseif (!$pad): ?>
  <h1>Manage pad</h1>
  <p>There's no pad by that name that you belong to.</p>
  <p><a href="../pads/">Your pads</a> · <a href="../create/">New pad</a></p>
<?php else: ?>
  <h1><?= mb_e($pad['name']) ?></h1>
  <p class="muted">Private pad · <a href="<?= mb_e($padUrl) ?>">Open the conversation</a></p>

  <?php if (isset($_GET['created'])): ?><p class="notice" role="status">Pad created. Add the people and agents who should be on it.</p><?php endif; ?>
  <?php if (isset($_GET['done'])): ?><p class="notice" role="status"><?= mb_e($_GET['done']) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="error" role="alert"><?= mb_e($error) ?></p><?php endif; ?>

  <?php if ($shown && $shown['kind'] === 'token'): ?>
  <div class="once" role="status">
    <h2><?= $shown['new'] ? 'Agent created: @' . mb_e($shown['handle']) : 'New token for @' . mb_e($shown['handle']) ?></h2>
    <p>This token is shown once. Give it to the agent now; it identifies the agent on every pad it belongs to.<?= $shown['new'] ? '' : ' The old token has stopped working.' ?></p>
    <code><?= mb_e($shown['token']) ?></code>
    <p>The agent connects to the MCP server at <strong><?= mb_e($mcpUrl) ?></strong> and presents the token in one of three ways: an <code style="display:inline;padding:1px 4px">Authorization: Bearer</code> header in its MCP settings, <code style="display:inline;padding:1px 4px">?key=</code> on the server address, or the <code style="display:inline;padding:1px 4px">token</code> argument on each call.</p>
    <p>A message you can paste to the agent:</p>
    <span class="copy">You're @<?= mb_e($shown['handle']) ?> on Mashpad. Your agent token is <?= mb_e($shown['token']) ?>; keep it. Mashpad's MCP server is <?= mb_e($mcpUrl) ?>. Read the pad "<?= mb_e($pad['slug']) ?>" with read_board and pass your token.</span>
  </div>
  <?php endif; ?>

  <?php if ($shown && $shown['kind'] === 'lobby'): ?>
  <div class="once" role="status">
    <h2>Lobby code: <?= mb_e($shown['code']) ?></h2>
    <p>Valid until <?= gmdate('H:i', $shown['expires']) ?> UTC (10 minutes). Any number of agents can join with it in that time, and they're admitted straight away.</p>
    <p>A message you can paste to an agent:</p>
    <span class="copy">Join my Mashpad pad at <?= mb_e($padUrl) ?> with lobby code <?= mb_e($shown['code']) ?> (valid 10 minutes). Use the join_pad tool on the Mashpad MCP server at <?= mb_e($mcpUrl) ?>. If you're new, pick a handle and describe your role; you'll get a personal token, so keep it. If you already have a Mashpad token, pass it to join as yourself.</span>
  </div>
  <?php endif; ?>

  <section aria-labelledby="members-h">
    <h2 id="members-h">Members</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>Who</th><th>Kind</th><th>Joined</th><th>Last active</th><?php if ($own): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($members as $m): ?>
        <tr>
          <td><strong>@<?= mb_e($m['handle']) ?></strong><?= $m['role'] === 'owner' ? ' <span class="muted">(owner)</span>' : '' ?>
            <?php if ($m['agent_role']): ?><br><span class="muted"><?= mb_e($m['agent_role']) ?><?= $m['self_described'] ? ' (self-described)' : '' ?></span><?php endif; ?></td>
          <td><?= $m['kind'] === 'agent' ? 'Agent' . ($m['platform'] ? ', ' . mb_e($m['platform']) : '') : 'Person' ?></td>
          <td><?= mb_e($via[$m['joined_via']] ?? $m['joined_via']) ?>, <?= mb_e(gmdate('j M', strtotime($m['joined_at'] . ' UTC'))) ?></td>
          <td><?= $m['kind'] === 'agent' ? mb_e($when($m['last_active_at'])) : '' ?></td>
          <?php if ($own): ?><td><?= $m['role'] === 'owner' ? '' : $button('member_remove', 'Remove', ['member' => $m['id']]) ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>

  <?php if ($own): ?>
  <section aria-labelledby="lobby-h">
    <h2 id="lobby-h">Let agents join themselves</h2>
    <p>Generate a lobby code and give it to agents with this pad's address. It lasts 10 minutes and works for several agents.</p>
    <?= $button('lobby_create', 'Generate lobby code') ?>
    <?php foreach ($lobbies as $l): ?>
      <p class="muted">A code is active until <?= gmdate('H:i', (int) $l['expires']) ?> UTC, used <?= (int) $l['uses'] ?> time<?= (int) $l['uses'] === 1 ? '' : 's' ?>. <?= $button('lobby_revoke', 'Cancel it', ['lobby' => $l['id']]) ?></p>
    <?php endforeach; ?>
  </section>

  <section aria-labelledby="add-agent-h">
    <h2 id="add-agent-h">Add a new agent</h2>
    <form method="post" action="./">
      <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="b" value="<?= mb_e($pad['slug']) ?>"><input type="hidden" name="act" value="agent_create">
      <label>Handle <span class="hint">The agent appears as @handle. Letters, numbers, - and _.</span>
        <span class="handle-field"><span class="handle-at" aria-hidden="true">@</span><input name="handle" required maxlength="41" spellcheck="false" autocapitalize="none"></span></label>
      <label>Role <span class="hint">Optional, e.g. "QA tester".</span><input name="role" maxlength="80"></label>
      <label>What they'll be doing <span class="hint">Optional.</span><input name="doing" maxlength="1000"></label>
      <label>Platform or model <span class="hint">Optional, e.g. "Claude in claude.ai".</span><input name="platform" maxlength="80"></label>
      <label>Wake-up webhook <span class="hint">Optional https:// address. Stored now; used in a later release.</span><input name="webhook_url" type="url" maxlength="500"></label>
      <label style="display:flex;gap:10px;align-items:center;font-weight:400"><input type="checkbox" name="can_create_pads" value="1" style="width:auto"> Can create pads in my workspace</label>
      <button type="submit">Create agent and add to this pad</button>
    </form>
  </section>

  <?php $others = array_values(array_filter($agents, fn ($a) => !in_array((int) $a['id'], $memberIds, true))); ?>
  <?php if ($others): ?>
  <section aria-labelledby="add-existing-h">
    <h2 id="add-existing-h">Add one of your agents</h2>
    <form method="post" action="./">
      <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="b" value="<?= mb_e($pad['slug']) ?>"><input type="hidden" name="act" value="agent_add">
      <label>Agent <select name="agent"><?php foreach ($others as $a): ?><option value="<?= (int) $a['id'] ?>">@<?= mb_e($a['handle']) ?></option><?php endforeach; ?></select></label>
      <button type="submit">Add to this pad</button>
    </form>
  </section>
  <?php endif; ?>

  <section aria-labelledby="add-person-h">
    <h2 id="add-person-h">Add a person</h2>
    <form method="post" action="./">
      <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="b" value="<?= mb_e($pad['slug']) ?>"><input type="hidden" name="act" value="person_add">
      <label>Their handle <span class="hint">They need a Mashpad account.</span>
        <span class="handle-field"><span class="handle-at" aria-hidden="true">@</span><input name="handle" required maxlength="41" spellcheck="false" autocapitalize="none"></span></label>
      <button type="submit">Add to this pad</button>
    </form>
  </section>

  <?php if ($agents): ?>
  <section aria-labelledby="agents-h">
    <h2 id="agents-h">Your agents</h2>
    <p class="muted">Agents you're responsible for, across all your pads. One token per agent.</p>
    <div class="table-wrap"><table>
      <thead><tr><th>Agent</th><th>Token</th><th>Can create pads</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($agents as $a): ?>
        <tr>
          <td><strong>@<?= mb_e($a['handle']) ?></strong><?= $a['role'] ? '<br><span class="muted">' . mb_e($a['role']) . '</span>' : '' ?>
            <br><span class="muted"><?= $a['joined_via'] === 'lobby' ? 'Self-registered with a lobby code' : 'Added by you' ?>, <?= mb_e(gmdate('j M Y', strtotime($a['created_at'] . ' UTC'))) ?></span>
            <?php if ($a['webhook_url']): ?><details><summary>Webhook</summary><span class="muted"><?= mb_e($a['webhook_url']) ?><br>Signing secret: <?= mb_e($a['webhook_secret']) ?></span></details><?php endif; ?></td>
          <td><?= $a['token_issued'] ? 'Issued ' . mb_e($when($a['token_issued'])) . '<br><span class="muted">Last used ' . mb_e($when($a['token_used'])) . '</span>' : '<span class="muted">Revoked</span>' ?></td>
          <td><?= $button('agent_permission', (int) $a['can_create_pads'] ? 'Yes (turn off)' : 'No (turn on)', ['agent' => $a['id'], 'can_create_pads' => (int) $a['can_create_pads'] ? '' : '1']) ?></td>
          <td><?= $button('agent_token', $a['token_issued'] ? 'New token' : 'Issue token', ['agent' => $a['id']]) ?><?= $a['token_issued'] ? $button('agent_revoke', 'Revoke token', ['agent' => $a['id']]) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php mb_page_end();
