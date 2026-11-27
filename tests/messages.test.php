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
require_once "$root/src/messages.php";
$GLOBALS['mb_wake_no_defer'] = true; $GLOBALS['mb_messages_no_random_prune'] = true;
$sent = []; $ips = ['93.184.216.34'];
$GLOBALS['mb_cimd_resolver'] = function ($h) use (&$ips) { return $ips; };
$GLOBALS['mb_wake_http']     = function ($m, $u, $h, $b, $host, $ip) use (&$sent) { $sent[] = compact('m', 'u', 'h', 'b'); return ['status' => 200, 'error' => null, 'transient' => false]; };
$mk = fn (string $h) => mb_create_human($pdo, ucfirst($h), $h, "$h@example.com", 'correct horse battery', $h === 'dylan');
$dylanId = $mk('dylan'); $samId = $mk('sam');
$pdo->exec('UPDATE plans SET max_human_seats = NULL, max_agents = NULL, post_interval_seconds = 0');
$dylan = mb_principal($pdo, $dylanId);
$agent = fn (int $owner, string $h) => mb_create_agent($pdo, $owner, ['handle' => $h])[0];
$muse = $agent($dylanId, 'muse'); $qa = $agent($dylanId, 'qa-bot'); $third = $agent($dylanId, 'third-one'); $stranger = $agent($samId, 'sams-agent');
$A = fn (int $id) => mb_principal($pdo, $id);
$cnt = fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();
$allow = fn (int $id, array $on) => $pdo->exec('UPDATE agent_profiles SET can_message_agents = ' . (int) in_array('msg', $on) . ', can_join_account_pads = ' . (int) in_array('join', $on) . ', can_create_pads = ' . (int) in_array('create', $on) . " WHERE principal_id = $id");

// ---- the permissions
check('every permission is off for a new agent', $A($muse)['can_create_pads'] == 0 && $A($muse)['can_message_agents'] == 0 && $A($muse)['can_join_account_pads'] == 0 && array_keys(MB_AGENT_PERMISSIONS) === ['can_create_pads', 'can_message_agents', 'can_receive_outside_messages', 'can_join_account_pads']);
$ch = mb_agent_set_permissions($pdo, $dylanId, $A($muse), ['can_message_agents' => '1', 'can_join_account_pads' => '1']);
check('a person sets them with ticked boxes, and is told what changed', $ch === ['can_message_agents' => true, 'can_join_account_pads' => true] && $A($muse)['can_message_agents'] == 1 && $A($muse)['can_join_account_pads'] == 1 && $A($muse)['can_create_pads'] == 0);
check('each change is recorded, by whom', $cnt("SELECT COUNT(*) FROM audit_log WHERE action = 'agent.permission' AND actor_id = $dylanId AND subject = 'principal:$muse' AND detail IN ('can_message_agents=1', 'can_join_account_pads=1')") === 2);
check('saving the same again changes nothing and records nothing', mb_agent_set_permissions($pdo, $dylanId, $A($muse), ['can_message_agents' => '1', 'can_join_account_pads' => '1']) === [] && $cnt("SELECT COUNT(*) FROM audit_log WHERE action = 'agent.permission'") === 2);
check('a box left unticked turns it off', mb_agent_set_permissions($pdo, $dylanId, $A($muse), ['can_message_agents' => '1']) === ['can_join_account_pads' => false] && $A($muse)['can_join_account_pads'] == 0);
check('only the person responsible can, and not for someone else\'s agent', str_contains(err(fn () => mb_agent_set_permissions($pdo, $samId, $A($muse), ['can_create_pads' => '1'])), 'isn\'t one of your agents') && $A($muse)['can_create_pads'] == 0);
$pad = mb_create_pad($pdo, $dylan, 'Launch'); $inv = mb_invite_make($pdo, mb_pad_by_address($pdo, 'dylan', 'launch'), $dylanId);
$guest = mb_invite_guest_agent($pdo, mb_pad_by_address($pdo, 'dylan', 'launch'), ['handle' => 'stranger', 'acts_for' => 'Sam'], false)[0];
check('an agent that joined on its own can\'t be given permissions until it is claimed', str_contains(err(fn () => mb_agent_set_permissions($pdo, $dylanId, $A((int) $guest['id']), ['can_message_agents' => '1'])), 'hasn\'t been claimed yet'));
$allow($muse, []);

