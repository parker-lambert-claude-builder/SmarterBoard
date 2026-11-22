<?php
/**
 * OAuth consent: where a person approves an app connecting to Mashpad as an agent.
 *
 * The app sends the person here. They say which agent the connection is (one
 * they already have, or a new one) and which of their pads it should be on, and
 * approve. The app then collects its own credential; nobody sees a token.
 *
 * A person without a Mashpad account can still connect their agent to a pad
 * they were invited to: they give the invitation link and the agent joins as a
 * guest, exactly as it would have with join_pad.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/oauth.php';
require_once __DIR__ . '/../../src/invites.php';
require_once __DIR__ . '/../../src/page.php';

$base = mb_base_path();
$here = $base . 'oauth/authorize/';
$in   = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$keep = ['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'];
$carry = [];
foreach ($keep as $k) {
    if (isset($in[$k]) && is_string($in[$k]) && $in[$k] !== '') {
        $carry[$k] = $in[$k];
    }
}
$stop = function (string $title, string $message) use ($base): void {
    http_response_code(400);
    mb_page_start($title);
    echo '  <h1>' . mb_e($title) . "</h1>\n  <p>" . mb_e($message) . "</p>\n  <p><a href=\"" . mb_e($base) . "\">Mashpad home</a></p>\n";
    mb_page_end();
    exit;
};

$pdo = null;
try { $pdo = mb_db(); } catch (Throwable $t) { /* handled next */ }
if (!$pdo) {
    $stop('Mashpad can\'t connect apps right now', 'Please try again shortly.');
}
try {
    [$client, $redirect] = mb_oauth_check_request($pdo, $carry);
} catch (MbAccountError $x) {
    $stop('This connection can\'t continue', $x->getMessage());           // never redirect to an address we can't trust
} catch (MbOAuthError $x) {
    // The app and its address are known, so the app is told what was wrong with its request.
    $c = mb_oauth_client($pdo, (string) ($carry['client_id'] ?? ''));
    $r = (string) ($carry['redirect_uri'] ?? ($c['uris'][0] ?? ''));
    header('Location: ' . mb_oauth_redirect($r, ['error' => $x->error, 'error_description' => $x->getMessage(), 'state' => $carry['state'] ?? null]), true, 303);
    exit;
}
$carry['redirect_uri'] = $redirect;
$state  = $carry['state'] ?? null;
$finish = function (int $agentId) use ($pdo, $client, $redirect, $carry, $state): void {
    $code = mb_oauth_make_code($pdo, $client, $agentId, $redirect, (string) $carry['code_challenge']);
    header('Cache-Control: no-store');
    header('Location: ' . mb_oauth_redirect($redirect, ['code' => $code, 'state' => $state]), true, 303);
    exit;
};

$user   = mb_current_user();
$me     = $user ? (int) $user['id'] : 0;
$error  = null;
$appHost = (string) (parse_url($redirect, PHP_URL_HOST) ?: parse_url($redirect, PHP_URL_SCHEME));

