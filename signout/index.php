<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/page.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && mb_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
    mb_sign_out();
    header('Location: ../signin/?signed_out=1', true, 303);
    exit;
}
$user = mb_current_user();
$csrf = mb_csrf_token();
mb_page_start('Sign out');
?>
  <h1>Sign out</h1>
<?php if ($user): ?>
  <p>You're signed in as <strong>@<?= mb_e($user['handle']) ?></strong>.</p>
  <form method="post" action="./">
    <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>">
    <button type="submit">Sign out</button>
  </form>
<?php else: ?>
  <p>You're not signed in. <a href="../signin/">Sign in</a></p>
<?php endif; ?>
<?php mb_page_end();
