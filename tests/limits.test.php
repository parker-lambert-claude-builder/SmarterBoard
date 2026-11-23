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
require "$root/src/invites.php";
require_once "$root/src/claims.php";

$mk = fn (string $h) => mb_create_human($pdo, ucfirst($h), $h, "$h@example.com", 'correct horse battery', $h === 'dylan');
$dylanId = $mk('dylan'); $samId = $mk('sam'); $leeId = $mk('lee'); $kimId = $mk('kim');
$principal = fn (int $id) => mb_principal($pdo, $id);
$dylan = $principal($dylanId);
$setPlan = fn (int $id, string $plan) => $pdo->exec("UPDATE principals SET plan_code = '$plan' WHERE id = $id");

// What the seeds say
$plans = array_column(mb_plans($pdo), null, 'code');
check('the plans start with one person per pad and three agents on Free, and no limit on people and twenty-five agents on Plus', (int) $plans['free']['max_human_seats'] === 1 && (int) $plans['free']['max_agents'] === 3 && $plans['plus']['max_human_seats'] === null && (int) $plans['plus']['max_agents'] === 25);
check('a plan is named as a person says it', mb_plan_short($plans['free']) === 'Free' && mb_plan_short($plans['plus']) === 'Plus' && mb_count_phrase(1, 'person', 'people') === 'one person' && mb_count_phrase(3, 'agent', 'agents') === '3 agents');

// ---- people per pad
$pad = mb_create_pad($pdo, $dylan, 'Launch');
$padOf = fn () => mb_pad_by_address($pdo, 'dylan', 'launch');
check('the owner is the one person on a new pad', mb_humans_on_pad($pdo, (int) $pad['id']) === 1 && mb_seat_state($pdo, $padOf())[0] === 1 && mb_seat_state($pdo, $padOf())[1] === 1 && mb_seat_room($pdo, $padOf()) === false);
$e = err(fn () => mb_add_member($pdo, $padOf(), $samId, 'added'));
check('on a Free pad a second person is refused, saying whose plan, how many it allows, and that agents don\'t count', str_contains($e, 'MbPlanLimit') && str_contains($e, 'It is on @dylan\'s Free plan, which has room for one person on a pad, and it has 1') && str_contains($e, 'Agents don\'t count, so an agent can still join'), $e);
check('and they are not on it', mb_humans_on_pad($pdo, (int) $pad['id']) === 1 && !mb_membership($pdo, (int) $pad['id'], $samId));
$inv = mb_invite_make($pdo, $padOf(), $dylanId);
check('through the invitation link too, with no approval step', str_contains(err(fn () => mb_invite_use($pdo, $inv, $padOf(), $principal($samId))), 'no room for another person on this pad') && mb_humans_on_pad($pdo, (int) $pad['id']) === 1);
$pdo->exec("UPDATE pad_invites SET require_approval = 1 WHERE pad_id = {$pad['id']}"); $inv = mb_invite_make($pdo, $padOf(), $dylanId);
check('and with approval: the request is refused outright, so none is left waiting', str_contains(err(fn () => mb_invite_use($pdo, $inv, $padOf(), $principal($samId))), 'no room for another person') && (int) $pdo->query("SELECT COUNT(*) FROM pad_join_requests WHERE pad_id = {$pad['id']}")->fetchColumn() === 0);
check('an agent is never refused for lack of a seat', ($g = mb_invite_guest_agent($pdo, $padOf(), ['handle' => 'helper', 'acts_for' => 'Sam'], false)) && mb_invite_use($pdo, $inv, $padOf(), $g[0]) === 'requested' && mb_humans_on_pad($pdo, (int) $pad['id']) === 1);
$pdo->exec("UPDATE pad_invites SET require_approval = 0 WHERE pad_id = {$pad['id']}");
check('on Plus there is no limit on people, so a second and third person can be added', ($setPlan($dylanId, 'plus') || true) && mb_seat_room($pdo, $padOf()) === true && mb_add_member($pdo, $padOf(), $samId, 'added') === null && mb_add_member($pdo, $padOf(), $leeId, 'added') === null && mb_humans_on_pad($pdo, (int) $pad['id']) === 3);
$pdo->exec("UPDATE plans SET max_human_seats = 3 WHERE code = 'plus'");
check('a numeric limit counts the owner, and stops at it', mb_seat_room($pdo, $padOf()) === false && str_contains(err(fn () => mb_add_member($pdo, $padOf(), $kimId, 'added')), 'room for 3 people on a pad, and it has 3'));
$pdo->exec("UPDATE plans SET max_human_seats = 2 WHERE code = 'plus'");
check('lowering a limit below what a pad already has removes nobody, and only stops more', mb_humans_on_pad($pdo, (int) $pad['id']) === 3 && !!mb_membership($pdo, (int) $pad['id'], $leeId) && mb_seat_room($pdo, $padOf()) === false);
mb_remove_member($pdo, $padOf(), $leeId, $dylanId); mb_remove_member($pdo, $padOf(), $samId, $dylanId);
check('people who have left free their seats', mb_humans_on_pad($pdo, (int) $pad['id']) === 1 && mb_seat_room($pdo, $padOf()) === true);
mb_add_member($pdo, $padOf(), $samId, 'added');
check('and someone who left and returns needs a seat like anyone else', str_contains(err(fn () => mb_add_member($pdo, $padOf(), $leeId, 'added')), 'room for 2 people') && mb_humans_on_pad($pdo, (int) $pad['id']) === 2);
$pdo->exec("UPDATE plans SET max_human_seats = NULL WHERE code = 'plus'");
// a request waiting when the pad fills up stays waiting when approving is refused
$pdo->exec("UPDATE pad_invites SET require_approval = 1 WHERE pad_id = {$pad['id']}"); $inv = mb_invite_make($pdo, $padOf(), $dylanId);
mb_invite_use($pdo, $inv, $padOf(), $principal($leeId));
$rid = (int) $pdo->query("SELECT id FROM pad_join_requests WHERE pad_id = {$pad['id']}")->fetchColumn();
$pdo->exec("UPDATE plans SET max_human_seats = 2 WHERE code = 'plus'");
check('approving a person on a pad that has since filled is refused and leaves the request waiting', str_contains(err(fn () => mb_join_decide($pdo, $padOf(), $rid, true, $dylanId)), 'no room for another person') && (int) $pdo->query("SELECT COUNT(*) FROM pad_join_requests WHERE id = $rid")->fetchColumn() === 1);
check('and declining still works', str_contains(mb_join_decide($pdo, $padOf(), $rid, false, $dylanId), 'was declined'));
$pdo->exec("UPDATE plans SET max_human_seats = NULL WHERE code = 'plus'");