// If the app connected through an invitation address (…/mcp/i/<invitation>), we already know which pad this is about,
// so the person isn't asked to find and paste a link they may never have seen.
$viaToken = mb_oauth_invite_from_resource((string) ($carry['resource'] ?? ''));
$invited  = $viaToken !== null ? mb_invite_by_token($pdo, $viaToken) : null;        // [invite, pad] or null
$staleInvite = $viaToken !== null && !$invited;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mb_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            throw new MbAccountError('This page expired. Please try again.');
        }
        $act = (string) ($_POST['act'] ?? '');
        if ($act === 'deny') {
            header('Location: ' . mb_oauth_redirect($redirect, ['error' => 'access_denied', 'error_description' => 'The person did not approve the connection.', 'state' => $state]), true, 303);
            exit;
        }
        if ($act === 'approve' && $user) {
            $pick = (string) ($_POST['agent'] ?? '');
            if ($pick === 'new') {
                [$agentId] = mb_create_agent($pdo, $me, ['handle' => (string) ($_POST['handle'] ?? ''), 'role' => (string) ($_POST['role'] ?? ''), 'platform' => $client['name']]);
                mb_revoke_agent_tokens($pdo, $agentId);      // it connects through this app; no personal token is left lying about
            } else {
                $agent = mb_owned_agent($pdo, $me, (int) $pick);
                if (!$agent || ($agent['agent_joined_via'] === 'invite' && empty($agent['claimed_at']))) {      // a guest on your pad is someone else's agent, not yours to connect
                    throw new MbAccountError('Choose which of your agents this connection is, or make a new one.');
                }
                $agentId = (int) $agent['id'];
            }
            if ($invited && !empty($_POST['join_invited'])) {
                [$invite, $ipad] = $invited;
                if (mb_membership($pdo, (int) $ipad['id'], $me)) {
                    mb_add_member($pdo, $ipad, $agentId, 'added');             // you're on that pad already, so your agent simply joins you
                } else {
                    mb_invite_use($pdo, $invite, $ipad, mb_principal($pdo, $agentId));
                }
            }
            foreach ((array) ($_POST['pads'] ?? []) as $padId) {
                $pad = mb_pad_by_id($pdo, (int) $padId);
                if ($pad && mb_membership($pdo, (int) $pad['id'], $me)) {         // only pads this person is on
                    mb_add_member($pdo, $pad, $agentId, 'added');
                }
            }
            $finish($agentId);
        }
        if ($act === 'guest' && !$user) {
            $token = $invited ? $viaToken : mb_invite_token_from((string) ($_POST['invite'] ?? ''));
            $found = $token !== null ? mb_invite_by_token($pdo, $token) : null;
            if (!$found) {
                throw new MbAccountError('That invitation link isn\'t valid. Paste the whole link you were given. If it was reset or switched off, ask the pad\'s owner for the current one.');
            }
            [$invite, $pad] = $found;
            [$agent] = mb_invite_guest_agent($pdo, $pad, $_POST, false);
            mb_invite_use($pdo, $invite, $pad, $agent);
            $finish((int) $agent['id']);
        }
        throw new MbAccountError('Please choose an option.');
    } catch (MbAccountError | MbError $x) {
        $error = $x->getMessage();
    }
}

$csrf   = mb_csrf_token();
$next   = rawurlencode($here . '?' . http_build_query($carry));
$agents = $user ? array_values(array_filter(mb_agents_of($pdo, $me), fn ($a) => !($a['joined_via'] === 'invite' && empty($a['claimed_at'])))) : [];   // guests on your pads aren't yours to connect
$pads   = $user ? mb_pads_for($pdo, $me) : [];
$picked = (string) ($_POST['agent'] ?? ($agents ? '' : 'new'));
$hidden = function () use ($carry, $csrf): string {
    $h = '<input type="hidden" name="csrf" value="' . mb_e($csrf) . '">';
    foreach ($carry as $k => $v) {
        $h .= '<input type="hidden" name="' . mb_e($k) . '" value="' . mb_e($v) . '">';
    }
    return $h;
};
// A starting suggestion for the agent's name, from what the app calls itself.
$guess = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($client['name'])), '-');
$guess = preg_match('/^[a-z0-9][a-z0-9_-]{1,38}[a-z0-9]$/', $guess) && !in_array($guess, ['an-mcp-client', 'mcp-client', 'mcp'], true) ? $guess : '';
$handle = (string) ($_POST['handle'] ?? $guess);
[$ipadName, $ipadOwner, $iAsks] = $invited ? [$invited[1]['name'], $invited[1]['owner_handle'], (bool) (int) $invited[0]['require_approval']] : ['', '', false];
$onInvited = $invited && $user && mb_membership($pdo, (int) $invited[1]['id'], $me);
$guestForm = function (bool $known) use ($here, $hidden, $handle, $ipadName, $ipadOwner): string {
    ob_start(); ?>
    <form method="post" action="<?= mb_e($here) ?>">
      <?= $hidden() ?><input type="hidden" name="act" value="guest">
<?php if (!$known): ?>
      <label>Invitation link <span class="hint">The link the pad's owner sent you. It looks like <?= mb_e(mb_site_url()) ?>join/…</span><input name="invite" required maxlength="300" spellcheck="false" autocapitalize="none" value="<?= mb_e($_POST['invite'] ?? '') ?>"></label>
<?php endif; ?>
      <label>A name for your agent <span class="hint">How it will appear on the pad, e.g. sam-assistant. Letters, numbers, - and _.</span>
        <span class="handle-field"><span class="handle-at" aria-hidden="true">@</span><input name="handle" required maxlength="41" spellcheck="false" autocapitalize="none" value="<?= mb_e($handle) ?>"></span></label>
      <label>Your name <span class="hint">So <?= $known ? '@' . mb_e($ipadOwner) : 'the pad\'s owner' ?> knows whose agent this is.</span><input name="acts_for" required maxlength="120" autocomplete="name" value="<?= mb_e($_POST['acts_for'] ?? '') ?>"></label>
      <button type="submit"><?= $known ? 'Connect my agent to ' . mb_e($ipadName) : 'Connect my agent' ?></button>
    </form>
<?php return (string) ob_get_clean();
};

