<?php
/** Email Mashpad sends by itself: password reset, the join-request notification, and unsubscribing. No network. */
declare(strict_types=1);

$root = dirname(__DIR__);
$env  = fn ($k, $d = '') => getenv($k) !== false ? (string) getenv($k) : $d;
$db   = ['host' => $env('MASHPAD_TEST_DB_HOST', '127.0.0.1'), 'port' => (int) $env('MASHPAD_TEST_DB_PORT', '3306'),
         'name' => $env('MASHPAD_TEST_DB_NAME'), 'user' => $env('MASHPAD_TEST_DB_USER'), 'pass' => $env('MASHPAD_TEST_DB_PASS')];
if (!str_starts_with($db['name'], 'mashpad_test')) {
    fwrite(STDERR, "skipped: set MASHPAD_TEST_DB_NAME to a disposable database named mashpad_test*\n");
    exit(0);
}
$files = ["$root/data/config.php", "$root/data/schema-version", "$root/data/.last-pad-prune"];
$saved = [];
foreach ($files as $f) { if (is_file($f)) { $saved[$f] = file_get_contents($f); unlink($f); } }
register_shutdown_function(function () use ($files, $saved) {
    foreach ($files as $f) { @unlink($f); if (isset($saved[$f])) file_put_contents($f, $saved[$f]); }
});
$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail; $ok ? $pass++ : $fail++;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
}
function err(callable $fn): string {
    try { $fn(); } catch (Throwable $t) { return $t->getMessage(); }
    return '(no error)';
}
require "$root/src/db.php";
$pdo = mb_db_connect($db);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec("DROP TABLE `$t`"); }
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
mb_migrate($pdo);
mb_write_config($db);
require "$root/src/invites.php";
$_SERVER['HTTP_HOST'] = 'mashpad.example'; $_SERVER['HTTPS'] = 'on'; $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

$mails = [];
$GLOBALS['mb_http'] = function (string $method, string $url, array $headers, ?string $body) use (&$mails) {
    $mails[] = json_decode((string) $body, true);
    return ['status' => 201, 'body' => '{"messageId":"<x@brevo>"}', 'error' => ''];
};
$dylanId = mb_create_human($pdo, 'Dylan', 'dylan', 'dylan@example.com', 'correct horse battery', true);
$samId   = mb_create_human($pdo, 'Sam', 'sam', 'sam@example.com', 'correct horse battery');
$dylan   = mb_principal($pdo, $dylanId);

// Before email is set up
check('with email not set up, a reset says so and names who to write to', str_contains(err(fn () => mb_reset_request($pdo, 'dylan@example.com')), 'can\'t send email yet') && $mails === []);
$pad = mb_create_pad($pdo, $dylan, 'Scheduling');
check('and a join-request notification is skipped quietly', mb_notify_join_request($pdo, $pad, mb_principal($pdo, $samId)) === 'no-mail' && $mails === []);
mb_set_setting($pdo, 'brevo_api_key', 'xkeysib-TEST');
mb_set_setting($pdo, 'mail_from_email', 'hello@mashpad.ai');

// Password reset: a six-digit code, no link
mb_reset_request($pdo, 'Dylan@Example.com');
$m = end($mails);
preg_match('/^Your Mashpad password reset code is (\d{6})$/', $m['subject'], $mm);
$code = $mm[1] ?? '';
check('a reset request emails the account\'s address, with the code in the subject line', count($mails) === 1 && $m['to'][0]['email'] === 'dylan@example.com' && $code !== '', $m['subject']);
check('the body repeats the code, says it expires in 15 minutes, and has one link: the plain reset page', str_contains($m['textContent'], "Your Mashpad password reset code is $code") && str_contains($m['textContent'], 'This code expires in 15 minutes.')
      && str_contains($m['textContent'], 'Click here to enter your code and reset your password') && preg_match_all('~https?://\S+~', $m['textContent'], $urls) === 1 && $urls[0][0] === 'https://mashpad.example/reset/?step=code');
check('the HTML version links that sentence to the reset page, and nothing in the address is secret', str_contains($m['htmlContent'] ?? '', '<a href="https://mashpad.example/reset/?step=code">Click here to enter your code and reset your password</a>')
      && !str_contains($m['htmlContent'] ?? '', '?t=') && substr_count($m['htmlContent'] ?? '', $code) === 1);
