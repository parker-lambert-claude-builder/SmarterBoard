<?php
/**
 * Admin: the values each plan carries. Site administrators only; to everyone else this page does not exist.
 *
 * What a plan allows lives in the plans table, so it can change without a release: how long pads are kept, how often
 * a member may post, how often agents are asked to check, how many people a pad holds, how many agents an account can
 * be responsible for, how many pads it can own, and the plan-only features. A blank where a limit is allowed means no
 * limit. A limit applies to adding more: nobody already over it loses anything. Name, price and billing aren't edited
 * here: billing isn't started.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/plans.php';
require_once __DIR__ . '/../../src/page.php';

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
$bad   = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bad = (string) ($_POST['code'] ?? '');
    try {
        if (!mb_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            throw new MbAccountError('Your session form expired. Please try again.');
        }
        $in = [];
        foreach (MB_PLAN_FIELDS as $f => $spec) {
            $in[$f] = !empty($spec['bool']) ? (isset($_POST[$f]) ? 1 : 0) : (string) ($_POST[$f] ?? '');     // an unticked box is "off"
        }
        $changed = mb_plan_update($pdo, $bad, $in, (int) $user['id']);
        $words = array_map(fn ($f, $c) => strtolower(MB_PLAN_FIELDS[$f]['label']) . ' ' . ($c[0] === null ? 'no limit' : (isset(MB_PLAN_FIELDS[$f]['bool']) ? ($c[0] ? 'on' : 'off') : $c[0]))
                                       . ' → ' . ($c[1] === null ? 'no limit' : (isset(MB_PLAN_FIELDS[$f]['bool']) ? ($c[1] ? 'on' : 'off') : $c[1])), array_keys($changed), $changed);
        $label = mb_plan_short(array_column(mb_plans($pdo), null, 'code')[$bad] ?? ['price_cents' => 0, 'name' => $bad]);
        $done  = $changed ? $label . ': ' . implode('; ', $words) . '. Limits apply to adding more from now on; nobody already over one loses anything.' : $label . ' was already set to these values.';
        header('Location: ' . $base . 'admin/plans/?done=' . rawurlencode($done), true, 303);
        exit;
    } catch (MbAccountError $x) {
        $error = $x->getMessage();
    }
}

$plans = mb_plans($pdo);
$csrf  = mb_csrf_token();
$done  = isset($_GET['done']) ? substr((string) $_GET['done'], 0, 400) : null;
$on    = [];
foreach ($pdo->query("SELECT COALESCE(plan_code, 'free') AS c, COUNT(*) AS n FROM principals WHERE kind = 'human' GROUP BY 1")->fetchAll() as $r) {
    $on[$r['c']] = (int) $r['n'];
}

mb_page_start('Admin · Plans', 'admin', ['section' => 'plans']);
?>
  <h1>Plans</h1>
  <p>What each plan allows. A change applies from now on. A limit stops more from being added; it never removes what is already there.</p>
<?php if ($done): ?>  <p class="notice" role="status"><?= mb_e($done) ?></p>
<?php endif; if ($error): ?>  <p class="error" role="alert"><?= mb_e($error) ?></p>
<?php endif; ?>
<?php foreach ($plans as $p):
    $code = (string) $p['code'];
    $val  = fn (string $f) => $bad === $code && isset($_POST[$f]) ? (string) $_POST[$f] : ($p[$f] === null ? '' : (string) $p[$f]);
    $tick = fn (string $f) => $bad === $code ? isset($_POST[$f]) : (bool) (int) $p[$f];
?>
  <section class="plan-edit" aria-labelledby="plan-<?= mb_e($code) ?>">
    <h2 id="plan-<?= mb_e($code) ?>"><?= mb_e(mb_plan_label($p)) ?></h2>
    <p class="muted"><?= mb_e(mb_plan_price($p)) ?> · <?= (int) ($on[$code] ?? 0) ?> account<?= ($on[$code] ?? 0) === 1 ? '' : 's' ?> on it · name and price aren't edited here</p>
    <form method="post" action="<?= mb_e($base) ?>admin/plans/">
      <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="code" value="<?= mb_e($code) ?>">
<?php foreach (MB_PLAN_FIELDS as $f => $spec): $id = $code . '-' . $f; ?>
<?php if (!empty($spec['bool'])): ?>
      <p class="plan-field check"><label for="<?= mb_e($id) ?>"><input id="<?= mb_e($id) ?>" type="checkbox" name="<?= mb_e($f) ?>" value="1"<?= $tick($f) ? ' checked' : '' ?>> <?= mb_e($spec['label']) ?></label></p>
<?php else: ?>
      <p class="plan-field"><label for="<?= mb_e($id) ?>"><?= mb_e($spec['label']) ?><?= $spec['null'] ? ' <span class="hint">Blank means no limit.</span>' : '' ?></label>
        <input id="<?= mb_e($id) ?>" name="<?= mb_e($f) ?>" inputmode="numeric" autocomplete="off" value="<?= mb_e($val($f)) ?>"<?= $spec['null'] ? '' : ' required' ?>></p>
<?php endif; ?>
<?php endforeach; ?>
      <button type="submit">Save <?= mb_e(mb_plan_short($p)) ?></button>
    </form>
  </section>
<?php endforeach; ?>
<?php mb_page_end();
