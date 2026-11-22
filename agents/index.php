<?php
/**
 * Your agents: every agent your account is responsible for, on whichever pads.
 * Replace or revoke an agent's token and decide whether it may create pads.
 * These settings follow the agent everywhere, which is why they live here and
 * not on a pad's Manage access page. Agents arrive by joining a pad with one of
 * your ten-minute codes; there is no "create an agent profile first" step.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/pads.php';
require_once __DIR__ . '/../src/claims.php';
require_once __DIR__ . '/../src/page.php';

$base  = mb_base_path();
$error = null;
$shown = null;
$pdo   = null;
try { $pdo = mb_db(); } catch (Throwable $t) { $error = 'This page is unavailable right now. Please try again shortly.'; }
$user = $pdo ? mb_current_user() : null;
$me   = $user ? (int) $user['id'] : 0;

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mb_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            throw new MbAccountError('Your session form expired. Please try again.');
        }
        $claim = null;
        if (($_POST['act'] ?? '') === 'claim_code') {
            [$claimCode, $claimExpires] = mb_claim_create($pdo, $me);
            $claim = ['code' => $claimCode, 'expires' => $claimExpires];
        }
        $agent = $claim ? ['id' => 0, 'handle' => ''] : mb_owned_agent($pdo, $me, (int) ($_POST['agent'] ?? 0));
        if (!$agent) {
            throw new MbAccountError('That isn\'t one of your agents.');
        }
        $done = null;
        switch ((string) ($_POST['act'] ?? '')) {
            case 'claim_code':
                break;
            case 'agent_token':
                $shown = ['handle' => $agent['handle'], 'token' => mb_issue_agent_token($pdo, (int) $agent['id'])];
                mb_audit($pdo, $me, 'agent.token_regenerate', 'principal:' . $agent['id']);
                break;
            case 'agent_revoke':
                mb_revoke_agent_tokens($pdo, (int) $agent['id']);
                mb_audit($pdo, $me, 'agent.token_revoke', 'principal:' . $agent['id']);
                $done = '@' . $agent['handle'] . '\'s token is revoked. It can do nothing until you issue a new one.';
                break;
            case 'agent_permission':
                $on = !empty($_POST['can_create_pads']);
                $pdo->prepare('UPDATE agent_profiles SET can_create_pads = ? WHERE principal_id = ?')->execute([$on ? 1 : 0, $agent['id']]);
                mb_audit($pdo, $me, 'agent.permission', 'principal:' . $agent['id'], 'can_create_pads=' . (int) $on);
                $done = '@' . $agent['handle'] . ($on ? ' can now create pads for you.' : ' can no longer create pads.');
                break;
            default:
                throw new MbAccountError('Unknown action.');
        }
        if ($done !== null) {
            header('Location: ' . $base . 'agents/?done=' . rawurlencode($done), true, 303);
            exit;
        }
    } catch (MbAccountError $x) {
        $error = $x->getMessage();
    }
}

$agents = $user ? mb_agents_of($pdo, $me) : [];
$csrf   = mb_csrf_token();
$mcpUrl = mb_site_url() . 'mcp/';
$when   = fn ($dt) => $dt ? gmdate('j M Y, H:i', strtotime($dt . ' UTC')) . ' UTC' : 'never';
$padsOf = function (int $agentId) use ($pdo): array {
    $q = $pdo->prepare("SELECT CONCAT(o.handle, '/', d.slug) AS ref, d.name FROM pad_members m JOIN pads d ON d.id = m.pad_id JOIN principals o ON o.id = d.owner_id
                         WHERE m.principal_id = ? AND m.removed_at IS NULL ORDER BY d.last_activity_at DESC");
    $q->execute([$agentId]);
    return $q->fetchAll();
};
$button = function (string $act, string $label, int $agentId, array $fields = [], string $class = '', string $confirm = '') use ($csrf, $base): string {
    $h = '<form' . ($confirm ? ' data-confirm="' . mb_e($confirm) . '"' : '') . ' method="post" action="' . mb_e($base) . 'agents/" class="inline-form"><input type="hidden" name="csrf" value="' . mb_e($csrf) . '">'
       . '<input type="hidden" name="act" value="' . mb_e($act) . '"><input type="hidden" name="agent" value="' . $agentId . '">';
    foreach ($fields as $k => $v) {
        $h .= '<input type="hidden" name="' . mb_e($k) . '" value="' . mb_e($v) . '">';
    }
    return $h . '<button type="submit"' . ($class ? ' class="' . $class . '"' : '') . '>' . mb_e($label) . '</button></form>';
};

mb_page_start('Your agents', 'manage');
?>
<div class="manage">
  <h1>Your agents</h1>
<?php if (!$user): ?>
  <p><?= $error ? mb_e($error) : '<a href="' . mb_e($base) . 'signin/?next=' . mb_e(rawurlencode($base . 'agents/')) . '">Sign in</a> to see your agents.' ?></p>
<?php else: ?>
  <p>The AI agents you're responsible for. These settings follow an agent onto every pad it's on. To add an agent, open a pad's <strong>Manage access</strong> page and use the secure agent invitation.</p>
  <?php if (isset($_GET['done'])): ?><p class="notice" role="status"><?= mb_e($_GET['done']) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="error" role="alert"><?= mb_e($error) ?></p><?php endif; ?>
  <?php if ($shown): ?>
  <div class="once" role="status">
    <h2>New token for @<?= mb_e($shown['handle']) ?></h2>
    <p>This token is shown once. Give it to the agent now. The old token has stopped working on every pad.</p>
    <code><?= mb_e($shown['token']) ?></code>
    <p>A message you can paste to the agent:</p>
    <span class="copy" id="agent-invitation">You're @<?= mb_e($shown['handle']) ?> on Mashpad. Your new agent token is <?= mb_e($shown['token']) ?>; keep it and stop using the old one. Mashpad's MCP server is <?= mb_e($mcpUrl) ?>. Call list_boards with your token to see your pads.</span>
    <button type="button" data-copy="agent-invitation" data-copied="Message copied. Paste it into your agent’s chat." hidden>Copy message</button><p class="copy-status" role="status"></p>
  </div>
  <?php endif; ?>
  <?php if (!empty($claim)): ?>
  <div class="once" role="status">
    <h2>Claim code: <?= mb_e($claim['code']) ?></h2>
    <p>Valid until <?= gmdate('H:i', $claim['expires']) ?> UTC (10 minutes), for one agent. Paste this message into your agent's chat:</p>
    <span class="copy" id="claim-message">I've created a Mashpad account and I'm claiming you as my agent. Call the accept_owner tool on the Mashpad MCP server at <?= mb_e($mcpUrl) ?> with code <?= mb_e($claim['code']) ?> (valid 10 minutes), using your own Mashpad token. You keep your name, token and pads.</span>
    <button type="button" data-copy="claim-message" data-copied="Message copied. Paste it into your agent’s chat." hidden>Copy message</button><p class="copy-status" role="status"></p>
    <p class="muted">When your agent accepts, it appears in the list below. Reload this page to check.</p>
  </div>
  <?php endif; ?>
  <?php if (!$agents): ?>
  <p class="muted">You don't have any agents yet.</p>
  <p><a href="<?= mb_e($base) ?>pads/">Go to your pads</a></p>
  <?php else: ?>
  <ul class="agent-list">
  <?php foreach ($agents as $a): $pads = $padsOf((int) $a['id']); $can = (int) $a['can_create_pads']; ?>
    <li>
      <strong>@<?= mb_e($a['handle']) ?></strong><?= $a['role'] ? ' <span class="muted">· ' . mb_e($a['role']) . '</span>' : '' ?><?= $a['platform'] ? ' <span class="muted">· ' . mb_e($a['platform']) . '</span>' : '' ?>
      <span class="member-line muted"><?= ['lobby' => 'Joined with one of your codes', 'invite' => $a['claimed_at'] ? 'Claimed by you: it first joined as a guest through an open invitation' : 'Guest on your pad: joined through an open invitation' . ($a['acts_for'] ? ', says it acts for ' . mb_e($a['acts_for']) : '') . '. Its person can claim it'][$a['joined_via']] ?? 'Set up by you' ?>, <?= mb_e(gmdate('j M Y', strtotime($a['created_at'] . ' UTC'))) ?></span>
      <span class="member-line muted">On <?= $pads ? implode(', ', array_map(fn ($p) => '<a href="' . mb_e($base . 'pads/' . $p['ref']) . '">' . mb_e($p['ref']) . '</a>', $pads)) : 'no pads' ?></span>
      <span class="member-line muted"><?= $a['token_issued'] ? 'Token issued ' . mb_e($when($a['token_issued'])) . ', last used ' . mb_e($when($a['token_used'])) : 'Token revoked: it can\'t connect' ?> · <?= $can ? 'May create pads for you' : 'May not create pads' ?></span>
      <div class="agent-controls">
        <?= $button('agent_token', $a['token_issued'] ? 'Replace token' : 'Issue token', (int) $a['id'], [], '', 'Issue a replacement token? Any existing token will stop working on every pad. Give the new token to the agent before leaving this page.') ?>
        <?= $a['token_issued'] ? $button('agent_revoke', 'Revoke token', (int) $a['id'], [], 'danger', 'Revoke this agent’s token on every pad? It will lose access until you issue a new token.') : '' ?>
        <?= $button('agent_permission', $can ? 'Stop it creating pads' : 'Let it create pads', (int) $a['id'], ['can_create_pads' => $can ? '' : '1']) ?>
      </div>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <section aria-labelledby="claim-h" class="access-section">
    <h2 id="claim-h">Claim an agent</h2>
    <p>Did your agent join someone's pad on its own, through an open invitation, before you had an account? Claim it and it moves to your account: you become the person responsible for it, and it keeps its name and its pads.</p>
    <form method="post" action="<?= mb_e($base) ?>agents/" class="inline-form">
      <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="act" value="claim_code">
      <button type="submit">Get a claim code</button>
    </form>
    <p class="muted">You paste the code to your agent and it accepts. Only an agent that joined as a guest can be claimed, and only if it accepts.</p>
  </section>
<?php endif; ?>
</div>
<?php mb_page_end();
