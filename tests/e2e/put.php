<?php
// usage: php put.php pad who body [kind] [when] [replyToSeq]  -> prints the new post's number
// Writes a post straight into the database as someone, so a check can set up a pad without going through the page.
$site = getenv('MASHPAD_SITE') ?: '/srv/site';
require "$site/src/pads.php";
$pdo = mb_db();
[$_, $slug, $who, $body] = $argv;
$kind = $argv[4] ?? 'post'; $when = ($argv[5] ?? '') !== '' ? $argv[5] : null; $reply = ($argv[6] ?? '') !== '' ? (int) $argv[6] : null;
$pad = mb_pad_by_address($pdo, 'alice', $slug);
$by  = (int) $pdo->query("SELECT id FROM principals WHERE handle = " . $pdo->quote($who))->fetchColumn();
$pdo->beginTransaction();
$seq = (int) $pdo->query('SELECT next_seq FROM pads WHERE id = ' . (int) $pad['id'] . ' FOR UPDATE')->fetchColumn();
$parent = $reply === null ? null : (int) $pdo->query('SELECT id FROM messages WHERE pad_id = ' . (int) $pad['id'] . ' AND seq = ' . $reply)->fetchColumn();
$pdo->prepare('INSERT INTO messages (pad_id, seq, author_id, body, kind, parent_id' . ($when ? ', created_at' : '') . ') VALUES (?, ?, ?, ?, ?, ?' . ($when ? ', ?' : '') . ')')
    ->execute(array_merge([$pad['id'], $seq, $by, $body, $kind, $parent], $when ? [$when] : []));
$pdo->prepare('UPDATE pads SET next_seq = next_seq + 1 WHERE id = ?')->execute([$pad['id']]);
$pdo->commit();
echo $seq;