// ---- private messages
$e = err(fn () => mb_message_send($pdo, $A($muse), 'qa-bot', 'Hello'));
check('without the permission a send is refused, saying who can fix it, and nothing is saved', str_contains($e, 'MbAccountError') && str_contains($e, 'your person hasn\'t allowed you to send private messages') && str_contains($e, 'Can send private messages to my other agents') && $cnt('SELECT COUNT(*) FROM agent_messages') === 0, $e);
$allow($muse, ['msg']);
$r = mb_message_send($pdo, $A($muse), '@QA-Bot', "The plan is in dylan/launch.\nPlease look at it.");
check('with it, a message goes to another of the person\'s agents, however the handle is written', $r['to'] === 'qa-bot' && $cnt('SELECT COUNT(*) FROM agent_messages') === 1 && ($row = $pdo->query('SELECT * FROM agent_messages')->fetch()) && (int) $row['owner_id'] === $dylanId && (int) $row['from_id'] === $muse && (int) $row['to_id'] === $qa && $row['read_at'] === null);
check('the recipient has one unread, and needs no permission to read it', mb_messages_unread($pdo, $qa) === 1 && $A($qa)['can_message_agents'] == 0 && ($got = mb_messages_read($pdo, $A($qa))) && count($got) === 1 && $got[0]['from'] === 'muse' && $got[0]['was_unread'] === true && str_contains($got[0]['body'], 'Please look at it.'));
check('reading marks it read, once', mb_messages_unread($pdo, $qa) === 0 && mb_messages_read($pdo, $A($qa))[0]['was_unread'] === false && $pdo->query("SELECT last_messages_read_at FROM agent_profiles WHERE principal_id = $qa")->fetchColumn() !== null);
check('the recipient can read it but can\'t reply without its own permission', str_contains(err(fn () => mb_message_send($pdo, $A($qa), 'muse', 'Thanks')), 'hasn\'t allowed you to send private messages'));
$allow($qa, ['msg']); mb_message_send($pdo, $A($qa), 'muse', 'Thanks, looking now.');
$conv = mb_messages_read($pdo, $A($muse), 'qa-bot');
check('with "with" it is the whole conversation, both ways, oldest first', array_column($conv, 'from') === ['muse', 'qa-bot'] && $conv[1]['was_unread'] === true && count(mb_messages_read($pdo, $A($muse))) === 1);
check('since_id returns only what is newer, and an unknown agent is refused', count(mb_messages_read($pdo, $A($muse), 'qa-bot', (int) $conv[0]['id'])) === 1 && str_contains(err(fn () => mb_messages_read($pdo, $A($muse), 'nobody')), 'There is no agent @nobody among the agents you have messages with or your person\'s agents.'));
$e = err(fn () => mb_message_send($pdo, $A($muse), 'sams-agent', 'Hi'));
check('an agent of another person that is on none of the person\'s pads can\'t be messaged, and is not named', str_contains($e, 'there is no agent @sams-agent you can message') && str_contains($e, 'Your person\'s agents: @qa-bot, @third-one.') && !str_contains($e, 'stranger'), $e);
check('nor an agent still waiting to be claimed, nor one that has been removed, nor yourself', str_contains(err(fn () => mb_message_send($pdo, $A($muse), 'stranger', 'Hi')), 'no agent @stranger') && ($removed = $agent($dylanId, 'gone-soon')) && (mb_delete_agent($pdo, $dylanId, $removed) || true) && str_contains(err(fn () => mb_message_send($pdo, $A($muse), 'gone-soon', 'Hi')), 'no agent @gone-soon') && str_contains(err(fn () => mb_message_send($pdo, $A($muse), 'muse', 'Hi')), 'that is you'));
$rows = array_column(mb_agents_of($pdo, $dylanId), null, 'handle');
check('the list of a person\'s agents says which are guests that have not been claimed, so Your agents can show the claim line and no form', mb_is_guest_agent($rows['stranger']) === true && mb_is_guest_agent($rows['muse']) === false && mb_is_guest_agent($rows['qa-bot']) === false);
check('an agent that is its person\'s only one is told so', ($only = $agent($samId, 'lonely')) === ($only) && ($pdo->exec("UPDATE principals SET disabled_at = UTC_TIMESTAMP() WHERE id = $stranger") || true) && ($allow($only, ['msg']) || true) && str_contains(err(fn () => mb_message_send($pdo, $A($only), 'anyone', 'Hi')), 'You are your person\'s only agent.'));
check('a message must have something in it and not be too long, and says so in plain words', str_contains($e1 = err(fn () => mb_message_send($pdo, $A($muse), 'qa-bot', '   ')), 'Not sent: Message is empty. Your text was not saved.') && str_contains($e2 = err(fn () => mb_message_send($pdo, $A($muse), 'qa-bot', str_repeat('x', 200000))), 'Message is too long') && str_contains($e2, 'Split it into several messages.') && $cnt('SELECT COUNT(*) FROM agent_messages WHERE body = \'\'') === 0, $e1 . ' | ' . $e2);
check('the person sees every message between their agents, newest first, and nobody else sees any', array_column(mb_messages_for_owner($pdo, $dylanId), 'body') === ['Thanks, looking now.', "The plan is in dylan/launch.\nPlease look at it."] && mb_messages_for_owner($pdo, $samId) === [] && count(mb_messages_for_owner($pdo, $dylanId, 1)) === 1 && count(mb_messages_for_owner($pdo, $dylanId, 50, (int) mb_messages_for_owner($pdo, $dylanId)[0]['id'])) === 1);
check('another person\'s agent can\'t read them, even by asking for the conversation', mb_messages_read($pdo, $A($only)) === [] && str_contains(err(fn () => mb_messages_read($pdo, $A($only), 'qa-bot')), 'There is no agent @qa-bot'));
check('they are never on a pad', $cnt("SELECT COUNT(*) FROM messages WHERE body LIKE '%Please look at it%'") === 0);

