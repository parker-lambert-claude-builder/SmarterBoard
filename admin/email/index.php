<?php
/**
 * Admin: outgoing email. Enter the Brevo API key and the address mail comes
 * from, and send yourself a test. Site administrators only; to everyone else
 * this page does not exist. The key is never shown again in full.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/mail.php';
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
$here   = $base . 'admin/email/';
$error  = null;
$notice = isset($_GET['done']) ? substr((string) $_GET['done'], 0, 400) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mb_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            throw new MbAccountError('Your session form expired. Please try again.');
        }
        $act = (string) ($_POST['act'] ?? '');
        if ($act === 'save') {
            $key   = trim((string) ($_POST['api_key'] ?? ''));
            $from  = trim((string) ($_POST['from_email'] ?? ''));
            $name  = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['from_name'] ?? '')));
            if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
                throw new MbAccountError('Enter the email address mail should come from. It must be a sender you have verified in Brevo.');
            }
            if ($name === '' || mb_ulen($name) > 80) {
                throw new MbAccountError('Enter a sender name of up to 80 characters, for example "Mashpad".');
            }
            $said = 'Saved.';
            if ($key !== '') {                                  // blank keeps the key already stored
                if (strlen($key) > 200 || preg_match('/\s/', $key)) {
                    throw new MbAccountError('That doesn\'t look like a Brevo API key. Paste the key exactly, with no spaces.');
                }
                $who = mb_brevo_account($key, mb_mail_config($pdo)['api']);
                if (!$who['ok'] && !str_starts_with($who['error'], 'Brevo couldn\'t be reached')) {
                    throw new MbAccountError('Not saved. ' . $who['error'] . ' Check the key in Brevo under SMTP & API, API keys.');
                }
                mb_set_setting($pdo, 'brevo_api_key', $key);
                mb_audit($pdo, (int) $user['id'], 'mail.key_set', null, mb_mask_key($key));
                $said = $who['ok'] ? 'Saved. Brevo recognises the key' . ($who['email'] !== '' ? ' as ' . $who['email'] : '') . '.'
                                   : 'Saved, but the key could not be checked: ' . $who['error'] . ' Send a test email to make sure it works.';
            } elseif (mb_setting($pdo, 'brevo_api_key') === '') {
                throw new MbAccountError('Paste your Brevo API key.');
            }
            mb_set_setting($pdo, 'mail_from_email', $from);
            mb_set_setting($pdo, 'mail_from_name', $name);
            header('Location: ' . $here . '?done=' . rawurlencode($said), true, 303);
            exit;
        }
        if ($act === 'test') {
            $to = trim((string) ($_POST['to'] ?? ''));
            $r  = mb_mail($pdo, $to, (string) $user['display_name'], 'Mashpad test email',
                          "This is a test from Mashpad's email settings.\n\nIf you're reading it, outgoing email works.\n\nSent at " . gmdate('Y-m-d H:i') . " UTC by @{$user['handle']}.");
            if (!$r['ok']) {
                throw new MbAccountError('The test wasn\'t sent. ' . $r['error']);
            }
            header('Location: ' . $here . '?done=' . rawurlencode('Brevo accepted a test email to ' . $to . '. Check that inbox, and the spam folder, in a minute or two.'), true, 303);
            exit;
        }
        if ($act === 'remove') {
            mb_set_setting($pdo, 'brevo_api_key', '');
            mb_audit($pdo, (int) $user['id'], 'mail.key_removed');
            header('Location: ' . $here . '?done=' . rawurlencode('The API key was removed. Mashpad can\'t send email until you add one.'), true, 303);
            exit;
        }
        throw new MbAccountError('Unknown action.');
    } catch (MbAccountError $x) {
        $error = $x->getMessage();
    }
}

$c    = mb_mail_config($pdo);
$csrf = mb_csrf_token();
$val  = fn (string $field, string $saved) => mb_e($error !== null && isset($_POST[$field]) ? (string) $_POST[$field] : $saved);

mb_page_start('Admin · Email', 'admin', ['section' => 'email']);
?>
  <h1>Email</h1>
  <p>Mashpad sends email through <strong>Brevo</strong>. <?= $c['ready'] ? 'It is set up: mail goes out as <strong>' . mb_e($c['from_name']) . ' &lt;' . mb_e($c['from_email']) . '&gt;</strong> with the key ending <strong>' . mb_e(mb_mask_key($c['api_key'])) . '</strong>.' : 'It isn\'t set up yet, so nothing can be sent.' ?></p>
<?php if ($notice): ?>  <p class="notice" role="status"><?= mb_e($notice) ?></p>
<?php endif; if ($error): ?>  <p class="error" role="alert"><?= mb_e($error) ?></p>
<?php endif; ?>

  <h2>Connection</h2>
  <form method="post" action="<?= mb_e($here) ?>">
    <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="act" value="save">
    <label>Brevo API key <span class="hint"><?= $c['api_key'] !== '' ? 'A key ending ' . mb_e(mb_mask_key($c['api_key'])) . ' is saved. Leave this empty to keep it, or paste a new one to replace it.' : 'In Brevo: your name, then SMTP &amp; API, then API keys. It starts with xkeysib-.' ?> The key is never shown here again.</span>
      <input name="api_key" type="password" autocomplete="off" spellcheck="false" maxlength="200"<?= $c['api_key'] === '' ? ' required' : '' ?>></label>
    <label>Send from this address <span class="hint">Must be a sender you've verified in Brevo, or Brevo will refuse to send.</span>
      <input name="from_email" type="email" required maxlength="254" value="<?= $val('from_email', $c['from_email']) ?>"></label>
    <label>Sender name <span class="hint">What recipients see beside the address.</span>
      <input name="from_name" required maxlength="80" value="<?= $val('from_name', $c['from_name']) ?>"></label>
    <button type="submit">Save</button>
  </form>

<?php if ($c['ready']): ?>
  <h2>Send a test</h2>
  <form method="post" action="<?= mb_e($here) ?>">
    <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="act" value="test">
    <label>Send a test email to <input name="to" type="email" required maxlength="254" value="<?= $val('to', (string) $user['email']) ?>"></label>
    <button type="submit">Send test email</button>
  </form>

  <h2>Remove the key</h2>
  <p>Mashpad stops sending email until you add a key again.</p>
  <form method="post" action="<?= mb_e($here) ?>">
    <input type="hidden" name="csrf" value="<?= mb_e($csrf) ?>"><input type="hidden" name="act" value="remove">
    <button type="submit" class="danger">Remove API key</button>
  </form>
<?php endif; ?>
  <p class="aside">Nothing on Mashpad sends email on its own yet. Password reset, invitations by email and digests will use this connection once they're built.</p>
<?php mb_page_end();
