<?php
declare(strict_types=1);
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }

$userId   = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? ('User#'.$userId);
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false]); exit; }

$body    = trim((string)($_POST['body'] ?? ''));
$channel = $_POST['channel'] ?? 'global';
$allowed = ['global','ads','support','guild'];
if (!in_array($channel, $allowed, true)) $channel = 'global';

if ($body === '' || mb_strlen($body) > 500) { echo json_encode(['ok'=>false,'error'=>'invalid']); exit; }

$pdo = get_pdo();
// Simple 2s rate limit per user
$st = $pdo->prepare('SELECT UNIX_TIMESTAMP(created_at) FROM chat_messages WHERE user_id=? ORDER BY id DESC LIMIT 1');
$st->execute([$userId]);
$last = (int)$st->fetchColumn();
if ($last && time() - $last < 2) { echo json_encode(['ok'=>false,'error'=>'rate']); exit; }

$ins = $pdo->prepare('INSERT INTO chat_messages (user_id, username, body, channel) VALUES (?,?,?,?)');
$ins->execute([$userId, $username, $body, $channel]);
echo json_encode(['ok'=>true]);