// ---- agents per account
$free = $principal($samId);                       // Sam is on Free: three agents
check('a Free account has room for agents until it has three', mb_agent_state($pdo, $samId)[1] === 3 && mb_agent_room($pdo, $samId) === true);
mb_create_agent($pdo, $samId, ['handle' => 'bot1']); mb_create_agent($pdo, $samId, ['handle' => 'bot2']); mb_create_agent($pdo, $samId, ['handle' => 'bot3']);
check('and then no more: it says whose plan, how many, and what to do', mb_agents_held($pdo, $samId) === 3 && mb_agent_room($pdo, $samId) === false
      && ($e = err(fn () => mb_create_agent($pdo, $samId, ['handle' => 'bot4']))) && str_contains($e, 'MbPlanLimit') && str_contains($e, 'Your Free plan allows 3 agents and you have 3. Remove one on Your agents, or move to a plan with more.'), $e);
check('the refused agent was not created', mb_agents_held($pdo, $samId) === 3 && !$pdo->query("SELECT 1 FROM principals WHERE handle = 'bot4'")->fetchColumn());
$pdo->exec("UPDATE principals SET disabled_at = UTC_TIMESTAMP() WHERE kind = 'agent' AND handle = 'bot3' AND owner_id = $samId");
check('a disabled agent is not counted', mb_agents_held($pdo, $samId) === 2 && mb_agent_room($pdo, $samId) === true);
$setPlan($samId, 'plus');
check('Plus has room for twenty-five', mb_agent_state($pdo, $samId)[1] === 25 && mb_agent_room($pdo, $samId) === true && mb_create_agent($pdo, $samId, ['handle' => 'bot5']) !== null);
$setPlan($samId, 'free');
$pdo->exec("UPDATE plans SET max_agents = NULL WHERE code = 'free'");
check('a blank limit is no limit', mb_agent_state($pdo, $samId)[1] === null && mb_agent_room($pdo, $samId) === true && mb_create_agent($pdo, $samId, ['handle' => 'bot6']) !== null);
$pdo->exec("UPDATE plans SET max_agents = 1 WHERE code = 'free'");
check('lowering the limit below what an account has removes no agent, and only stops more', mb_agents_held($pdo, $samId) === 4 && mb_agent_room($pdo, $samId) === false && str_contains(err(fn () => mb_create_agent($pdo, $samId, ['handle' => 'bot7'])), 'allows one agent and you have 4'));
$pdo->exec("UPDATE plans SET max_agents = 3 WHERE code = 'free'");

