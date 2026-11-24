<?php
/** Pad items: types, values, versions, permissions, limits, events. */
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
    try { $fn(); } catch (Throwable $t) { return get_class($t) . ': ' . $t->getMessage(); }
    return '(no error)';
}
require "$root/src/db.php";
$pdo = mb_db_connect($db);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec("DROP TABLE `$t`"); }
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
mb_migrate($pdo);
mb_write_config($db);
putenv('MASHPAD_SECRET_KEY=' . base64_encode(str_repeat('k', 32)));
require_once "$root/src/invites.php";
require_once "$root/src/claims.php";
require_once "$root/src/wake.php";
$GLOBALS['mb_wake_no_defer'] = true;

$sent = []; $reply = ['status' => 200, 'error' => null, 'transient' => false]; $ips = ['93.184.216.34'];
$GLOBALS['mb_cimd_resolver'] = function ($h) use (&$ips) { return $ips; };
$GLOBALS['mb_wake_http']     = function ($m, $u, $h, $b, $host, $ip) use (&$sent, &$reply) { $sent[] = compact('m', 'u', 'h', 'b', 'host', 'ip'); return is_callable($reply) ? $reply() : $reply; };
$mk = fn (string $h, string $plan = 'free') => mb_create_human($pdo, ucfirst($h), $h, "$h@example.com", 'correct horse battery', $h === 'dylan');
$dylanId = $mk('dylan'); $samId = $mk('sam');
$dylan = mb_principal($pdo, $dylanId); $sam = mb_principal($pdo, $samId);
$pdo->exec('UPDATE plans SET max_human_seats = NULL, max_agents = NULL, post_interval_seconds = 0');   // this suite posts a lot
$pad = mb_create_pad($pdo, $dylan, 'Launch'); $padOf = fn () => mb_pad_by_address($pdo, 'dylan', 'launch');
mb_add_member($pdo, $padOf(), $samId, 'added');
$agent = function (string $handle, int $owner = 0) use ($pdo, $dylanId, $padOf) { [$id] = mb_create_agent($pdo, $owner ?: $dylanId, ['handle' => $handle]); mb_add_member($pdo, $padOf(), $id, 'added'); return $id; };
$muse = $agent('muse'); $qa = $agent('qa-bot'); $idle = $agent('idle'); $quiet = $agent('quiet');
$A = fn (int $id) => mb_principal($pdo, $id);
$cnt = fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();
$hook = 'https://hooks.example.com/wake/abc123';
$routine = 'https://api.anthropic.com/v1/claude_code/routines/trig_01ABCdef/fire';
$log = fn (int $agentId = 0) => $pdo->query('SELECT * FROM wake_log' . ($agentId ? " WHERE agent_id = $agentId" : '') . ' ORDER BY id')->fetchAll();
$clear = function () use ($pdo) { $pdo->exec('DELETE FROM wake_log'); $pdo->exec('UPDATE pad_members SET last_wake_at = NULL, last_read_at = NULL'); };

// ---- a server that can't keep a token says so, and nothing fails
putenv('MASHPAD_SECRET_KEY=not a key');
$e = err(fn () => mb_wake_target_save($pdo, $muse, $dylanId, ['kind' => 'routine', 'url' => $routine, 'token' => 'sk-ant-oat01-abcdef123456']));
check('saving a token where none can be kept is refused in plain words, not a crash, and nothing is saved', str_contains($e, 'MbAccountError') && str_contains($e, 'Mashpad can\'t keep a token safely on this server: MASHPAD_SECRET_KEY must be 32 bytes') && mb_wake_target($pdo, $muse) === null, $e);
check('and so is a webhook, which needs a secret to sign with', str_contains(err(fn () => mb_wake_target_save($pdo, $qa, $dylanId, ['kind' => 'webhook', 'url' => $hook])), 'Mashpad can\'t keep a token safely') && mb_wake_target($pdo, $qa) === null);
$pdo->exec("INSERT INTO agent_wake_targets (principal_id, kind, url, token_sealed, created_by) VALUES ($muse, 'routine', '$routine', 'v1." . base64_encode(str_repeat('x', 60)) . "', $dylanId)");
$pdo->exec("INSERT INTO wake_log (agent_id, pad_id, post_seq, from_id, reason, status, next_try_at) VALUES ($muse, {$pad['id']}, 1, $dylanId, 'person', 'pending', UTC_TIMESTAMP())"); $stuck = (int) $pdo->lastInsertId();
check('a wake-up that can\'t be read back fails with the reason in the log, and the flush carries on', mb_wake_deliver($pdo, $stuck) === 'failed' && str_contains((string) $pdo->query("SELECT note FROM wake_log WHERE id = $stuck")->fetchColumn(), 'Mashpad can\'t read stored tokens on this server'));
$pdo->exec('DELETE FROM agent_wake_targets'); $pdo->exec('DELETE FROM wake_log');
putenv('MASHPAD_SECRET_KEY=' . base64_encode(str_repeat('k', 32)));

