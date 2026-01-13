<?php
declare(strict_types=1);
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
session_write_close();

header('Content-Type: application/json');
@set_time_limit(0);

$since   = max(0, (int)($_GET['since'] ?? 0));
$channel = $_GET['channel'] ?? 'global';
$allowed = ['global','ads','support','guild'];
if (!in_array($channel, $allowed, true)) $channel = 'global';

$pdo = get_pdo();
$timeout = 25;
$start = time();
$payload = [];

do {
  $stm = $pdo->prepare('SELECT id, username, body, UNIX_TIMESTAMP(created_at) AS ts
                        FROM chat_messages
                        WHERE channel=? AND id > ?
                        ORDER BY id ASC LIMIT 100');
  $stm->execute([$channel, $since]);
  $rows = $stm->fetchAll();
  if ($rows) { $payload = $rows; break; }
  usleep(150000);
} while (time() - $start < $timeout);

echo json_encode(['ok'=>true,'messages'=>$payload]);