// guests join under the pad owner, so they use the owner's allowance until claimed
$gpad = mb_create_pad($pdo, $dylan, 'Guests'); $setPlan($dylanId, 'free'); $pdo->exec("UPDATE plans SET max_agents = 2 WHERE code = 'free'");
$ginv = mb_invite_make($pdo, mb_pad_by_address($pdo, 'dylan', 'guests'), $dylanId);
$gp = fn () => mb_pad_by_address($pdo, 'dylan', 'guests');
$held0 = mb_agents_held($pdo, $dylanId);
$guest1 = mb_invite_guest_agent($pdo, $gp(), ['handle' => 'guest-one', 'acts_for' => 'Lee'], false);
check('an agent that joins by invitation is registered under the pad\'s owner, and counts for them', mb_agents_held($pdo, $dylanId) === $held0 + 1);
while (mb_agent_room($pdo, $dylanId)) { mb_create_agent($pdo, $dylanId, ['handle' => 'fill' . mb_agents_held($pdo, $dylanId)]); }
$e = err(fn () => mb_invite_guest_agent($pdo, $gp(), ['handle' => 'guest-two', 'acts_for' => 'Kim'], false));
check('when the owner has no room a new guest can\'t join, and is told why, and that its own token still works', str_contains($e, 'MbPlanLimit') && str_contains($e, 'A new agent can\'t join under @dylan right now: their Free plan allows 2 agents') && str_contains($e, 'registered under the pad\'s owner until its person claims it') && str_contains($e, 'join with your token instead'), $e);
check('and it was not created', !$pdo->query("SELECT 1 FROM principals WHERE handle = 'guest-two'")->fetchColumn());

// claiming moves an agent into the claimer's allowance
$pdo->exec("UPDATE plans SET max_agents = 3 WHERE code = 'free'");
$pdo->exec("UPDATE principals SET disabled_at = UTC_TIMESTAMP() WHERE kind = 'agent' AND owner_id = $samId AND handle IN ('bot1','bot2','bot5','bot6')");
$guestAgent = $principal((int) $guest1[0]['id']);
[$code] = mb_claim_create($pdo, $leeId);
$beforeOwner = mb_agents_held($pdo, $dylanId); $beforeLee = mb_agents_held($pdo, $leeId);
mb_claim_accept($pdo, $guestAgent, $code);
check('a claim moves the agent from the owner\'s allowance to the claimer\'s', mb_agents_held($pdo, $dylanId) === $beforeOwner - 1 && mb_agents_held($pdo, $leeId) === $beforeLee + 1 && (int) $principal((int) $guestAgent['id'])['owner_id'] === $leeId);
// a claimer with no room
$g3 = mb_invite_guest_agent($pdo, $gp(), ['handle' => 'guest-three', 'acts_for' => 'Kim'], false);
for ($i = mb_agents_held($pdo, $kimId); $i < 3; $i++) { mb_create_agent($pdo, $kimId, ['handle' => "kimbot$i"]); }
[$code2] = mb_claim_create($pdo, $kimId);
$e = err(fn () => mb_claim_accept($pdo, $principal((int) $g3[0]['id']), $code2));
check('a claimer whose plan is full can\'t take it, is named, and nothing changes', str_contains($e, 'MbPlanLimit') && str_contains($e, 'Not accepted: @kim\'s Free plan allows 3 agents and they have 3. Nothing has changed.') && (int) $principal((int) $g3[0]['id'])['owner_id'] === $dylanId, $e);
check('and the claim code is not used up by the refusal, so they can try again once there is room', (int) $pdo->query("SELECT COUNT(*) FROM agent_claims WHERE created_by = $kimId AND used_at IS NULL")->fetchColumn() === 1);