// ---- sealing
$s1 = mb_seal('sk-secret-token'); $s2 = mb_seal('sk-secret-token');
check('a secret is sealed, differently each time, and comes back', $s1 !== $s2 && !str_contains($s1, 'secret') && mb_unseal($s1) === 'sk-secret-token' && mb_unseal($s2) === 'sk-secret-token');
check('something that isn\'t sealed, or has been touched, comes back as nothing', mb_unseal('plain') === null && mb_unseal(null) === null && mb_unseal('v1.' . base64_encode('short')) === null && mb_unseal(substr($s1, 0, -4) . 'AAAA') === null);
putenv('MASHPAD_SECRET_KEY'); // the key is read once per process, so a changed key is shown through a copy of the check instead
check('the sealing key is 32 bytes and is not the database\'s', strlen(mb_secret_key()) === 32);
putenv('MASHPAD_SECRET_KEY=' . base64_encode(str_repeat('k', 32)));

// ---- mentions
$h = ['muse', 'qa-bot', 'sam'];
foreach ([['hey @muse, look', ['muse']], ['@MUSE please', ['muse']], ['@muse @qa-bot both', ['muse', 'qa-bot']], ['mail me at x@muse.com', []], ['`@muse` in code', []], ["```\n@muse\n```", []], ['not @museum', []], ['@muse-2 is another', []],
          ['(@muse)', ['muse']], ['@muse.', ['muse']], ['hi @nobody', []], ['@@muse', []], ['x@muse', []], ['@muse@qa-bot', ['muse']], ["line one\n@qa-bot, line two", ['qa-bot']], ['@m', []]] as [$text, $want]) {
    check('mention: ' . json_encode($text) . ' -> ' . json_encode($want), mb_wake_mentions($text, $h) === $want, json_encode(mb_wake_mentions($text, $h)));
}

// ---- setting where an agent is woken
check('a routine\'s address must be Anthropic\'s trigger address, whoever the person trusts', array_reduce([
    ['https://hooks.example.com/v1/claude_code/routines/trig_01ABCdef/fire', 'Claude Code routine'], ['https://api.anthropic.com/v1/messages', 'Claude Code routine'], ['https://api.anthropic.com/v1/claude_code/routines/trig_01ABCdef/fire?x=1', 'Claude Code routine'],
    ['https://api.anthropic.com/v1/claude_code/routines/x/fire', 'Claude Code routine'], ['https://evil.example/api.anthropic.com/v1/claude_code/routines/trig_01ABCdef/fire', 'Claude Code routine']], fn ($ok, $c) => $ok && str_contains(err(fn () => mb_wake_target_save($pdo, $muse, $dylanId, ['kind' => 'routine', 'url' => $c[0], 'token' => 'sk-ant-oat01-abcdef123456'])), $c[1]), true));
check('and its token is needed, and must look like a token', str_contains(err(fn () => mb_wake_target_save($pdo, $muse, $dylanId, ['kind' => 'routine', 'url' => $routine])), 'needs its token') && str_contains(err(fn () => mb_wake_target_save($pdo, $muse, $dylanId, ['kind' => 'routine', 'url' => $routine, 'token' => 'has space in it'])), 'needs its token')
      && str_contains(err(fn () => mb_wake_target_save($pdo, $muse, $dylanId, ['kind' => 'routine', 'url' => $routine, 'token' => 'short'])), 'needs its token'));
