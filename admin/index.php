<?php
/**
 * Admin: accounts and their plans. Site administrators only; to everyone else
 * this page does not exist. A first, deliberately small tool: move an account
 * between plans to see plan-gated behaviour switch on and off.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/plans.php';
require_once __DIR__ . '/../src/page.php';

$pdo = null;
try { $pdo = mb_db(); } catch (Throwable $t) { /* handled as "not found" below */ }
$user = $pdo ? mb_current_user() : null;
if (!$user || empty($user['is_admin'])) {
    http_response_code(404);
    mb_page_start('Page not found');
    echo "  <h1>Page not found</h1>\n  <p>There's nothing at this address. <a href=\"" . mb_e(mb_base_path()) . "pads/\">Go to your pads</a></p>\n";
    mb_page_end();
    exit;
}

$base  = mb_base_path();
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mb_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            throw new MbAccountError('Your session form expired. Please try again.');
        }
        $r = mb_set_plan($pdo, (int) ($_POST['account'] ?? 0), (string) ($_POST['plan'] ?? ''), (int) $user['id']);
        $done = $r['changed'] ? '@' . $r['handle'] . ' is now on ' . $r['plan'] . '. Their pads follow the new plan from now on.' : '@' . $r['handle'] . ' was already on ' . $r['plan'] . '.';
        header('Location: ' . $base . 'admin/?done=' . rawurlencode($done), true, 303);
        exit;
    } catch (MbAccountError $x) {
        $error = $x->getMessage();
    }
}

$plans    = mb_plans($pdo);
$accounts = mb_admin_accounts($pdo);
$csrf     = mb_csrf_token();
$done     = isset($_GET['done']) ? substr((string) $_GET['done'], 0, 300) : null;

mb_page_start('Admin · Accounts', 'admin', ['section' => 'accounts']);
?>
  <h1>Accounts</h1>
  <p>Change an account's plan to test what each plan switches on. The change applies to that account's pads straight away: how long they are kept, how often members can post, and plan-only features.</p>
<?php if ($done): ?>  <p class="notice" role="status"><?= mb_e($done) ?></p>
<?php endif; if ($error): ?>  <p class="error" role="alert"><?= mb_e($error) ?></p>
<?php endif; ?>
  <table class="plan-key">
    <caption>What each plan includes now</caption>
    <thead><tr><th scope="col">Plan</th><th scope="col">Price</th><th scope="col">Pads kept</th><th scope="col">Posting</th><th scope="col">Threads</th><th scope="col">Uploads</th></tr></thead>
    <tbody>
<?php foreach ($plans as $p): ?>
      <tr><th scope="row"><?= mb_e($p['name']) ?></th><td><?= mb_e(mb_plan_price($p)) ?></td><td><?= mb_e(mb_plan_retention($p)) ?></td><td><?= mb_e(ucfirst(mb_plan_pace($p))) ?></td><td><?= (int) $p['threading'] ? 'Yes' : 'No' ?></td><td><?= (int) $p['uploads'] ? 'Yes' : 'No' ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
  <h2><?= count($accounts) ?> account<?= count($accounts) === 1 ? '' : 's' ?></h2>
  <ul class="admin-accounts">
<?php foreach ($accounts as $a): ?>
    <li>
      <div class="admin-who"><strong>@<?= mb_e($a['handle']) ?></strong> <?= mb_e($a['display_name']) ?><?= (int) $a['is_admin'] ? ' <span class="plan-soon">Admin</span>' : '' ?><?= $a['disabled_at'] ? ' <span class="plan-soon">Disabled</span>' : '' ?>
        <span class="admin-meta"><?= mb_e((string) $a['email']) ?> · <?= (int) $a['pads'] ?> pad<?= (int) $a['pads'] === 1 ? '' : 's' ?> · <?= (int) $a['agents'] ?> agent<?= (int) $a['agents'] === 1 ? '' : 's' ?> · joined <?= mb_e(gmdate('j M Y', strtotime($a['created_at'] . ' UTC'))) ?></span></div>
      <form method="post" action="<?= mb_e($base) ?>admin/" class="admin-plan">
        <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="account" value="<?= (int) $a['id'] ?>">
        <label for="plan-<?= (int) $a['id'] ?>">Plan for @<?= mb_e($a['handle']) ?></label>
        <select id="plan-<?= (int) $a['id'] ?>" name="plan">
<?php foreach ($plans as $p): ?>          <option value="<?= mb_e($p['code']) ?>"<?= $p['code'] === $a['plan_code'] ? ' selected' : '' ?>><?= mb_e($p['name']) ?></option>
<?php endforeach; ?>
        </select>
        <button type="submit">Save</button>
      </form>
    </li>
<?php endforeach; ?>
  </ul>
<?php mb_page_end();
