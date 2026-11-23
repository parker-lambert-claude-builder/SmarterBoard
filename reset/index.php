<?php
/**
 * Forgotten password. Step one: ask for a link by email. Step two (the link): choose a new password.
 * The first step answers the same way whether or not the address has an account.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/notify.php';
require_once __DIR__ . '/../src/page.php';

$base  = mb_base_path();
$here  = $base . 'reset/';
$error = null;
$sent  = false;
$pdo   = null;
try { $pdo = mb_db(); } catch (Throwable $t) { $error = 'This page is unavailable right now. Please try again shortly.'; }
$token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$link  = $pdo && $token !== '' ? mb_reset_lookup($pdo, $token) : null;

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mb_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            throw new MbAccountError('This page expired. Please try again.');
        }
        if ($token !== '') {
            if ((string) ($_POST['password'] ?? '') !== (string) ($_POST['password2'] ?? '')) {
                throw new MbAccountError('The two passwords don\'t match. Type the new password again in both boxes.');
            }
            mb_reset_complete($pdo, $token, (string) ($_POST['password'] ?? ''));
            header('Location: ' . $base . 'signin/?reset=1', true, 303);
            exit;
        }
        mb_reset_request($pdo, (string) ($_POST['email'] ?? ''));
        $sent = true;
    } catch (MbAccountError $x) {
        $error = $x->getMessage();
    }
}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');          // the link's token must not travel in a Referer header
$csrf = mb_csrf_token();
mb_page_start($token !== '' ? 'Choose a new password' : 'Reset your password');
?>
<div class="form-page">
<?php if ($token !== '' && !$link && !$error): ?>
  <h1>This link has expired</h1>
  <p>A reset link works once and for one hour. Ask for a new one and use it straight away.</p>
  <p><a href="<?= mb_e($here) ?>">Send me a new link</a></p>
<?php elseif ($token !== ''): ?>
  <h1>Choose a new password</h1>
  <?php if ($link): ?><p>For the account <strong>@<?= mb_e($link['handle']) ?></strong>. Changing it signs that account out everywhere else.</p><?php endif; ?>
  <?php if ($error): ?><p class="error" role="alert"><?= mb_e($error) ?></p><?php endif; ?>
  <?php if ($link): ?>
  <form method="post" action="<?= mb_e($here) ?>">
    <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="t" value="<?= mb_e($token) ?>">
    <label>New password <span class="hint">At least <?= MB_PASSWORD_MIN ?> characters. A few unrelated words works well.</span><input name="password" type="password" required minlength="<?= MB_PASSWORD_MIN ?>" autocomplete="new-password"></label>
    <label>New password, again <input name="password2" type="password" required autocomplete="new-password"></label>
    <button type="submit">Save new password</button>
  </form>
  <?php else: ?><p><a href="<?= mb_e($here) ?>">Send me a new link</a></p><?php endif; ?>
<?php elseif ($sent): ?>
  <h1>Check your email</h1>
  <p>If <strong><?= mb_e(trim((string) ($_POST['email'] ?? ''))) ?></strong> has a Mashpad account, a link to choose a new password is on its way. It works once and for one hour.</p>
  <p>If it doesn't arrive in a few minutes, look in your spam folder, and check the address is the one you signed up with.</p>
  <p><a href="<?= mb_e($base) ?>signin/">Back to sign in</a></p>
<?php else: ?>
  <h1>Reset your password</h1>
  <p>Enter the email address you signed up with and we'll send a link to choose a new password.</p>
  <?php if ($error): ?><p class="error" role="alert"><?= mb_e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= mb_e($here) ?>">
    <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>">
    <label>Email <input name="email" type="email" required autocomplete="email" value="<?= mb_e($_POST['email'] ?? '') ?>"></label>
    <button type="submit">Send me a link</button>
  </form>
  <p><a href="<?= mb_e($base) ?>signin/">Back to sign in</a></p>
<?php endif; ?>
</div>
<?php mb_page_end();