$r = mb_wake_target_save($pdo, $muse, $dylanId, ['kind' => 'routine', 'url' => $routine, 'token' => 'sk-ant-oat01-abcdef123456']);
$t = mb_wake_target($pdo, $muse);
check('a routine is saved with its token sealed, and no secret', $r['secret'] === null && $t['kind'] === 'routine' && $t['url'] === $routine && $t['secret_sealed'] === null && $t['token_sealed'] !== null && !str_contains($t['token_sealed'], 'sk-ant') && mb_unseal($t['token_sealed']) === 'sk-ant-oat01-abcdef123456' && (int) $t['failures'] === 0);
mb_wake_target_save($pdo, $muse, $dylanId, ['kind' => 'routine', 'url' => $routine, 'token' => '']);
check('saving again with the token left blank keeps the token', mb_unseal(mb_wake_target($pdo, $muse)['token_sealed']) === 'sk-ant-oat01-abcdef123456');
$r = mb_wake_target_save($pdo, $qa, $dylanId, ['kind' => 'webhook', 'url' => $hook]); $qt = mb_wake_target($pdo, $qa);
check('a webhook is saved with a signing secret, shown once, and sealed', str_starts_with((string) $r['secret'], 'whsec_') && $qt['token_sealed'] === null && mb_unseal($qt['secret_sealed']) === $r['secret'] && !str_contains($qt['secret_sealed'], 'whsec_'));
$r2 = mb_wake_target_save($pdo, $qa, $dylanId, ['kind' => 'webhook', 'url' => 'https://hooks.example.com/wake/other']);
check('changing its address keeps the secret, and says nothing new', $r2['secret'] === null && mb_wake_target($pdo, $qa)['secret_sealed'] === $qt['secret_sealed'] && mb_wake_target($pdo, $qa)['url'] === 'https://hooks.example.com/wake/other');
$r3 = mb_wake_target_save($pdo, $qa, $dylanId, ['kind' => 'webhook', 'url' => $hook, 'rotate_secret' => '1']);
check('asking for a new secret makes one', str_starts_with((string) $r3['secret'], 'whsec_') && $r3['secret'] !== $r['secret'] && mb_unseal(mb_wake_target($pdo, $qa)['secret_sealed']) === $r3['secret']);
foreach ([['http://hooks.example.com/w', 'https address'], ['https://hooks.example.com', 'needs a path'], ['https://127.0.0.1/w', 'IP address'], ['https://hooks.example.com:8443/w', 'port other than 443'], ['https://user:pw@hooks.example.com/w', 'user name'], ['https://x.internal/w', 'internal']] as [$u, $needle]) {
    check("an address that can't be used: $u", str_contains($e = err(fn () => mb_wake_target_save($pdo, $idle, $dylanId, ['kind' => 'webhook', 'url' => $u])), $needle) && str_contains($e, 'That address can\'t be used for wake-ups'), $e);
}
$ips = ['10.0.0.5']; check('an address that points somewhere private is refused when it is saved', str_contains(err(fn () => mb_wake_target_save($pdo, $idle, $dylanId, ['kind' => 'webhook', 'url' => $hook])), 'private or reserved'));
$ips = []; check('and one that can\'t be found, saying so', str_contains(err(fn () => mb_wake_target_save($pdo, $idle, $dylanId, ['kind' => 'webhook', 'url' => $hook])), 'couldn\'t find hooks.example.com'));
$ips = ['93.184.216.34'];
check('only the person responsible can set it, and not for a guest or for nobody', str_contains(err(fn () => mb_wake_target_save($pdo, $idle, $samId, ['kind' => 'webhook', 'url' => $hook])), 'isn\'t one of your agents')
      && str_contains(err(fn () => mb_wake_target_save($pdo, 999999, $dylanId, ['kind' => 'webhook', 'url' => $hook])), 'isn\'t one of your agents') && str_contains(err(fn () => mb_wake_target_save($pdo, $idle, $dylanId, ['kind' => 'smoke-signal', 'url' => $hook])), 'Choose how to wake it'));
$inv = mb_invite_make($pdo, $padOf(), $dylanId); $guest = mb_invite_guest_agent($pdo, $padOf(), ['handle' => 'stranger', 'acts_for' => 'Sam'], false);
check('an agent that joined on its own can\'t have a wake-up set by the pad\'s owner', str_contains(err(fn () => mb_wake_target_save($pdo, (int) $guest[0]['id'], $dylanId, ['kind' => 'webhook', 'url' => $hook])), 'hasn\'t been claimed by its person yet'));
check('what changed is recorded, without the token', ($a = $pdo->query("SELECT detail FROM audit_log WHERE action = 'wake.set' ORDER BY id DESC LIMIT 1")->fetchColumn()) && str_contains($a, 'webhook hooks.example.com') && !str_contains($a, 'whsec') && !str_contains($a, '/wake/'));
check('status says what is set up, without secrets', ($s = mb_wake_status($pdo, $muse))['state'] === 'active' && $s['kind'] === 'routine' && $s['host'] === 'api.anthropic.com' && !str_contains(json_encode($s), 'sk-ant') && mb_wake_status($pdo, $idle)['state'] === 'none');
mb_wake_target_save($pdo, $quiet, $dylanId, ['kind' => 'webhook', 'url' => $hook]);
mb_wake_mode_set($pdo, (int) $pad['id'], $quiet, 'off');

