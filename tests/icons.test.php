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
require_once "$root/src/invites.php";
require_once "$root/src/claims.php";
require_once "$root/src/icons.php";
$pdo->exec('UPDATE plans SET max_human_seats = NULL, max_agents = NULL, post_interval_seconds = 0');
$mk = fn (string $h) => mb_create_human($pdo, ucfirst($h), $h, "$h@example.com", 'correct horse battery', $h === 'dylan');
$dylanId = $mk('dylan'); $samId = $mk('sam'); $leeId = $mk('lee');
$icon = fn (int $id) => (string) $pdo->query("SELECT icon FROM agent_profiles WHERE principal_id = $id")->fetchColumn();
$newAgent = fn (int $owner, string $h) => mb_create_agent($pdo, $owner, ['handle' => $h])[0];
$lib = mb_agent_icon_library();

// ---- the library
check('the library is the four icons so far, in order, named as words joined by hyphens', $lib === ['orange-sprout', 'orange-visor', 'pink-stars', 'teal-cheeks'] && array_reduce($lib, fn ($ok, $k) => $ok && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $k) === 1, true), json_encode($lib));
foreach ($lib as $k) {
    $info = getimagesize(MB_ICON_DIR . "/$k.webp");
    check("$k is a 100 x 100 webp, and light", $info !== false && $info[0] === 100 && $info[1] === 100 && $info['mime'] === 'image/webp' && filesize(MB_ICON_DIR . "/$k.webp") < 10240, json_encode($info));
}
check('an icon\'s address is the file, changing with its contents so a replaced icon shows at once', preg_match('~^/assets/agent-icons/orange-visor\.webp\?v=[0-9a-f]{10}$~', mb_agent_icon_url('orange-visor')) === 1 && mb_agent_icon_url('no-such-icon') === '/assets/agent-icons/no-such-icon.webp');

// ---- assignment
$ids = []; foreach (['ag1', 'ag2', 'ag3', 'ag4'] as $h) { $ids[] = $newAgent($dylanId, $h); }
$got = array_map($icon, $ids);
check('an account\'s first four agents get four different icons, taking the library in order', $got === $lib, json_encode($got));
$a5 = $newAgent($dylanId, 'ag5'); $a6 = $newAgent($dylanId, 'ag6'); $a7 = $newAgent($dylanId, 'ag7');
check('the fifth and later share them as evenly as can be, starting again from the first', [$icon($a5), $icon($a6), $icon($a7)] === [$lib[0], $lib[1], $lib[2]]);
$use = array_count_values(array_map($icon, array_merge($ids, [$a5, $a6, $a7])));
check('no icon is used more than once more than another', max($use) - min($use) <= 1 && count($use) === 4, json_encode($use));
check('another account starts from the first icon again: accounts do not share a count', $icon($newAgent($samId, 'sg1')) === $lib[0] && $icon($newAgent($samId, 'sg2')) === $lib[1]);

// ---- freeing and re-assigning
mb_delete_agent($pdo, $dylanId, $a5); mb_delete_agent($pdo, $dylanId, $a6); mb_delete_agent($pdo, $dylanId, $a7);
mb_delete_agent($pdo, $dylanId, $ids[2]);
$again = $newAgent($dylanId, 'ag8');
check('removing an agent frees its icon for the next one', $icon($again) === $lib[2] && array_count_values(array_map($icon, [$ids[0], $ids[1], $ids[3], $again])) == array_fill_keys($lib, 1));
check('a removed agent\'s icon does not count against the account', mb_agent_icon_pick($pdo, $dylanId) === $lib[0]);

