<?php
/**
 * Admin: the scheduled job. Makes the key a third-party cron service uses to call cron/, shows when the job last ran and what it did,
 * and can run it now. Administrators only; to everyone else this page does not exist.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/cron.php';
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

$base   = mb_base_path();
$error  = null;
$newKey = null;
$ranNow = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mb_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            throw new MbAccountError('Your session form expired. Please try again.');
        }
        switch ((string) ($_POST['act'] ?? '')) {
            case 'key':
                $newKey = mb_cron_key_create($pdo, (int) $user['id']);      // shown below, once
                break;
            case 'run':
                $ranNow = mb_cron_run($pdo, 'admin');
                break;
            default:
                throw new MbAccountError('Unknown action.');
        }
    } catch (MbAccountError $x) {
        $error = $x->getMessage();
    }
}

$csrf   = mb_csrf_token();
$origin = (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$url    = $origin . $base . 'cron/';
$status = mb_cron_status($pdo);
$has    = mb_cron_key_exists($pdo);
$ago    = fn (int $t) => $t > time() - 90 ? 'just now' : (($d = time() - $t) < 3600 ? intdiv($d, 60) . ' minutes ago' : ($d < 172800 ? intdiv($d, 3600) . ' hours ago' : intdiv($d, 86400) . ' days ago'));

mb_page_start('Admin · Cron', 'admin', ['section' => 'cron']);
?>
<div class="manage">
  <h1>Cron</h1>
  <p>Mashpad has one scheduled job. It sends the wake-ups that are due (retries, and any queued while the server was busy), tidies the wake-up log, and deletes private messages older than their plan keeps them. It can run every minute and can overlap itself safely. Run it from your host's cron with <code>php /path/to/site/bin/wake-cron.php</code>, or have a cron service call the address below.</p>
<?php if ($error): ?><p class="error" role="alert"><?= mb_e($error) ?></p><?php endif; ?>

  <section aria-labelledby="last-h" class="access-section">
    <h2 id="last-h">When it last ran</h2>
<?php if (!$status): ?>
    <p>It has never run. Once a cron service calls it, or you run it below, this shows when and what it did.</p>
<?php else: ?>
    <p><?= $status['ok'] ? 'Ran ' . mb_e($ago((int) $status['ran_at'])) : 'Failed ' . mb_e($ago((int) $status['ran_at'])) ?> (<?= mb_e(gmdate('j M Y, H:i', (int) $status['ran_at'])) ?> UTC), <?= ($status['via'] ?? '') === 'web' ? 'called over the web' : (($status['via'] ?? '') === 'admin' ? 'run from this page' : 'run from the server\'s cron') ?>.
<?php if (!empty($status['skipped'])): ?>That run found another already going and did nothing.<?php elseif ($status['ok']): ?>It sent <?= (int) $status['sent'] ?> wake-up<?= (int) $status['sent'] === 1 ? '' : 's' ?> (<?= (int) $status['retry'] ?> to retry, <?= (int) $status['failed'] ?> failed), tidied <?= (int) $status['pruned'] ?> log entr<?= (int) $status['pruned'] === 1 ? 'y' : 'ies' ?> and deleted <?= (int) $status['messages_pruned'] ?> old private message<?= (int) $status['messages_pruned'] === 1 ? '' : 's' ?>, in <?= (int) ($status['ms'] ?? 0) ?> ms.<?php else: ?><?= mb_e((string) ($status['error'] ?? '')) ?><?php endif; ?></p>
<?php if ((int) $status['ran_at'] < time() - 600): ?>
    <p class="notice" role="status">It hasn't run for more than ten minutes. Wake-ups are still sent right after the post that causes them, but retries wait for this job.</p>
<?php endif; ?>
<?php endif; ?>
<?php if ($ranNow): ?><p class="notice" role="status"><?= !empty($ranNow['ok']) ? 'Ran it now: ' . (int) ($ranNow['sent'] ?? 0) . ' wake-ups sent.' : mb_e((string) $ranNow['error']) ?></p><?php endif; ?>
    <form method="post" action="<?= mb_e($base) ?>admin/cron/" class="inline-form"><input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="act" value="run"><button type="submit" class="quiet">Run it now</button></form>
  </section>

  <section aria-labelledby="key-h" class="access-section">
    <h2 id="key-h">Use a cron service</h2>
<?php if ($newKey): ?>
    <p class="notice" role="status">Here is your key. <strong>It is shown only this once</strong>: copy it now. Making another key replaces this one, and the old one stops working.</p>
    <p>Key<br><code id="cron-key"><?= mb_e($newKey) ?></code></p>
    <p>Address, with the key in a header (best, since addresses are logged by servers along the way)<br><code id="cron-url"><?= mb_e($url) ?></code><br>Header: <code>Authorization: Bearer <?= mb_e($newKey) ?></code></p>
    <p>Or, if the service can only call an address<br><code id="cron-url-key"><?= mb_e($url . '?key=' . $newKey) ?></code></p>
<?php elseif ($has): ?>
    <p>A key exists. It was shown once when it was made and can't be shown again; only a fingerprint is kept. If you've lost it, make a new one: the old one stops working.</p>
    <p>Address: <code><?= mb_e($url) ?></code></p>
<?php else: ?>
    <p>There is no key yet. Make one to let a cron service call the job.</p>
<?php endif; ?>
    <form method="post" action="<?= mb_e($base) ?>admin/cron/" class="inline-form"><input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="act" value="key"><button type="submit"><?= $has ? 'Make a new key' : 'Make a key' ?></button></form>
    <h3>Setting up the service</h3>
    <ul>
      <li>Address: the one above, called with <strong>GET</strong> (POST works too), <strong>every minute</strong>. If it can't go that often, every five minutes is fine.</li>
      <li>Send the key in an <strong>Authorization</strong> header as <code>Bearer</code> and the key, if the service lets you set headers. If not, use the address with <code>?key=</code>.</li>
      <li>A good answer is <strong>200</strong> with a small summary. Anything else (a wrong key, or plain http) is a <strong>404</strong>, on purpose.</li>
      <li>Set a timeout of about 30 seconds, and ask the service to tell you when it fails.</li>
      <li>The address only works over https. Treat the key like a password: anyone who has it can run the job, though all it can do is the job itself.</li>
    </ul>
  </section>
</div>
<?php mb_page_end();