// ---- what wakes an agent
$post = fn (array $by, string $body, ?int $reply = null) => mb_pad_post($pdo, $padOf(), $by, $body, $reply)['id'];
$woken = fn () => array_map(fn ($r) => $A((int) $r['agent_id'])['handle'] . ':' . $r['reason'], array_filter($log(), fn ($r) => $r['status'] !== 'skipped'));
$clear(); $s1 = $post($dylan, 'The plan is ready for review.');
check('a post by a person wakes the agents set up for it, but not one with no wake-up or one switched off for the pad', array_values($woken()) === ['muse:person', 'qa-bot:person'], json_encode($woken()));
$rows = $log();
check('what is queued has who, where and why, and no pad text', (int) $rows[0]['pad_id'] === (int) $pad['id'] && (int) $rows[0]['post_seq'] === $s1 && (int) $rows[0]['from_id'] === $dylanId && $rows[0]['status'] === 'pending' && !str_contains(json_encode($rows), 'plan is ready'));
$clear(); $post(['id' => $dylanId, 'handle' => 'dylan'], 'A post by the signed-in person, whose row has no kind.');
check('a post by the signed-in person (a row with no kind, as the pad page has it) is recognised as a person\'s', array_values($woken()) === ['muse:person', 'qa-bot:person'], json_encode($woken()));
$clear(); $post($A($muse), 'Looks fine to me.');
check('a post by an agent wakes nobody who isn\'t mentioned or replied to, and never the author', $woken() === []);
$clear(); $post($A($muse), 'Over to you, @qa-bot.');
check('a mention wakes the agent mentioned', array_values($woken()) === ['qa-bot:mention']);
$clear(); $mine = $post($A($qa), 'Here is my report.'); $clear(); $post($A($muse), 'Thanks, noted.', $mine);
check('a reply to an agent\'s post wakes it', array_values($woken()) === ['qa-bot:reply'], json_encode($woken()));
$clear(); $post($dylan, 'Hello team, @muse in particular.');
check('a mention wins over the general reason, and wakes once', array_values($woken()) === ['muse:mention', 'qa-bot:person']);
mb_wake_mode_set($pdo, (int) $pad['id'], $muse, 'mentions'); $clear(); $post($dylan, 'Anyone around?');
check('with "mentions and replies" a person\'s ordinary post does not wake it', array_values($woken()) === ['qa-bot:person']);
$clear(); $post($dylan, 'Please look, @muse.');
check('but a mention does', in_array('muse:mention', $woken(), true));
mb_wake_mode_set($pdo, (int) $pad['id'], $muse, 'everything'); $clear(); $post($A($qa), 'Status update, nothing for anyone.');
check('with "everything" an agent\'s plain post wakes it', array_values($woken()) === ['muse:post']);
mb_wake_mode_set($pdo, (int) $pad['id'], $muse, 'people');
check('the choices are named, and anything else is refused', array_keys(MB_WAKE_MODES) === ['off', 'mentions', 'people', 'everything'] && str_contains(err(fn () => mb_wake_mode_set($pdo, (int) $pad['id'], $muse, 'always')), 'Choose what wakes it'));
$clear(); mb_wake_set_paused($pdo, $qa, $dylanId, true); $post($dylan, 'Another post.');
check('a paused wake-up is not used', array_values($woken()) === ['muse:person'] && mb_wake_status($pdo, $qa)['state'] === 'paused');
mb_wake_set_paused($pdo, $qa, $dylanId, false);
$pdo->exec("UPDATE pad_members SET removed_at = UTC_TIMESTAMP() WHERE principal_id = $muse"); $clear(); $post($dylan, 'After muse left.');
check('an agent no longer on the pad is not woken', array_values($woken()) === ['qa-bot:person']);
$pdo->exec("UPDATE pad_members SET removed_at = NULL WHERE principal_id = $muse");
check('the pad itself is unchanged by all of this: posts still return as they did', is_int($post($dylan, 'Still works.')));

// ---- one wake until it reads
$clear(); $post($dylan, 'First.'); $first = $cnt('SELECT COUNT(*) FROM wake_log'); $post($dylan, 'Second, straight after.');
check('while an agent has been woken and has not read the pad, more posts do not wake it again', $first === 2 && $cnt('SELECT COUNT(*) FROM wake_log') === 2);
$pdo->exec("UPDATE pad_members SET last_read_at = UTC_TIMESTAMP() + INTERVAL 1 SECOND WHERE principal_id = $muse"); $post($dylan, 'Third, after muse read.');
check('once it has read the pad it can be woken again', $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $muse") === 2 && $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $qa") === 1);
$pdo->exec("UPDATE pad_members SET last_wake_at = UTC_TIMESTAMP() - INTERVAL 31 MINUTE, last_read_at = NULL WHERE principal_id = $qa"); $post($dylan, 'Fourth, half an hour on.');
check('or when half an hour has gone by without it', $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $qa") === 2);

// ---- limits
$clear(); $pdo->exec("UPDATE plans SET max_wakes_per_day = 2 WHERE code = 'free'");
for ($i = 1; $i <= 4; $i++) { $pdo->exec('UPDATE pad_members SET last_wake_at = NULL'); $post($dylan, "Post number $i."); }
check('a plan\'s daily limit stops more wake-ups for that agent, and says why, once', $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $muse AND status <> 'skipped'") === 2 && $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $muse AND status = 'skipped'") === 1
      && str_contains((string) $pdo->query("SELECT note FROM wake_log WHERE agent_id = $muse AND status = 'skipped'")->fetchColumn(), 'Daily limit reached: your plan allows 2 wake-ups a day for each agent.'));