// ---- agents that have none yet, or whose file has gone
$old = $newAgent($samId, 'sg3'); $pdo->exec("UPDATE agent_profiles SET icon = NULL WHERE principal_id = $old");
check('an agent with no icon gets one the first time it is shown, one its account\'s other agents use least', ($k = mb_agent_icon($pdo, mb_principal($pdo, $old))) !== '' && $icon($old) === $k && $k === $lib[2], $k);
$pdo->exec("UPDATE agent_profiles SET icon = 'deleted-long-ago' WHERE principal_id = $old");
check('and so does one whose icon file has gone', in_array(mb_agent_icon($pdo, mb_principal($pdo, $old)), $lib, true) && $icon($old) !== 'deleted-long-ago');
check('an agent that has one keeps it, however many times it is shown', mb_agent_icon($pdo, mb_principal($pdo, $old)) === $icon($old) && mb_agent_icon($pdo, mb_principal($pdo, $old)) === $icon($old));
check('the tag is a decorative image of the right size', preg_match('~^<img class="agent-icon" src="/assets/agent-icons/[a-z-]+\.webp\?v=[0-9a-f]{10}" alt="" width="40" height="40">$~', mb_agent_icon_tag($pdo, mb_principal($pdo, $old))) === 1 && str_contains(mb_agent_icon_tag($pdo, mb_principal($pdo, $old), 72, 'big'), 'class="big"') && str_contains(mb_agent_icon_tag($pdo, mb_principal($pdo, $old), 72), 'width="72" height="72"'));

// ---- the list of a person's agents carries the icon
check('Your agents\'s list has each agent\'s icon', array_column(mb_agents_of($pdo, $dylanId), 'icon', 'handle') === ['ag1' => $lib[0], 'ag2' => $lib[1], 'ag4' => $lib[3], 'ag8' => $lib[2]]);

// ---- a claimed agent moves account
$pad = mb_create_pad($pdo, mb_principal($pdo, $dylanId), 'Launch'); $guest = mb_invite_guest_agent($pdo, mb_pad_by_address($pdo, 'dylan', 'launch'), ['handle' => 'wanderer', 'acts_for' => 'Lee'], false)[0];
$gid = (int) $guest['id']; $taken = $icon($gid);
check('an agent that joined on its own as a guest has an icon from its pad owner\'s account', in_array($taken, $lib, true));
$lee1 = $newAgent($leeId, 'lee1');
$pdo->exec("UPDATE agent_profiles SET icon = " . $pdo->quote($taken) . " WHERE principal_id = $lee1");
[$code] = mb_claim_create($pdo, $leeId); mb_claim_accept($pdo, mb_principal($pdo, $gid), $code);
check('claiming it into an account that already uses its icon gives it another, so the account has no two alike', (int) mb_principal($pdo, $gid)['owner_id'] === $leeId && $icon($gid) !== $taken && in_array($icon($gid), $lib, true));
$guest2 = mb_invite_guest_agent($pdo, mb_pad_by_address($pdo, 'dylan', 'launch'), ['handle' => 'wanderer-two', 'acts_for' => 'Sam'], false)[0]; $g2 = (int) $guest2['id'];
$pdo->exec("UPDATE agent_profiles SET icon = 'teal-cheeks' WHERE principal_id = $g2"); $pdo->exec("UPDATE agent_profiles SET icon = 'orange-sprout' WHERE principal_id IN (SELECT id FROM principals WHERE handle = 'sg1')");
[$code2] = mb_claim_create($pdo, $samId); mb_claim_accept($pdo, mb_principal($pdo, $g2), $code2);
check('and keeps it when the new account is not using it', $icon($g2) === 'teal-cheeks');

// ---- the pad's own data
mb_add_member($pdo, mb_pad_by_address($pdo, 'dylan', 'launch'), $ids[0], 'added');
$read = mb_pad_read($pdo, mb_pad_by_address($pdo, 'dylan', 'launch'), null, 50);
check('a pad\'s data says which members are agents, and gives each agent\'s icon, not a person\'s', ($read['kinds']['ag1'] ?? '') === 'agent' && ($read['kinds']['dylan'] ?? '') === 'human' && isset($read['icons']['ag1']) && str_contains($read['icons']['ag1'], '/assets/agent-icons/' . $icon($ids[0]) . '.webp') && !isset($read['icons']['dylan']), json_encode([$read['kinds'] ?? null, $read['icons'] ?? null]));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