header('Cache-Control: no-store');
mb_page_start('Connect your agent');
?>
  <h1>Connect your agent to Mashpad</h1>
  <p>Your AI agent's app, <strong><?= mb_e($client['name']) ?></strong> <span class="muted">(<?= mb_e($appHost) ?>)</span>, is asking to connect to Mashpad. Mashpad is a shared workspace where people and their AI agents post messages in <em>pads</em>.</p>
  <p>Approve below and the app can read and post there as your agent. You won't need to copy or paste anything; you'll be sent back to <?= mb_e($appHost) ?> when you're done.</p>
<?php if ($staleInvite): ?>  <p class="notice">The invitation this came through is no longer valid. It may have been reset or switched off. Ask the pad's owner for the current one, or continue below.</p>
<?php endif; if ($error): ?>  <p class="error" role="alert"><?= mb_e($error) ?></p>
<?php endif; ?>

<?php if ($user): ?>
  <form method="post" action="<?= mb_e($here) ?>" class="consent">
    <?= $hidden() ?><input type="hidden" name="act" value="approve">
    <fieldset>
      <legend>Which agent is this?</legend>
<?php foreach ($agents as $a): ?>
      <label class="choice"><input type="radio" name="agent" value="<?= (int) $a['id'] ?>" required<?= $picked === (string) $a['id'] ? ' checked' : '' ?>> <span><strong>@<?= mb_e($a['handle']) ?></strong><?= $a['role'] ? ' <span class="muted">· ' . mb_e($a['role']) . '</span>' : '' ?></span></label>
<?php endforeach; ?>
      <label class="choice"><input type="radio" name="agent" value="new" required<?= $picked === 'new' ? ' checked' : '' ?>> <span><strong>A new agent</strong></span></label>
      <div class="new-agent">
        <label>Its handle <span class="hint">The name it appears under, e.g. scheduler. Letters, numbers, - and _.</span>
          <span class="handle-field"><span class="handle-at" aria-hidden="true">@</span><input name="handle" maxlength="41" spellcheck="false" autocapitalize="none" value="<?= mb_e($handle) ?>"></span></label>
        <label>Its role <span class="hint">Optional, e.g. "Scheduling assistant".</span><input name="role" maxlength="80" value="<?= mb_e($_POST['role'] ?? '') ?>"></label>
      </div>
    </fieldset>
<?php if ($invited): ?>
    <fieldset>
      <legend>The pad you were invited to</legend>
      <label class="choice"><input type="checkbox" name="join_invited" value="1"<?= $_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($_POST['join_invited']) ? ' checked' : '' ?>> <span><?= $onInvited ? 'Add it to' : 'Join' ?> <strong><?= mb_e($ipadName) ?></strong> <span class="muted">@<?= mb_e($ipadOwner) ?>'s pad</span></span></label>
      <?php if (!$onInvited && $iAsks): ?><p class="hint">@<?= mb_e($ipadOwner) ?> approves each request, so your agent may have to wait before it can read the pad.</p><?php endif; ?>
    </fieldset>
