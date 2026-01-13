<?php
declare(strict_types=1);
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
session_write_close();

header('Content-Type: application/json');

$channel = $_GET['channel'] ?? 'global';
$allowed = ['global','ads','support','guild'];
if (!in_array($channel, $allowed, true)) $channel = 'global';

$pdo = get_pdo();
$stm = $pdo->prepare('SELECT id, username, body, UNIX_TIMESTAMP(created_at) AS ts
                      FROM chat_messages
                      WHERE channel=?
                      ORDER BY id DESC LIMIT 50');
$stm->execute([$channel]);
$rows = array_reverse($stm->fetchAll());
echo json_encode(['ok'=>true,'messages'=>$rows]);