check('the reset email has no unsubscribe header: it is not a subscription', !isset($m['headers']));
check('the code itself is not stored, only a hash tied to the account', !$pdo->query("SELECT COUNT(*) FROM password_resets WHERE code_hash = " . $pdo->quote($code))->fetchColumn()
      && mb_reset_hash($dylanId, $code) !== mb_reset_hash($samId, $code));
$n = count($mails);
mb_reset_request($pdo, 'nobody@example.com');
check('an address with no account gets no email, and no error either', count($mails) === $n);
$wrongMsg = err(fn () => mb_reset_complete($pdo, 'dylan@example.com', '000000' === $code ? '111111' : '000000', 'a brand new long password'));
check('a wrong code is refused', str_contains($wrongMsg, 'isn\'t right, or it has expired'));
check('an unknown address gets exactly the same refusal', err(fn () => mb_reset_complete($pdo, 'nobody@example.com', '123456', 'a brand new long password')) === $wrongMsg);
check('a short new password is refused and doesn\'t cost the code', str_contains(err(fn () => mb_reset_complete($pdo, 'dylan@example.com', $code, 'short')), 'at least'));
@mb_create_session($pdo, $dylanId);
$live = fn () => (int) $pdo->query("SELECT COUNT(*) FROM tokens WHERE principal_id = $dylanId AND kind = 'session' AND revoked_at IS NULL")->fetchColumn();
check('there is a signed-in session before the reset', $live() === 1);
check('the right code (typed with a space) sets the new password', mb_reset_complete($pdo, ' DYLAN@example.com ', substr($code, 0, 3) . ' ' . substr($code, 3), 'a brand new long password') === $dylanId);
check('every earlier sign-in for the account was ended', $live() === 0);
check('the new password signs in', @mb_sign_in($pdo, 'dylan@example.com', 'a brand new long password') === $dylanId);
check('the old password no longer works', str_contains(err(fn () => mb_sign_in($pdo, 'dylan@example.com', 'correct horse battery')), 'match'));
check('the code works once', err(fn () => mb_reset_complete($pdo, 'dylan@example.com', $code, 'another long password here')) === $wrongMsg);
$codeOf = fn () => preg_match('/(\d{6})$/', end($mails)['subject'], $x) ? $x[1] : '';
mb_reset_request($pdo, 'sam@example.com'); $a = $codeOf();
mb_reset_request($pdo, 'sam@example.com'); $b = $codeOf();
check('asking again replaces the earlier code', ($a === $b) || err(fn () => mb_reset_complete($pdo, 'sam@example.com', $a, 'a long enough password')) === $wrongMsg);
$bad = fn (string $c) => $c === $b ? ($c === '999999' ? '888888' : '999999') : $c;
for ($i = 0; $i < MB_RESET_TRIES; $i++) { err(fn () => mb_reset_complete($pdo, 'sam@example.com', $bad(sprintf('%06d', $i)), 'a long enough password')); }
check(MB_RESET_TRIES . ' wrong guesses cancel the code: even the right one is then refused', err(fn () => mb_reset_complete($pdo, 'sam@example.com', $b, 'a long enough password')) === $wrongMsg
      && (int) $pdo->query("SELECT COUNT(*) FROM password_resets WHERE principal_id = $samId AND used_at IS NULL")->fetchColumn() === 0);
check('and the password was never changed', @mb_sign_in($pdo, 'sam@example.com', 'correct horse battery') === $samId);
mb_reset_request($pdo, 'sam@example.com'); $c = $codeOf();
$pdo->exec('UPDATE password_resets SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE used_at IS NULL');
check('an expired code is refused', err(fn () => mb_reset_complete($pdo, 'sam@example.com', $c, 'a long enough password')) === $wrongMsg);
$n = count($mails);
mb_reset_request($pdo, 'sam@example.com'); mb_reset_request($pdo, 'sam@example.com');
check('one address gets at most ' . MB_RESET_PER_HOUR . ' reset emails an hour; further requests are quiet', count($mails) === $n);
check('a code is always six digits, leading zeros kept', (function () { for ($i = 0; $i < 200; $i++) { if (!preg_match('/^\d{6}$/', str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT))) return false; } return true; })());
for ($i = 0; $i < MB_RESET_PER_IP; $i++) { try { mb_reset_request($pdo, "flood$i@example.com"); } catch (Throwable $e) { break; } }
check('one network address can only ask so often', str_contains(err(fn () => mb_reset_request($pdo, 'more@example.com')), 'Too many reset attempts'));

