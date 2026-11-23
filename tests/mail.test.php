<?php
/** Outgoing email through Brevo: settings, what is sent, how refusals are reported. No network: the HTTP call is replaced. */
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
require "$root/src/db.php";
$pdo = mb_db_connect($db);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec("DROP TABLE `$t`"); }
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
mb_migrate($pdo);
mb_write_config($db);
require_once "$root/src/mail.php";

$calls = [];
$reply = ['status' => 201, 'body' => '{"messageId":"<abc@smtp-relay.brevo.com>"}', 'error' => ''];
$GLOBALS['mb_http'] = function (string $method, string $url, array $headers, ?string $body) use (&$calls, &$reply) {
    $calls[] = compact('method', 'url', 'headers', 'body');
    return $reply;
};

check('settings read back, with a default when unset', mb_setting($pdo, 'nope', 'fallback') === 'fallback' && (mb_set_setting($pdo, 'x', '1') ?? true) && mb_setting($pdo, 'x') === '1'
      && (mb_set_setting($pdo, 'x', '2') ?? true) && mb_setting($pdo, 'x') === '2');
check('email is not ready until a key and a sender are set', mb_mail_config($pdo)['ready'] === false);
$r = mb_mail($pdo, 'sam@example.com', 'Sam', 'Hi', 'Body');
check('sending before setup is refused without calling Brevo', $r['ok'] === false && str_contains($r['error'], 'isn\'t set up') && $calls === []);

mb_set_setting($pdo, 'brevo_api_key', 'xkeysib-TESTKEY-k3Qz');
mb_set_setting($pdo, 'mail_from_email', 'hello@mashpad.ai');
mb_set_setting($pdo, 'mail_from_name', 'Mashpad');
$c = mb_mail_config($pdo);
check('ready once both are set; Brevo\'s real address by default', $c['ready'] && $c['api'] === 'https://api.brevo.com/v3');
check('a key is masked to its last four characters', mb_mask_key($c['api_key']) === '…k3Qz' && mb_mask_key('') === '');

$r = mb_mail($pdo, 'sam@example.com', "Sam\r\nBcc: evil@example.com", "Hello\r\nX-Injected: 1", "Line one\nLine two", '<p>Line one</p>');
$sent = json_decode($calls[0]['body'], true);
check('a send is one POST to Brevo\'s transactional endpoint with the key in a header', $r['ok'] && $r['id'] === '<abc@smtp-relay.brevo.com>' && $calls[0]['method'] === 'POST'
      && $calls[0]['url'] === 'https://api.brevo.com/v3/smtp/email' && in_array('api-key: xkeysib-TESTKEY-k3Qz', $calls[0]['headers'], true) && !str_contains($calls[0]['body'], 'xkeysib'));
check('the message carries sender, recipient, subject, text and html', $sent['sender'] === ['name' => 'Mashpad', 'email' => 'hello@mashpad.ai'] && $sent['to'][0]['email'] === 'sam@example.com'
      && $sent['textContent'] === "Line one\nLine two" && $sent['htmlContent'] === '<p>Line one</p>');
check('line breaks can\'t smuggle headers through a name or subject', $sent['subject'] === 'Hello X-Injected: 1' && $sent['to'][0]['name'] === 'Sam Bcc: evil@example.com');
$log = $pdo->query("SELECT action, subject, detail FROM audit_log WHERE action LIKE 'mail.%' ORDER BY id DESC LIMIT 1")->fetch();
check('the log records that mail went out and to which domain, not the address or the text', $log['action'] === 'mail.sent' && $log['subject'] === 'to:@example.com' && !str_contains($log['detail'], 'Line one'));
check('text-only mail has no html part', (function () use ($pdo, &$calls) { mb_mail($pdo, 'a@example.com', '', 'S', 'T'); $j = json_decode(end($calls)['body'], true); return !isset($j['htmlContent']) && $j['to'][0] === ['email' => 'a@example.com']; })());

$n = count($calls);
check('a bad recipient is refused before any call', mb_mail($pdo, 'not-an-address', '', 'S', 'T')['ok'] === false && count($calls) === $n);
$reply = ['status' => 401, 'body' => '{"code":"unauthorized","message":"Key not found"}', 'error' => ''];
check('a refused key is reported in plain words', mb_mail($pdo, 'a@example.com', '', 'S', 'T')['error'] === 'Brevo refused the API key: Key not found');
$reply = ['status' => 400, 'body' => '{"code":"invalid_parameter","message":"Sending has been rejected because the sender you used hello@mashpad.ai is not valid."}', 'error' => ''];
$bad = mb_mail($pdo, 'a@example.com', '', 'S', 'T');
check('Brevo\'s own reason is passed on (an unverified sender)', $bad['ok'] === false && str_starts_with($bad['error'], 'Brevo said: Sending has been rejected because the sender'));
$reply = ['status' => 0, 'body' => '', 'error' => 'Could not resolve host'];
check('an unreachable Brevo is reported, and never throws', mb_mail($pdo, 'a@example.com', '', 'S', 'T')['error'] === 'Brevo couldn\'t be reached (Could not resolve host).');
check('failures are logged too', $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'mail.failed'")->fetchColumn() == 3);

$reply = ['status' => 200, 'body' => '{"email":"owner@parker-lambert.com","companyName":"Parker-Lambert"}', 'error' => ''];
$who = mb_brevo_account('xkeysib-TESTKEY-k3Qz');
check('checking a key asks Brevo whose it is', $who === ['ok' => true, 'email' => 'owner@parker-lambert.com', 'company' => 'Parker-Lambert', 'error' => ''] && end($calls)['method'] === 'GET' && end($calls)['url'] === 'https://api.brevo.com/v3/account');
$reply = ['status' => 401, 'body' => '{"message":"Key not found"}', 'error' => ''];
check('and reports a key Brevo doesn\'t know', mb_brevo_account('wrong')['ok'] === false && mb_brevo_account('wrong')['error'] === 'Brevo refused the API key: Key not found');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