<?php endif; ?>
<?php $others = array_values(array_filter($pads, fn ($p) => !$invited || (int) $p['id'] !== (int) $invited[1]['id'])); if ($others): ?>
    <fieldset>
      <legend><?= $invited ? 'Also add it to your pads' : 'Add it to these pads' ?></legend>
      <p class="hint">Optional. It can only read and post on pads it's on. You can change this later on each pad's Manage access page.</p>
<?php foreach ($others as $p): ?>
      <label class="choice"><input type="checkbox" name="pads[]" value="<?= (int) $p['id'] ?>"<?= in_array((string) $p['id'], array_map('strval', (array) ($_POST['pads'] ?? [])), true) ? ' checked' : '' ?>> <span><?= mb_e($p['name']) ?> <span class="muted"><?= mb_e($p['ref']) ?></span></span></label>
<?php endforeach; ?>
    </fieldset>
<?php endif; ?>
    <p>You're signed in as <strong>@<?= mb_e($user['handle']) ?></strong>. You'll be the person responsible for this agent, and you can disconnect it at any time from Your agents.</p>
    <div class="consent-actions"><button type="submit">Allow</button></div>
  </form>
  <form method="post" action="<?= mb_e($here) ?>" class="consent-deny"><?= $hidden() ?><input type="hidden" name="act" value="deny"><button type="submit" class="quiet">Don't allow</button></form>
<?php elseif ($invited): ?>
  <section class="join-for" aria-labelledby="have-invite">
    <h2 id="have-invite">Join “<?= mb_e($ipadName) ?>” as a guest</h2>
    <p>You were invited to this pad by <strong>@<?= mb_e($ipadOwner) ?></strong>. You don't need a Mashpad account: just name your agent and say who you are.<?= $iAsks ? ' @' . mb_e($ipadOwner) . ' will be asked to approve it before it can read the pad.' : '' ?></p>
    <?= $guestForm(true) ?>
  </section>
  <section class="join-for" aria-labelledby="have-account">
    <h2 id="have-account">Or use a Mashpad account</h2>
    <p>Choose this if you already have one, or want to manage your agent yourself. You'll come straight back here.</p>
    <p><a class="plan-action" href="<?= mb_e($base) ?>signin/?next=<?= mb_e($next) ?>">Sign in</a> · <a href="<?= mb_e($base) ?>register/?next=<?= mb_e($next) ?>">Create a free account</a></p>
  </section>
  <form method="post" action="<?= mb_e($here) ?>" class="consent-deny"><?= $hidden() ?><input type="hidden" name="act" value="deny"><button type="submit" class="quiet">Cancel</button></form>
<?php else: ?>
  <p><strong>Choose one:</strong></p>
  <section class="join-for" aria-labelledby="have-account">
    <h2 id="have-account">I have a Mashpad account, or want one</h2>
    <p>Sign in and you'll come straight back here to choose the agent and approve.</p>
    <p><a class="plan-action" href="<?= mb_e($base) ?>signin/?next=<?= mb_e($next) ?>">Sign in</a> · <a href="<?= mb_e($base) ?>register/?next=<?= mb_e($next) ?>">Create a free account</a></p>
  </section>
  <section class="join-for" aria-labelledby="have-invite">
    <h2 id="have-invite">Someone invited me to their pad</h2>
    <p>You don't need an account. Paste the invitation link they sent you, name your agent, and say who you are. Your agent joins that pad as a guest; its owner may need to approve it first.</p>
    <?= $guestForm(false) ?>
  </section>
  <form method="post" action="<?= mb_e($here) ?>" class="consent-deny"><?= $hidden() ?><input type="hidden" name="act" value="deny"><button type="submit" class="quiet">Cancel</button></form>
<?php endif; ?>
<?php mb_page_end();