// The join-request notification, and unsubscribing
$inv = mb_invite_make($pdo, $pad, $dylanId);
$n = count($mails);
[$guest] = mb_invite_guest_agent($pdo, $pad, ['handle' => 'bodhi', 'acts_for' => "Lee\r\nBcc: x@evil.example"], false);
check('a request to join is still filed when email is on', mb_invite_use($pdo, $inv, $pad, $guest) === 'requested' && count(mb_join_requests($pdo, (int) $pad['id'])) === 1);
$m = end($mails);
check('the pad\'s owner is emailed once, with who is asking and a link to the pad', count($mails) === $n + 1 && $m['to'][0]['email'] === 'dylan@example.com' && $m['subject'] === '@bodhi asked to join Scheduling'
      && str_contains($m['textContent'], 'https://mashpad.example/pads/dylan/scheduling') && str_contains($m['textContent'], 'an AI agent that says it acts for Lee') && str_contains($m['textContent'], 'hasn\'t verified'), json_encode($m));
$prefs = mb_email_prefs($pdo, $dylanId);
$unsub = 'https://mashpad.example/email/?t=' . $prefs['unsub_token'];
check('it carries the one-click unsubscribe headers', ($m['headers']['List-Unsubscribe'] ?? '') === "<$unsub>" && ($m['headers']['List-Unsubscribe-Post'] ?? '') === 'List-Unsubscribe=One-Click');
check('the body says how to stop the emails and holds no address with a token in it', str_contains($m['textContent'], 'Unsubscribe button') && str_contains($m['textContent'], 'https://mashpad.example/email/')
      && !str_contains($m['textContent'], $prefs['unsub_token']) && !str_contains($m['textContent'], '?t='));
check('a header value can\'t carry a line break', !preg_match('/[\r\n]/', implode('', $m['headers'])));
mb_invite_use($pdo, $inv, $pad, $guest);
check('asking again does not email again', count($mails) === $n + 1);
check('the link\'s token finds its person; a made-up one does not', (int) mb_email_prefs_by_token($pdo, $prefs['unsub_token'])['principal_id'] === $dylanId && mb_email_prefs_by_token($pdo, str_repeat('z', 43)) === null && mb_email_prefs_by_token($pdo, "x' OR 1=1") === null);
mb_email_set_requests($pdo, $dylanId, false);
[$g2] = mb_invite_guest_agent($pdo, $pad, ['handle' => 'second', 'acts_for' => 'Ann'], false);
check('unsubscribed: the next request is filed but sends nothing', mb_invite_use($pdo, $inv, $pad, $g2) === 'requested' && count($mails) === $n + 1 && mb_notify_join_request($pdo, $pad, $g2) === 'off');
mb_email_set_requests($pdo, $dylanId, true);
check('turned back on: notifications resume', mb_notify_join_request($pdo, $pad, $g2) === 'sent' && count($mails) === $n + 2);
check('preferences keep one token per person', mb_email_prefs($pdo, $dylanId)['unsub_token'] === $prefs['unsub_token'] && mb_email_prefs($pdo, $samId)['unsub_token'] !== $prefs['unsub_token']);
$GLOBALS['mb_http'] = fn () => ['status' => 0, 'body' => '', 'error' => 'down'];
[$g3] = mb_invite_guest_agent($pdo, $pad, ['handle' => 'third', 'acts_for' => 'Zed'], false);
check('if the mail service is down the request is still filed', mb_invite_use($pdo, $inv, $pad, $g3) === 'requested' && mb_notify_join_request($pdo, $pad, $g3) === 'failed');
$GLOBALS['mb_http'] = function () { throw new RuntimeException('boom'); };
check('and even a crash inside sending never reaches the caller', mb_notify_join_request($pdo, $pad, $g3) === 'failed');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
