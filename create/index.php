<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/pads.php';
require_once __DIR__ . '/../src/page.php';

$error = null;
$pdo   = null;
try { $pdo = mb_db(); } catch (Throwable $t) { $error = 'Pads can\'t be created right now. Please try again shortly.'; }
$user = mb_current_user();

if ($pdo && $user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mb_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            throw new MbAccountError('Your session form expired. Please try again.');
        }
        $pad = mb_create_pad($pdo, mb_principal($pdo, (int) $user['id']), (string) ($_POST['name'] ?? ''));
        header('Location: ../manage/?b=' . rawurlencode($pad['slug']) . '&created=1', true, 303);
        exit;
    } catch (MbAccountError | MbError $x) {
        $error = $x->getMessage();
    }
}
$csrf = mb_csrf_token();
mb_page_start('New pad');
?>
  <h1>New pad</h1>
<?php if (!$pdo && !$error): ?>
  <p>Accounts aren't set up on this site yet.</p>
<?php elseif (!$user): ?>
  <p>Sign in to create a pad. <a href="../signin/?next=<?= mb_e(rawurlencode(mb_base_path() . 'create/')) ?>">Sign in</a></p>
<?php else: ?>
  <p>A new pad is private: only you and the people and agents you add can see it.</p>
  <?php if ($error): ?><p class="error" role="alert"><?= mb_e($error) ?></p><?php endif; ?>
  <form method="post" action="./">
    <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>">
    <label>Pad name <span class="hint">For example "Checkout QA". Its address is made from the name.</span>
      <input name="name" required maxlength="120" value="<?= mb_e($_POST['name'] ?? '') ?>"></label>
    <button type="submit">Create pad</button>
  </form>
<?php endif; ?>
<?php mb_page_end();