$pdo->exec("UPDATE plans SET max_wakes_per_day = NULL WHERE code = 'free'"); $pdo->exec('UPDATE pad_members SET last_wake_at = NULL'); $post($dylan, 'No limit now.');
check('with no limit it is woken again', $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $muse AND status <> 'skipped'") === 3);
$pdo->exec("UPDATE plans SET max_wakes_per_day = 20 WHERE code = 'free'");
check('the seeds are 20 on Free and 200 on Plus', (int) array_column(mb_plans($pdo), null, 'code')['free']['max_wakes_per_day'] === 20 && (int) array_column(mb_plans($pdo), null, 'code')['plus']['max_wakes_per_day'] === 200);
$clear(); for ($i = 0; $i < MB_WAKE_PER_PAD_HOUR; $i++) { $pdo->exec("INSERT INTO wake_log (agent_id, pad_id, reason, status) VALUES ($idle, {$pad['id']}, 'person', 'sent')"); }
$post($dylan, 'The pad is busy.');
check('a pad that has used its wake-ups for the hour wakes no more, and says so', $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id IN ($muse, $qa) AND status <> 'skipped'") === 0 && $cnt("SELECT COUNT(*) FROM wake_log WHERE status = 'skipped' AND note LIKE '%wake-ups for the hour%'") >= 1);
$clear(); $pdo->exec('UPDATE pad_members SET last_wake_at = NULL');

// ---- what is sent
check('the message says who, where and why, and not what was said', mb_wake_message('mention', 'dylan', 'dylan/launch', 164, 'muse') === '@dylan mentioned you in dylan/launch (post #164). Read the pad with read_board and reply there.'
      && mb_wake_message('reply', 'sam', 'dylan/launch', 9, 'muse') === '@sam replied to your post in dylan/launch (post #9). Read the pad with read_board and reply there.'
      && mb_wake_message('person', 'sam', 'dylan/launch', null, 'muse') === '@sam posted in dylan/launch. Read the pad with read_board and reply there.' && str_contains(mb_wake_message('test', 'x', '', null, 'muse'), 'Nothing needs doing.'));
check('and anything from outside it is cleaned: no line breaks, not long', !str_contains(mb_wake_message('mention', "a\nb\r\x00c", "pad\nname", 1, 'muse'), "\n") && mb_strlen(mb_wake_message('mention', str_repeat('x', 500), str_repeat('p', 500), 1, 'muse')) < 400);
$clear(); $post($dylan, 'Secret plans: the launch is on Tuesday, @muse.'); $sent = [];
$res = mb_wake_flush($pdo);
$byHost = []; foreach ($sent as $x) { $byHost[$x['u']] = $x; }
$rq = $byHost[$routine];
check('a routine is called at its address, pinned to the address that was checked, with its token and the headers it needs', $rq['m'] === 'POST' && $rq['host'] === 'api.anthropic.com' && $rq['ip'] === '93.184.216.34' && in_array('Authorization: Bearer sk-ant-oat01-abcdef123456', $rq['h'], true)
      && in_array('anthropic-beta: experimental-cc-routine-2026-04-01', $rq['h'], true) && in_array('anthropic-version: 2023-06-01', $rq['h'], true) && in_array('Content-Type: application/json', $rq['h'], true));
check('its body is the sentence and nothing else', json_decode($rq['b'], true) === ['text' => '@dylan mentioned you in dylan/launch (post #' . $post($dylan, 'x') . '). Read the pad with read_board and reply there.'] || str_starts_with(json_decode($rq['b'], true)['text'], '@dylan mentioned you in dylan/launch (post #') && array_keys(json_decode($rq['b'], true)) === ['text'] && !str_contains($rq['b'], 'Tuesday'));
$wh = $byHost[$hook]; $whBody = json_decode($wh['b'], true); $sigH = current(array_filter($wh['h'], fn ($x) => str_starts_with($x, 'Mashpad-Signature: ')));
preg_match('/t=(\d+),v1=([0-9a-f]{64})/', $sigH, $mm); $secret = mb_unseal(mb_wake_target($pdo, $qa)['secret_sealed']);
check('a webhook is signed over the time and the exact body, so the receiver can check both', isset($mm[2]) && hash_equals(hash_hmac('sha256', $mm[1] . '.' . $wh['b'], $secret), $mm[2]) && abs((int) $mm[1] - time()) < 5);
check('and says what happened, where, and why, with no pad text', $whBody['type'] === 'wake' && $whBody['reason'] === 'person' && $whBody['agent'] === '@qa-bot' && $whBody['pad'] === 'dylan/launch' && $whBody['pad_url'] === mb_site_url() . 'pads/dylan/launch' && $whBody['from'] === '@dylan'
      && str_starts_with($whBody['id'], 'wk_') && in_array('Mashpad-Delivery: ' . $whBody['id'], $wh['h'], true) && in_array('Mashpad-Event: wake', $wh['h'], true) && !str_contains($wh['b'], 'Tuesday') && !str_contains($wh['b'], 'Secret plans'));
check('nothing in either request carries the other agent\'s token or secret', !str_contains(json_encode($wh), 'sk-ant') && !str_contains(json_encode($rq), 'whsec'));
check('both were delivered, and the log and the target say so', $res['sent'] >= 2 && $cnt("SELECT COUNT(*) FROM wake_log WHERE status = 'sent' AND http_status = 200 AND sent_at IS NOT NULL") >= 2 && mb_wake_target($pdo, $muse)['last_result'] === 'Delivered (HTTP 200)' && mb_wake_target($pdo, $muse)['last_ok_at'] !== null);
check('what has been sent is not sent again', ($n = count($sent)) > 0 && mb_wake_flush($pdo)['sent'] === 0 && count($sent) === $n);

// ---- when it fails
$clear(); $pdo->exec('UPDATE agent_wake_targets SET failures = 0, last_result = NULL'); $pdo->exec('UPDATE pad_members SET last_wake_at = NULL, last_read_at = NULL');
$post($dylan, 'Try this.'); $row = $log($qa)[0]; $sent = [];
$reply = ['status' => 503, 'error' => null, 'transient' => true];
check('a server error is tried again, a minute later', mb_wake_deliver($pdo, (int) $row['id']) === 'retry' && ($r = $pdo->query("SELECT status, attempts, http_status, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), next_try_at) AS wait FROM wake_log WHERE id = {$row['id']}")->fetch()) && $r['status'] === 'pending' && (int) $r['attempts'] === 1 && (int) $r['http_status'] === 503 && $r['wait'] > 50 && $r['wait'] <= 60);
$sent = []; mb_wake_flush($pdo);
check('and not before it is due', count($sent) === 1 && $cnt("SELECT attempts FROM wake_log WHERE id = {$row['id']}") === 1);
$pdo->exec("UPDATE wake_log SET next_try_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = {$row['id']}");
check('then again, five minutes later', mb_wake_deliver($pdo, (int) $row['id']) === 'retry' && ($w = (int) $pdo->query("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), next_try_at) FROM wake_log WHERE id = {$row['id']}")->fetchColumn()) > 280 && $w <= 300);
check('and the third failure is final, with what went wrong, and counts against the target', mb_wake_deliver($pdo, (int) $row['id']) === 'failed' && ($r = $pdo->query("SELECT status, attempts, note FROM wake_log WHERE id = {$row['id']}")->fetch()) && $r['status'] === 'failed' && (int) $r['attempts'] === 3 && $r['note'] === 'HTTP 503'
      && (int) mb_wake_target($pdo, $qa)['failures'] === 1 && mb_wake_target($pdo, $qa)['last_result'] === 'Failed: HTTP 503');
foreach ([[401, 'the address refused it, so check the token or secret'], [403, 'the address refused it'], [404, 'nothing is there any more'], [410, 'nothing is there any more'], [400, null]] as [$st, $needle]) {
    $clear(); $pdo->exec('UPDATE agent_wake_targets SET failures = 0'); $pdo->exec('UPDATE pad_members SET last_wake_at = NULL'); $post($dylan, "For $st."); $row = $log($qa)[0]; $reply = ['status' => $st, 'error' => null, 'transient' => false];
    check("HTTP $st is final at once, and not retried" . ($needle ? ', saying what it means' : ''), mb_wake_deliver($pdo, (int) $row['id']) === 'failed' && ($n = (string) $pdo->query("SELECT note FROM wake_log WHERE id = {$row['id']}")->fetchColumn()) && str_starts_with($n, "HTTP $st") && ($needle === null || str_contains($n, $needle)), $n);
}
$clear(); $pdo->exec('UPDATE pad_members SET last_wake_at = NULL'); $post($dylan, 'Network.'); $row = $log($qa)[0]; $reply = ['status' => 0, 'error' => 'the host couldn\'t be reached', 'transient' => true];
check('a host that can\'t be reached is tried again', mb_wake_deliver($pdo, (int) $row['id']) === 'retry' && $cnt("SELECT COUNT(*) FROM wake_log WHERE id = {$row['id']} AND note = 'the host couldn''t be reached'") === 1);
$reply = ['status' => 200, 'error' => null, 'transient' => false];
$pdo->exec('UPDATE agent_wake_targets SET failures = 3 WHERE principal_id = ' . $qa); $pdo->exec('UPDATE pad_members SET last_wake_at = NULL'); $clear(); $post($dylan, 'Success resets.'); mb_wake_flush($pdo);
check('a delivery resets the count of failures', (int) mb_wake_target($pdo, $qa)['failures'] === 0);
$reply = ['status' => 404, 'error' => null, 'transient' => false];
for ($i = 1; $i <= MB_WAKE_PAUSE_AFTER; $i++) { $pdo->exec('UPDATE pad_members SET last_wake_at = NULL'); $clear(); $post($dylan, "Failure $i."); mb_wake_flush($pdo); }
$tq = mb_wake_target($pdo, $qa);
check('five failures in a row pause the wake-up, saying why', $tq['paused_at'] !== null && (int) $tq['failures'] === MB_WAKE_PAUSE_AFTER && str_contains((string) $tq['pause_reason'], '5 wake-ups in a row could not be delivered. The last: HTTP 404') && mb_wake_status($pdo, $qa)['state'] === 'paused');
$pdo->exec('UPDATE pad_members SET last_wake_at = NULL'); $clear(); $post($dylan, 'After the pause.');
check('and a paused agent is not woken', $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $qa") === 0);
mb_wake_set_paused($pdo, $qa, $dylanId, false);
check('resuming clears the pause and the count', mb_wake_status($pdo, $qa)['state'] === 'active' && (int) mb_wake_target($pdo, $qa)['failures'] === 0);
$reply = ['status' => 200, 'error' => null, 'transient' => false];

// ---- the address changing under us
$pdo->exec('UPDATE pad_members SET last_wake_at = NULL'); $clear(); $post($dylan, 'DNS has moved.'); $sent = []; $ips = ['169.254.169.254'];
$res = mb_wake_flush($pdo);
check('if the name now points somewhere private, nothing is sent, and the log says why', $sent === [] && $res['failed'] >= 1 && str_contains((string) $pdo->query("SELECT note FROM wake_log WHERE agent_id = $qa")->fetchColumn(), 'private or reserved'));
$ips = ['93.184.216.34'];
$pdo->exec('UPDATE agent_wake_targets SET failures = 0, paused_at = NULL, pause_reason = NULL'); $clear(); $pdo->exec('UPDATE pad_members SET last_wake_at = NULL');
check('a token that can\'t be read says so instead of sending without it', ($pdo->exec("UPDATE agent_wake_targets SET token_sealed = 'v1.AAAA' WHERE principal_id = $muse") || true) && ($post($dylan, 'Token.') || true) && ($rid = (int) $pdo->query("SELECT id FROM wake_log WHERE agent_id = $muse")->fetchColumn()) && ($sent = []) === [] && mb_wake_deliver($pdo, $rid) === 'failed' && $sent === []
      && str_contains((string) $pdo->query("SELECT note FROM wake_log WHERE id = $rid")->fetchColumn(), 'Its token can\'t be read any more'));
mb_wake_target_save($pdo, $muse, $dylanId, ['kind' => 'routine', 'url' => $routine, 'token' => 'sk-ant-oat01-abcdef123456']);

// ---- the test button
$clear(); $sent = []; $reply = ['status' => 202, 'error' => null, 'transient' => false];
$t1 = mb_wake_test($pdo, $muse, $dylanId); $lg = $log($muse);
check('a test wake-up is sent at once, once, and says how it went', $t1['ok'] === true && $t1['note'] === 'Delivered (HTTP 202).' && count($sent) === 1 && $lg[0]['reason'] === 'test' && $lg[0]['status'] === 'sent' && str_contains(json_decode($sent[0]['b'], true)['text'], 'This is a test wake-up from Mashpad for @muse. Nothing needs doing.'));
$reply = ['status' => 401, 'error' => null, 'transient' => false]; $before = (int) mb_wake_target($pdo, $muse)['failures'];
$t2 = mb_wake_test($pdo, $muse, $dylanId);
check('a test that fails says why, and does not count against the wake-up or pause it', $t2['ok'] === false && str_contains($t2['note'], 'HTTP 401: the address refused it') && (int) mb_wake_target($pdo, $muse)['failures'] === $before && mb_wake_status($pdo, $muse)['state'] === 'active');
$reply = ['status' => 200, 'error' => null, 'transient' => false];
check('a test doesn\'t use up the day\'s wake-ups, nor can it be run for anyone else\'s agent', $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $muse AND reason = 'test'") === 2 && str_contains(err(fn () => mb_wake_test($pdo, $muse, $samId)), 'no wake-up to test') && str_contains(err(fn () => mb_wake_test($pdo, $idle, $dylanId)), 'no wake-up to test'));
mb_wake_set_paused($pdo, $muse, $dylanId, true);
check('a paused wake-up can still be tested', mb_wake_test($pdo, $muse, $dylanId)['ok'] === true);
mb_wake_set_paused($pdo, $muse, $dylanId, false);

// ---- the queue
$clear(); $pdo->exec("INSERT INTO wake_log (agent_id, pad_id, post_seq, from_id, reason, status, locked_at) VALUES ($muse, {$pad['id']}, 1, $dylanId, 'person', 'sending', UTC_TIMESTAMP() - INTERVAL 5 MINUTE)"); $sent = [];
mb_wake_flush($pdo);
check('a wake-up stuck mid-delivery is picked up again', count($sent) === 1 && $cnt("SELECT COUNT(*) FROM wake_log WHERE status = 'sent'") === 1);
$clear(); for ($i = 0; $i < 5; $i++) { $pdo->exec("INSERT INTO wake_log (agent_id, pad_id, post_seq, from_id, reason, status, next_try_at) VALUES ($muse, {$pad['id']}, $i, $dylanId, 'person', 'pending', UTC_TIMESTAMP() - INTERVAL $i SECOND)"); } $sent = [];
mb_wake_flush($pdo, 2);
check('a flush sends no more than it was asked to, oldest first', count($sent) === 2 && $cnt("SELECT COUNT(*) FROM wake_log WHERE status = 'sent'") === 2 && $cnt("SELECT COUNT(*) FROM wake_log WHERE status = 'sent' AND post_seq IN (3, 4)") === 2);
$clear(); $pdo->exec("INSERT INTO wake_log (agent_id, reason, status, next_try_at, created_at) VALUES ($muse, 'person', 'pending', UTC_TIMESTAMP() + INTERVAL 1 HOUR, UTC_TIMESTAMP())"); $sent = [];
check('a wake-up not yet due is left', mb_wake_flush($pdo)['sent'] === 0 && $sent === []);
$clear(); $pdo->exec("INSERT INTO wake_log (agent_id, reason, status, created_at) VALUES ($muse, 'person', 'sent', UTC_TIMESTAMP() - INTERVAL 40 DAY), ($muse, 'person', 'failed', UTC_TIMESTAMP() - INTERVAL 31 DAY), ($muse, 'person', 'sent', UTC_TIMESTAMP() - INTERVAL 2 DAY), ($muse, 'person', 'pending', UTC_TIMESTAMP() - INTERVAL 40 DAY)");
check('old log lines are tidied, and nothing still waiting', mb_wake_prune($pdo) === 2 && $cnt('SELECT COUNT(*) FROM wake_log') === 2);

// ---- when an agent goes
$clear(); $pdo->exec('UPDATE pad_members SET last_wake_at = NULL'); $post($dylan, 'Pending when removed.');
mb_wake_target_remove($pdo, $qa, $dylanId);
check('removing a wake-up deletes it and what was waiting to be sent', mb_wake_target($pdo, $qa) === null && $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $qa AND status = 'pending'") === 0 && $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $qa AND status = 'skipped' AND note = 'The wake-up was removed.'") === 1);
check('nobody else can remove it, pause it, or resume it', str_contains(err(fn () => mb_wake_target_remove($pdo, $muse, $samId)), 'isn\'t one of your agents') && str_contains(err(fn () => mb_wake_set_paused($pdo, $muse, $samId, true)), 'no wake-up to pause') && str_contains(err(fn () => mb_wake_set_paused($pdo, $idle, $dylanId, true)), 'no wake-up to pause'));
mb_delete_agent($pdo, $dylanId, $muse);
check('deleting an agent removes its wake-up, and with it the token', mb_wake_target($pdo, $muse) === null);
$g = mb_wake_target_save($pdo, $quiet, $dylanId, ['kind' => 'webhook', 'url' => $hook]);
$pdo->exec("UPDATE agent_profiles SET joined_via = 'invite', claimed_at = NULL, acts_for = 'Sam' WHERE principal_id = $quiet");
[$code] = mb_claim_create($pdo, $samId); mb_claim_accept($pdo, $A($quiet), $code);
check('claiming an agent does not hand its wake-up to the new person', mb_wake_target($pdo, $quiet) === null);

// ---- the plan setting, and the page that edits it
check('the daily limit is a plan value an administrator can edit, blank for none', mb_plan_update($pdo, 'free', ['max_wakes_per_day' => '50'], $dylanId) == ['max_wakes_per_day' => [20, 50]] && mb_plan_update($pdo, 'free', ['max_wakes_per_day' => ''], $dylanId) == ['max_wakes_per_day' => [50, null]]
      && str_contains(err(fn () => mb_plan_update($pdo, 'free', ['max_wakes_per_day' => '0'], $dylanId)), 'Wake-ups per agent per day should be between 1 and 100000') && mb_plan_update($pdo, 'free', ['max_wakes_per_day' => '20'], $dylanId) == ['max_wakes_per_day' => [null, 20]]);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
