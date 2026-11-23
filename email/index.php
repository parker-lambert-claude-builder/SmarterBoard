<?php
/**
 * Email preferences and unsubscribe.
 *
 * Reached two ways: signed in (your own preferences), or through the link at the foot of a
 * notification, which carries a token and needs no sign-in. A POST from a mail app's own
 * Unsubscribe button (List-Unsubscribe=One-Click) switches the notifications off at once.
 * Opening the link in a browser shows a button instead of acting, because mail scanners
 * open links before a person ever sees them.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/notify.php';
require_once __DIR__ . '/../src/page.php';

$base  = mb_base_path();
$pdo   = null;
try { $pdo = mb_db(); } catch (Throwable $t) { /* handled below */ }
$token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$via   = $pdo && $token !== '' ? mb_email_prefs_by_token($pdo, $token) : null;
$user  = $pdo ? mb_current_user() : null;

// A mail app's one-click unsubscribe: no cookies, no form token, just the address and this body.
if ($pdo && $via && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
    mb_email_set_requests($pdo, (int) $via['principal_id'], false);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unsubscribed. Mashpad won't email this address about requests to join.\n";
    exit;
}

$who   = $via ? (int) $via['principal_id'] : ($user ? (int) $user['id'] : 0);
$note  = null;
$error = null;
if ($pdo && $who && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // With a link token the token itself is the proof; signed in, the usual form protection applies.
    if (!$via && !mb_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
        $error = 'This page expired. Please try again.';
    } else {
        $on = ($_POST['requests'] ?? '') === 'on';
        mb_email_set_requests($pdo, $who, $on);
        $note = $on ? 'Saved. Mashpad will email you when someone asks to join one of your pads.' : 'Saved. Mashpad won\'t email you about requests to join. You\'ll still see them on the pad.';
    }
}
$prefs = $pdo && $who ? mb_email_prefs($pdo, $who) : null;
$on    = $prefs ? (bool) (int) $prefs['notify_requests'] : true;
$here  = $base . 'email/' . ($via ? '?t=' . rawurlencode($token) : '');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
mb_page_start('Email settings');
?>
<div class="form-page">
  <h1>Email settings</h1>
<?php if (!$who): ?>
  <p><?= $token !== '' ? 'That link isn\'t valid. Sign in to change what Mashpad emails you.' : 'Sign in to choose what Mashpad emails you.' ?></p>
  <p><a href="<?= mb_e($base) ?>signin/?next=<?= mb_e(rawurlencode($base . 'email/')) ?>">Sign in</a></p>
<?php else: ?>
  <p>For <strong>@<?= mb_e($via ? $via['handle'] : $user['handle']) ?></strong>.</p>
  <?php if ($note): ?><p class="notice" role="status"><?= mb_e($note) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="error" role="alert"><?= mb_e($error) ?></p><?php endif; ?>
  <h2>Requests to join your pads</h2>
  <p><?= $on ? 'These emails are <strong>on</strong>.' : 'These emails are <strong>off</strong>. Requests still appear on the pad and on its Manage access page.' ?></p>
  <form method="post" action="<?= mb_e($here) ?>">
    <input type="hidden" name="csrf" value="<?= mb_e(mb_csrf_token()) ?>"><?php if ($via): ?><input type="hidden" name="t" value="<?= mb_e($token) ?>"><?php endif; ?>
    <input type="hidden" name="requests" value="<?= $on ? 'off' : 'on' ?>">
    <button type="submit"><?= $on ? 'Stop these emails' : 'Turn these emails back on' ?></button>
  </form>
  <h2>Email you'll always get</h2>
  <p>A password-reset code, when you ask for one. That's the only other email Mashpad sends.</p>
<?php endif; ?>
</div>
<?php mb_page_end();
