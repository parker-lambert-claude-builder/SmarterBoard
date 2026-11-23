<?php
// Seeds a fresh test database: two people (alice, an administrator, and bob), their pads, an agent (@muse) on alice's
// Launch pad, a lobby code on bob's, and an old open pad. Writes an agent token and a lobby code to the file named by
// MASHPAD_E2E_ENV, which the checks read.
$root = getenv('MASHPAD_SITE') ?: '/srv/site';
require "$root/src/db.php";
$db = ['host' => getenv('MASHPAD_E2E_DB_HOST') ?: '127.0.0.1', 'port' => (int) (getenv('MASHPAD_E2E_DB_PORT') ?: 3306), 'name' => getenv('MASHPAD_E2E_DB_NAME') ?: 'mashpad_test_e2e',
       'user' => getenv('MASHPAD_E2E_DB_USER') ?: 'mash', 'pass' => getenv('MASHPAD_E2E_DB_PASS') ?: 'mashpass'];
$pdo = mb_db_connect($db); mb_migrate($pdo); mb_write_config($db);
require "$root/src/pads.php";
$a = mb_create_human($pdo, 'Alice', 'alice', 'alice@example.com', 'correct horse battery', true);
$b = mb_create_human($pdo, 'Bob', 'bob', 'bob@example.com', 'correct horse battery');
$pa = mb_create_pad($pdo, mb_principal($pdo, $a), 'Launch');
$pb = mb_create_pad($pdo, mb_principal($pdo, $b), 'Launch');
mb_create_pad($pdo, mb_principal($pdo, $a), 'Solo Notes');
[$m, $tok] = mb_create_agent($pdo, $a, ['handle' => 'muse']);
mb_add_member($pdo, $pa, $m, 'added');
mb_pad_post($pdo, mb_pad_for($pdo, 'alice/launch', $a), mb_principal($pdo, $a), "Hello from Alice's launch.");
mb_pad_post($pdo, mb_pad_for($pdo, 'bob/launch', $b), mb_principal($pdo, $b), "Hello from Bob's launch.");
$pdo->exec("UPDATE messages SET created_at = created_at - INTERVAL 5 MINUTE");
[$code] = mb_lobby_create($pdo, $pb, $b);
file_put_contents(getenv('MASHPAD_E2E_ENV') ?: '/tmp/e2e.env', "TOK=$tok\nCODE=$code\n");
file_put_contents("$root/data/boards/old-open.json", json_encode(['name' => 'old-open', 'slug' => 'old-open', 'created' => time(), 'next_id' => 2, 'messages' => [['id' => 1, 'author' => 'builder', 'body' => 'legacy', 'ts' => time()]]]));
echo "seeded\n";
