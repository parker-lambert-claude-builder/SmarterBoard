<?php
/**
 * Forgotten password. Step one: ask for a code by email. Step two: type the six-digit code and a new
 * password. The email holds a code, never a link, because mail services rewrite links and can break them.
 * Step two is shown the same way whether or not the address has an account.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/notify.php';
require_once __DIR__ . '/../src/page.php';

$base  = mb_base_path();
$here  = $base . 'reset/';
$error = null;
$note  = null;
$step  = 'email';
$pdo   = null;
try { $pdo = mb_db(); } catch (Throwable $t) { $error = 'This page is unavailable right now. Please try again shortly.'; }
$email = trim((string) ($_POST['email'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['step'] ?? '') === 'code') {
    $step = 'code';            // the link in the email: straight to the code, with a box for the address
}

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['act'] ?? 'request');
    try {
        if (!mb_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            throw new MbAccountError('This page expired. Please try again.');
        }
        if ($act === 'complete') {
            $step = 'code';
            if ((string) ($_POST['password'] ?? '') !== (string) ($_POST['password2'] ?? '')) {
                throw new MbAccountError('The two passwords don\'t match. Type the new password again in both boxes.');
            }
            mb_reset_complete($pdo, $email, (string) ($_POST['code'] ?? ''), (string) ($_POST['password'] ?? ''));
            header('Location: ' . $base . 'signin/?reset=1', true, 303);
            exit;
        }
        mb_reset_request($pdo, $email);
        $step = 'code';
        $note = $act === 'again' ? 'If that address has an account, a new code is on its way. The earlier one no longer works.' : null;
    } catch (MbAccountError $x) {
        $error = $x->getMessage();
    }
}
header('Cache-Control: no-store');
$csrf = mb_csrf_token();
mb_page_start($step === 'code' ? 'Enter your code' : 'Reset your password');
$known = $email !== '';
?>
<div class="form-page">
<?php if ($step === 'code'): ?>
  <h1>Enter your code</h1>
  <p><?= $known ? 'If <strong>' . mb_e($email) . '</strong> has a Mashpad account, we\'ve emailed it a code.' : 'Enter your email address and the code we emailed you.' ?></p>
  <?php if ($note): ?><p class="notice" role="status"><?= mb_e($note) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="error" role="alert"><?= mb_e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= mb_e($here) ?>" class="reset-code-form">
    <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="act" value="complete">
<?php if ($known): ?>
    <input type="hidden" name="email" value="<?= mb_e($email) ?>">
<?php else: ?>
    <label>Email <input name="email" type="email" required autocomplete="email" autofocus></label>
<?php endif; ?>
    <label for="reset-code">Code</label>
    <div class="code-entry" data-code-entry>
      <input id="reset-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required<?= $known ? ' autofocus' : '' ?> value="<?= mb_e(preg_replace('/\D+/', '', (string) ($_POST['code'] ?? ''))) ?>">
      <div class="code-boxes" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span></div>
    </div>
    <label>New password <span class="hint">At least <?= MB_PASSWORD_MIN ?> characters.</span><input name="password" type="password" required minlength="<?= MB_PASSWORD_MIN ?>" autocomplete="new-password"></label>
    <label>New password, again <input name="password2" type="password" required autocomplete="new-password"></label>
    <button type="submit">Save new password</button>
  </form>
<?php if ($known): ?>
  <form method="post" action="<?= mb_e($here) ?>" class="reset-again">
    <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="act" value="again"><input type="hidden" name="email" value="<?= mb_e($email) ?>">
    <p>No email? Check your spam folder. <button type="submit" class="quiet">Send a new code</button></p>
  </form>
  <p><a href="<?= mb_e($here) ?>">Use a different address</a> · <a href="<?= mb_e($base) ?>signin/">Back to sign in</a></p>
<?php else: ?>
  <p>No email? Check your spam folder, or <a href="<?= mb_e($here) ?>">ask for a new code</a>. · <a href="<?= mb_e($base) ?>signin/">Back to sign in</a></p>
<?php endif; ?>
  <script src="<?= mb_e(mb_asset('code-entry.js')) ?>" defer></script>
<?php else: ?>
  <h1>Reset your password</h1>
  <p>Enter your email address and we'll send you a code.</p>
  <?php if ($error): ?><p class="error" role="alert"><?= mb_e($error) ?></p><?php endif; ?>
  <form method="post" action="<?= mb_e($here) ?>">
    <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="act" value="request">
    <label>Email <input name="email" type="email" required autocomplete="email" value="<?= mb_e($email) ?>"></label>
    <button type="submit">Send me a code</button>
  </form>
  <p><a href="<?= mb_e($base) ?>signin/">Back to sign in</a></p>
<?php endif; ?>
</div>
<?php mb_page_end();