// ---- editing the plan values
$pdo->exec("UPDATE plans SET max_human_seats = 1, max_agents = 3, threading = 0 WHERE code = 'free'");
$ch = mb_plan_update($pdo, 'free', ['max_agents' => '5', 'max_human_seats' => '', 'threading' => '1', 'retention_days' => '30'], $dylanId);
check('plan values change, blank means no limit, and what changed is reported', $ch == ['max_agents' => [3, 5], 'max_human_seats' => [1, null], 'threading' => [0, 1]], json_encode($ch));
$row = array_column(mb_plans($pdo), null, 'code')['free'];
check('they are saved, and the limits then apply at once', (int) $row['max_agents'] === 5 && $row['max_human_seats'] === null && (int) $row['threading'] === 1 && mb_seat_room($pdo, mb_pad_by_address($pdo, 'dylan', 'launch')) === true);
check('the change is recorded with the old and new values, by whom', ($a = $pdo->query("SELECT actor_id, action, detail FROM audit_log WHERE action = 'plan.update' ORDER BY id DESC LIMIT 1")->fetch()) && (int) $a['actor_id'] === $dylanId && str_contains($a['detail'], 'max_agents 3 -> 5') && str_contains($a['detail'], 'max_human_seats 1 -> none'), json_encode($a));
$n = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'plan.update'")->fetchColumn();
check('saving the same values changes nothing and is not recorded', mb_plan_update($pdo, 'free', ['max_agents' => '5', 'max_human_seats' => '', 'threading' => '1'], $dylanId) === [] && (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'plan.update'")->fetchColumn() === $n);
foreach ([['max_agents', 'many', 'Agents per account should be a whole number, or blank for no limit.'], ['max_agents', '2.5', 'Agents per account should be a whole number'], ['max_agents', '0', 'should be between 1 and 10000. You gave 0'], ['max_agents', '10001', 'You gave 10001'],
          ['retention_days', '', 'How long pads are kept (days) should be a whole number.'], ['retention_days', '0', 'should be between 1 and 3650'], ['check_interval_seconds', '30', 'should be between 60 and 86400'], ['post_interval_seconds', '-1', 'should be between 0 and 86400']] as [$f, $v, $msg]) {
    check("a bad value is refused: $f = " . json_encode($v), str_contains(err(fn () => mb_plan_update($pdo, 'free', [$f => $v], $dylanId)), $msg), err(fn () => mb_plan_update($pdo, 'free', [$f => $v], $dylanId)));
}
check('one bad value saves nothing, even when others were fine', str_contains(err(fn () => mb_plan_update($pdo, 'free', ['max_agents' => '9', 'retention_days' => 'x'], $dylanId)), 'How long pads are kept') && (int) array_column(mb_plans($pdo), null, 'code')['free']['max_agents'] === 5);
check('a plan that is not there is refused', str_contains(err(fn () => mb_plan_update($pdo, 'gold', ['max_agents' => 5], $dylanId)), 'There is no plan called "gold"'));
check('what is not named is left alone, and the name and price are not editable here', mb_plan_update($pdo, 'free', ['name' => 'Gold', 'price_cents' => '999', 'code' => 'x'], $dylanId) === [] && array_column(mb_plans($pdo), null, 'code')['free']['name'] === 'Mashpad' && (int) array_column(mb_plans($pdo), null, 'code')['free']['price_cents'] === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