// ---- limits
$pdo->exec('DELETE FROM agent_messages'); $allow($third, ['msg']);
for ($i = 1; $i <= MB_MSG_PER_HOUR; $i++) { mb_message_send($pdo, $A($third), 'muse', "m$i"); }
check('one agent can send twenty an hour, and then is told when to try again', str_contains($e = err(fn () => mb_message_send($pdo, $A($third), 'muse', 'one more')), 'you have sent 20 private messages in the last hour') && preg_match('/after \d\d:\d\d UTC/', $e) && $cnt("SELECT COUNT(*) FROM agent_messages WHERE from_id = $third") === 20, $e);
check('another agent is not held back by that', mb_message_send($pdo, $A($muse), 'qa-bot', 'still fine')['to'] === 'qa-bot');
$pdo->exec('DELETE FROM agent_messages'); $pdo->exec("UPDATE plans SET max_agent_messages_per_day = 3 WHERE code = 'free'");
mb_message_send($pdo, $A($muse), 'qa-bot', '1'); mb_message_send($pdo, $A($qa), 'muse', '2'); mb_message_send($pdo, $A($third), 'muse', '3');
check('the plan\'s daily limit counts every agent of the person together, and says so', str_contains($e = err(fn () => mb_message_send($pdo, $A($muse), 'qa-bot', '4')), 'your person\'s plan allows 3 private messages a day across their agents') && $cnt('SELECT COUNT(*) FROM agent_messages') === 3, $e);
$pdo->exec("UPDATE plans SET max_agent_messages_per_day = NULL WHERE code = 'free'");
check('with no limit it goes through', mb_message_send($pdo, $A($muse), 'qa-bot', '4')['to'] === 'qa-bot');
$pdo->exec("UPDATE plans SET max_agent_messages_per_day = 100 WHERE code = 'free'");
check('the seeds are 100 on Free and 1000 on Plus, and an administrator can edit them', array_column(mb_plans($pdo), 'max_agent_messages_per_day', 'code') == ['free' => 100, 'plus' => 1000] && mb_plan_update($pdo, 'free', ['max_agent_messages_per_day' => '250'], $dylanId) == ['max_agent_messages_per_day' => [100, 250]] && str_contains(err(fn () => mb_plan_update($pdo, 'free', ['max_agent_messages_per_day' => '0'], $dylanId)), 'between 1 and 1000000'));
$pdo->exec("UPDATE plans SET max_agent_messages_per_day = 100 WHERE code = 'free'");

// ---- how long they are kept
$pdo->exec('DELETE FROM agent_messages'); mb_message_send($pdo, $A($muse), 'qa-bot', 'fresh'); mb_message_send($pdo, $A($muse), 'qa-bot', 'old'); mb_message_send($pdo, $A($muse), 'qa-bot', 'older but on a plan that keeps it');
$ids = $pdo->query('SELECT id FROM agent_messages ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$pdo->exec("UPDATE agent_messages SET created_at = UTC_TIMESTAMP() - INTERVAL 31 DAY WHERE id = {$ids[1]}");
$pdo->exec("UPDATE agent_messages SET created_at = UTC_TIMESTAMP() - INTERVAL 31 DAY WHERE id = {$ids[2]}");
check('a message is kept as long as its person\'s plan keeps pads: older than that it is removed, newer stays', mb_messages_prune($pdo) === 2 && $cnt('SELECT COUNT(*) FROM agent_messages') === 1 && $pdo->query('SELECT body FROM agent_messages')->fetchColumn() === 'fresh');
$pdo->exec("UPDATE principals SET plan_code = 'plus' WHERE id = $dylanId"); mb_message_send($pdo, $A($muse), 'qa-bot', 'on plus'); $pdo->exec('UPDATE agent_messages SET created_at = UTC_TIMESTAMP() - INTERVAL 100 DAY');
check('on Plus it is kept six months', mb_messages_prune($pdo) === 0 && $cnt('SELECT COUNT(*) FROM agent_messages') === 2);
$pdo->exec("UPDATE principals SET plan_code = 'free' WHERE id = $dylanId"); $pdo->exec('DELETE FROM agent_messages');

// ---- waking a recipient
$hook = 'https://hooks.example.com/wake/q1';
mb_wake_target_save($pdo, $qa, $dylanId, ['kind' => 'webhook', 'url' => $hook]);
$clear = function () use ($pdo) { $pdo->exec('DELETE FROM wake_log'); $pdo->exec('DELETE FROM agent_messages'); $pdo->exec('UPDATE agent_profiles SET last_messages_read_at = NULL'); };
mb_message_send($pdo, $A($muse), 'qa-bot', 'Secret detail: the launch is on Tuesday.');
$w = $pdo->query("SELECT * FROM wake_log WHERE agent_id = $qa")->fetchAll();
check('a message wakes a recipient that has a wake-up, as a message, with no pad', count($w) === 1 && $w[0]['reason'] === 'message' && $w[0]['pad_id'] === null && (int) $w[0]['from_id'] === $muse && $w[0]['status'] === 'pending');
$sent = []; mb_wake_flush($pdo); $body = json_decode($sent[0]['b'] ?? '{}', true);
check('what it is sent says who and what to do, and carries nothing that was said', ($body['message'] ?? '') === '@muse sent you a private message. Read it with read_messages.' && ($body['reason'] ?? '') === 'message' && $body['pad'] === null && !str_contains($sent[0]['b'], 'Tuesday') && !str_contains($sent[0]['b'], 'Secret'));
mb_message_send($pdo, $A($muse), 'qa-bot', 'A second one, straight away.');
check('while it hasn\'t read its messages, more do not wake it again', $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $qa AND reason = 'message'") === 1);
sleep(1); mb_messages_read($pdo, $A($qa)); mb_message_send($pdo, $A($muse), 'qa-bot', 'A third, after it read.');        // the read has to be after the wake-up, which is recorded to the second
check('once it has read them it can be woken again', $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $qa AND reason = 'message'") === 2);
mb_wake_set_paused($pdo, $qa, $dylanId, true); $clear(); mb_message_send($pdo, $A($muse), 'qa-bot', 'to a paused one');
check('a paused wake-up is not used, and an agent with none is simply not woken', $cnt('SELECT COUNT(*) FROM wake_log') === 0 && (mb_message_send($pdo, $A($qa), 'muse', 'to one with none') !== null) && $cnt('SELECT COUNT(*) FROM wake_log') === 0);
mb_wake_set_paused($pdo, $qa, $dylanId, false); $clear(); $pdo->exec("UPDATE plans SET max_wakes_per_day = 1 WHERE code = 'free'");
$pdo->exec("INSERT INTO wake_log (agent_id, reason, status) VALUES ($qa, 'person', 'sent')"); mb_message_send($pdo, $A($muse), 'qa-bot', 'over the daily wake limit');
check('the daily wake limit applies, and the message is still delivered', $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $qa AND reason = 'message' AND status <> 'skipped'") === 0 && mb_messages_unread($pdo, $qa) === 1);
$pdo->exec("UPDATE plans SET max_wakes_per_day = 20 WHERE code = 'free'"); $pdo->exec('DELETE FROM wake_log');

// ---- the daily wake limit for a message is the recipient's owner's, not the Free plan's by default
$clear(); $pdo->exec("UPDATE principals SET plan_code = 'plus' WHERE id = $dylanId");
for ($i = 0; $i < 25; $i++) { $pdo->exec("INSERT INTO wake_log (agent_id, reason, status) VALUES ($qa, 'person', 'sent')"); }
set_error_handler(function ($n, $s) { throw new ErrorException($s); });
try { mb_message_send($pdo, $A($muse), 'qa-bot', 'a wake on a plan with a bigger limit'); $warned = false; } catch (ErrorException $ex) { $warned = $ex->getMessage(); }
restore_error_handler();
check('on Plus, with 25 wake-ups used today and a limit of 200, a message does wake the recipient, and nothing warns', $warned === false && $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $qa AND reason = 'message' AND status = 'pending'") === 1, (string) $warned);
$clear(); $pdo->exec("UPDATE principals SET plan_code = 'free' WHERE id = $dylanId");
for ($i = 0; $i < 25; $i++) { $pdo->exec("INSERT INTO wake_log (agent_id, reason, status) VALUES ($qa, 'person', 'sent')"); }
mb_message_send($pdo, $A($muse), 'qa-bot', 'the same on Free');
check('on Free, the same 25 is over the limit of 20, so it does not, and the message is still delivered', $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $qa AND reason = 'message' AND status = 'pending'") === 0 && mb_messages_unread($pdo, $qa) === 1);
$clear();

// ---- telling a sender that a recipient may not be able to reply, and the person too
$clear(); $allow($qa, []); $allow($muse, ['msg']);
$r = mb_message_send($pdo, $A($muse), 'qa-bot', 'Can you reply?');
check('a message to an agent that isn\'t allowed to send still goes through, and the sender is told it may not get a reply', $r['reply_blocked'] === true && $r['outside'] === false && mb_messages_unread($pdo, $qa) === 1);
$cr = mb_messages_cant_reply($pdo, $dylanId);
check('and the person is warned: which agents, how many messages, and that it is their own agent to switch on', count($cr) === 1 && $cr[0]['handle'] === 'qa-bot' && $cr[0]['count'] === 1 && $cr[0]['own'] === true);
$allow($qa, ['msg']);
check('allowed to send, it is not blocked, and the warning goes', mb_message_send($pdo, $A($muse), 'qa-bot', 'And now?')['reply_blocked'] === false && mb_messages_cant_reply($pdo, $dylanId) === []);
$allow($qa, []);

// ---- reaching other people's agents on the person's pads
$lee = $mk('lee'); $clear(); $allow($muse, ['msg']);
$pad2 = mb_create_pad($pdo, $dylan, 'Side Pad'); $launch = mb_pad_by_address($pdo, 'dylan', 'launch'); $side = mb_pad_by_address($pdo, 'dylan', 'side-pad');
$envoy = $agent($samId, 'sam-envoy'); mb_add_member($pdo, $launch, $envoy, 'added'); $stray = $agent($samId, 'sam-stray');
$e = err(fn () => mb_message_send($pdo, $A($muse), 'sam-envoy', 'Hello from Dylan\'s agent'));
check('another person\'s agent on one of the person\'s pads can be addressed, but is refused unless its own person allows it, in words that say who can', str_contains($e, 'Not sent: @sam-envoy doesn\'t take private messages from other people\'s agents') && str_contains($e, 'Can receive private messages from other people\'s agents') && $cnt('SELECT COUNT(*) FROM agent_messages') === 0, $e);
check('one that is on none of the person\'s pads is not reachable at all', str_contains(err(fn () => mb_message_send($pdo, $A($muse), 'sam-stray', 'hi')), 'there is no agent @sam-stray you can message') && str_contains(err(fn () => mb_message_send($pdo, $A($muse), 'sam-envoy', 'hi')), 'Not sent: @sam-envoy doesn\'t take'));
$pdo->exec("UPDATE agent_profiles SET can_receive_outside_messages = 1 WHERE principal_id = $envoy");
mb_wake_target_save($pdo, $envoy, $samId, ['kind' => 'webhook', 'url' => 'https://hooks.example.com/wake/envoy']);
$r = mb_message_send($pdo, $A($muse), 'sam-envoy', 'Secret detail: the plan is on Tuesday.');
check('once it is allowed, it is delivered, marked as from another person\'s agent, and the sender is told it may not reply and that only its person can change that', $r['outside'] === true && $r['reply_blocked'] === true && ($row = $pdo->query('SELECT * FROM agent_messages')->fetch()) && (int) $row['owner_id'] === $dylanId && (int) $row['to_owner_id'] === $samId);
check('it never wakes the other person\'s agent, even one with a wake-up', $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $envoy") === 0);
$got = mb_messages_read($pdo, $A($envoy));
check('the recipient reads it marked as from another person\'s agent, and says whose', count($got) === 1 && $got[0]['outside'] === true && $got[0]['from_person'] === 'dylan' && $got[0]['was_unread'] === true);
check('both people can read it, and no one else', count(mb_messages_for_owner($pdo, $dylanId)) === 1 && count(mb_messages_for_owner($pdo, $samId)) === 1 && mb_messages_for_owner($pdo, $lee) === [] && mb_messages_for_owner($pdo, $samId)[0]['from_owner'] === 'dylan' && mb_messages_for_owner($pdo, $samId)[0]['to_owner'] === 'sam');
$cr = mb_messages_cant_reply($pdo, $dylanId);
check('the sender\'s person is warned about it too, told it is another person\'s agent so not theirs to switch on', count($cr) === 1 && $cr[0]['handle'] === 'sam-envoy' && $cr[0]['own'] === false);
check('the other agent can\'t reply without its own permission, and a third person\'s agent can\'t read the conversation', str_contains(err(fn () => mb_message_send($pdo, $A($envoy), 'muse', 'ok')), 'hasn\'t allowed you to send private messages') && mb_messages_read($pdo, $A($stray)) === []);
$pdo->exec("UPDATE agent_profiles SET can_message_agents = 1 WHERE principal_id = $envoy");
check('once it may send, it can reply to the agent that wrote to it, even though that agent is on none of its person\'s pads, but only if that agent\'s person allows receiving', str_contains($e = err(fn () => mb_message_send($pdo, $A($envoy), 'muse', 'Thanks, understood.')), 'Not sent: @muse doesn\'t take private messages from other people\'s agents'), $e);
$pdo->exec("UPDATE agent_profiles SET can_receive_outside_messages = 1 WHERE principal_id = $muse");
$r2 = mb_message_send($pdo, $A($envoy), 'muse', 'Thanks, understood.');
check('allowed, the reply goes, marked as from another person\'s agent, delivered and read by muse, and never waking it', $r2['outside'] === true && $r2['reply_blocked'] === false && mb_messages_read($pdo, $A($muse))[0]['from_person'] === 'sam' && $cnt("SELECT COUNT(*) FROM wake_log WHERE agent_id = $muse AND reason = 'message'") === 0);
check('an agent that has not written to it is still not reachable that way', str_contains(err(fn () => mb_message_send($pdo, $A($envoy), 'qa-bot', 'hi')), 'no agent @qa-bot you can message'));
$pdo->exec("UPDATE agent_profiles SET can_receive_outside_messages = 0 WHERE principal_id = $muse");
$pdo->exec("UPDATE agent_profiles SET can_message_agents = 0 WHERE principal_id = $envoy");
$gone = $agent($samId, 'sam-leaver'); $pdo->exec("UPDATE agent_profiles SET can_receive_outside_messages = 1 WHERE principal_id = $gone"); mb_add_member($pdo, $launch, $gone, 'added');
check('an agent on a pad can be messaged, and when it leaves the pad it can no longer be, for the same reason as any stranger', mb_message_send($pdo, $A($muse), 'sam-leaver', 'still there?')['outside'] === true && mb_remove_member($pdo, $launch, $gone, $dylanId) === null && str_contains(err(fn () => mb_message_send($pdo, $A($muse), 'sam-leaver', 'and now?')), 'there is no agent @sam-leaver you can message'));

// ---- guests, names, and flooding
$g = mb_invite_guest_agent($pdo, $launch, ['handle' => 'guest-x', 'acts_for' => 'Lee'], false)[0];
check('an agent that joined on its own and isn\'t claimed is not reachable, whoever it will turn out to act for', str_contains(err(fn () => mb_message_send($pdo, $A($muse), 'guest-x', 'hi')), 'no agent @guest-x you can message'));
$twinA = $agent($samId, 'twin'); $twinB = $agent($lee, 'twin'); $twinMine = $agent($dylanId, 'twin');
mb_add_member($pdo, $launch, $twinA, 'added'); mb_add_member($pdo, $side, $twinB, 'added');
foreach ([$twinA, $twinB] as $t2) { $pdo->exec("UPDATE agent_profiles SET can_receive_outside_messages = 1 WHERE principal_id = $t2"); }
check('your own agent with the name comes first, without any fuss', mb_message_send($pdo, $A($muse), 'twin', 'to my own')['outside'] === false);
$pdo->exec("UPDATE principals SET disabled_at = UTC_TIMESTAMP() WHERE id = $twinMine");
check('with two other people\'s agents on different pads going by the same name, it asks which', str_contains($e = err(fn () => mb_message_send($pdo, $A($muse), 'twin', 'which?')), 'more than one agent goes by @twin on your pads. Say which with pad') , $e);
check('and the pad settles it, one pad at a time', mb_message_send($pdo, $A($muse), 'twin', 'on launch', 'dylan/launch')['outside'] === true && (int) $pdo->query('SELECT to_id FROM agent_messages ORDER BY id DESC LIMIT 1')->fetchColumn() === $twinA && mb_message_send($pdo, $A($muse), 'twin', 'on side', 'https://mashpad.ai/pads/dylan/side-pad')['outside'] === true && (int) $pdo->query('SELECT to_id FROM agent_messages ORDER BY id DESC LIMIT 1')->fetchColumn() === $twinB);
check('a pad that isn\'t the person\'s is refused, and a pad the agent isn\'t on finds no one', str_contains(err(fn () => mb_message_send($pdo, $A($muse), 'twin', 'x', 'sam/whatever')), 'that isn\'t one of your person\'s pads') && str_contains(err(fn () => mb_message_send($pdo, $A($muse), 'sam-envoy', 'x', 'dylan/side-pad')), 'no agent @sam-envoy you can message on that pad'));
$pdo->exec('DELETE FROM agent_messages'); $pdo->exec("UPDATE agent_profiles SET can_message_agents = 1 WHERE principal_id IN ($muse, $third, $qa)");
for ($i = 1; $i <= MB_MSG_OUTSIDE_PER_HOUR; $i++) { mb_message_send($pdo, $A($i % 2 ? $muse : $third), 'sam-envoy', "m$i"); }
check('a person\'s agent can only be written to ten times an hour by other people\'s agents, however many senders', str_contains($e = err(fn () => mb_message_send($pdo, $A($qa), 'sam-envoy', 'one too many')), 'has had 10 private messages from other people\'s agents in the last hour') && $cnt('SELECT COUNT(*) FROM agent_messages WHERE to_id = ' . $envoy) === 10, $e);
$pdo->exec("UPDATE agent_profiles SET can_message_agents = 1 WHERE principal_id = $envoy");
check('while its own person\'s agents are not held to that', mb_message_send($pdo, $A($envoy), 'sam-stray', 'between us')['outside'] === false);
$pdo->exec('DELETE FROM agent_messages');

// ---- the receiving permission is one of the permissions, off by default, and claiming resets it
check('there is a fourth permission, off for a new agent, and it is set with the others', array_key_exists('can_receive_outside_messages', MB_AGENT_PERMISSIONS) && $A($agent($dylanId, 'fresh-one'))['can_receive_outside_messages'] == 0 && mb_agent_set_permissions($pdo, $dylanId, $A($muse), ['can_receive_outside_messages' => '1', 'can_message_agents' => '1']) == ['can_receive_outside_messages' => true] && $A($muse)['can_receive_outside_messages'] == 1);

// ---- finding and joining the person's own pads
$secret = mb_create_pad($pdo, $dylan, 'Secret Plans'); mb_create_pad($pdo, $dylan, 'Budget Review'); $sam = mb_principal($pdo, $samId); mb_create_pad($pdo, $sam, 'Sams Pad');
$allow($muse, []);
check('without the permission an agent can neither find nor join, and is told who can fix it', str_contains(err(fn () => mb_account_pads($pdo, $A($muse))), 'hasn\'t allowed you to find and join their other pads') && str_contains(err(fn () => mb_join_account_pad($pdo, $A($muse), 'dylan/secret-plans')), 'Can find and join my other pads') && mb_membership($pdo, (int) $secret['id'], $muse) === null);
$allow($muse, ['join']); mb_add_member($pdo, mb_pad_by_address($pdo, 'dylan', 'launch'), $muse, 'added');
$found = mb_account_pads($pdo, $A($muse));
check('with it, an agent finds its person\'s pads it isn\'t on, by name, newest activity first, and not the ones it is on or anyone else\'s', array_column($found, 'slug') !== [] && !in_array('launch', array_column($found, 'slug'), true) && !in_array('sams-pad', array_column($found, 'slug'), true) && count($found) === 3 && isset($found[0]['members']) && !isset($found[0]['body']));
check('it can narrow them by name', array_column(mb_account_pads($pdo, $A($muse), 'secret'), 'slug') === ['secret-plans'] && mb_account_pads($pdo, $A($muse), 'zzz') === []);
[$joined, $already] = mb_join_account_pad($pdo, $A($muse), 'https://mashpad.ai/pads/dylan/secret-plans');
check('it joins one with no code and no approval, from a link', (int) $joined['id'] === (int) $secret['id'] && $already === false && mb_membership($pdo, (int) $secret['id'], $muse) !== null && $pdo->query("SELECT joined_via FROM pad_members WHERE pad_id = {$secret['id']} AND principal_id = $muse")->fetchColumn() === 'account');
check('the pad shows that it joined, and why', ($ev = $pdo->query("SELECT body FROM messages WHERE pad_id = {$secret['id']} AND kind = 'event' ORDER BY id DESC LIMIT 1")->fetchColumn()) && str_contains($ev, '@muse joined, using the permission its person gave it to join their pads.'), (string) $ev);
check('joining again says it is already there, and changes nothing', mb_join_account_pad($pdo, $A($muse), 'dylan/secret-plans')[1] === true && $cnt("SELECT COUNT(*) FROM pad_members WHERE pad_id = {$secret['id']} AND principal_id = $muse") === 1);
check('a bare name works while it is only one of the person\'s pads', mb_join_account_pad($pdo, $A($muse), 'budget-review')[0]['slug'] === 'budget-review');
check('someone else\'s pad, and one that does not exist, are refused in the same words, whatever the address', ($e1 = err(fn () => mb_join_account_pad($pdo, $A($muse), 'sam/sams-pad'))) === ($e2 = err(fn () => mb_join_account_pad($pdo, $A($muse), 'sam/not-a-pad'))) && str_contains($e1, 'That isn\'t one of your person\'s pads. Call find_pads'), $e1);
$allow($muse, []);
check('switching the permission off stops new joins but doesn\'t remove it from pads it is on', mb_membership($pdo, (int) $secret['id'], $muse) !== null && str_contains(err(fn () => mb_account_pads($pdo, $A($muse))), 'hasn\'t allowed you'));
$allow($qa, ['join']); $pdo->exec("UPDATE agent_profiles SET claimed_at = NULL, joined_via = 'invite' WHERE principal_id = $qa");
check('an agent that joined on its own can\'t use it until it is claimed', str_contains(err(fn () => mb_account_pads($pdo, $A($qa))), 'Only an agent its person has claimed'));

// ---- a claim hands over nothing
$pdo->exec("UPDATE agent_profiles SET can_create_pads = 1, can_message_agents = 1, can_join_account_pads = 1 WHERE principal_id = $qa"); [$code] = mb_claim_create($pdo, $samId); mb_claim_accept($pdo, $A($qa), $code);
check('claiming an agent resets what it may do: the new person decides', $A($qa)['can_create_pads'] == 0 && $A($qa)['can_message_agents'] == 0 && $A($qa)['can_join_account_pads'] == 0 && (int) $A($qa)['owner_id'] === $samId);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
