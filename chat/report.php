<?php
declare(strict_types=1);
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Login required']); exit;
}

$pdo = get_pdo();
try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* Ensure table exists (safe if it already does) */
$pdo->exec("
CREATE TABLE IF NOT EXISTS chat_message_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_id INT NOT NULL,
  reporter_user_id INT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('open','resolved','invalid') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  handled_by INT NULL,
  handled_at TIMESTAMP NULL,
  INDEX (message_id),
  INDEX (reporter_user_id),
  INDEX (status),
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$uid    = (int)$_SESSION['user_id'];
$msgId  = (int)($_POST['message_id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));
$extra  = trim((string)($_POST['details'] ?? ''));

/* Basic validation */
if ($msgId <= 0 || $reason === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Invalid input']); exit;
}

/* Normalize reason to a short fixed set + allow extra */
$allowed = ['spam','harassment','nsfw','cheating','other'];
$label = strtolower($reason);
if (!in_array($label, $allowed, true)) $label = 'other';
if ($extra !== '') $label .= ': '.mb_substr($extra, 0, 200);

/* Verify the message exists */
$st = $pdo->prepare('SELECT id FROM chat_messages WHERE id = ? LIMIT 1');
$st->execute([$msgId]);
if (!$st->fetch()) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'Message not found']); exit;
}

/* Simple duplicate / rate-limit:
   - prevent the same user reporting the same message repeatedly
   - also limit to 5 reports per minute per user  */
$dupe = $pdo->prepare('SELECT id FROM chat_message_reports WHERE message_id = ? AND reporter_user_id = ? AND status = "open" LIMIT 1');
$dupe->execute([$msgId, $uid]);
if ($dupe->fetch()) {
  echo json_encode(['ok' => true, 'note' => 'Already reported']); exit;
}

$rate = $pdo->prepare('SELECT COUNT(*) FROM chat_message_reports WHERE reporter_user_id=? AND created_at > (NOW() - INTERVAL 1 MINUTE)');
$rate->execute([$uid]);
if ((int)$rate->fetchColumn() >= 5) {
  http_response_code(429);
  echo json_encode(['ok' => false, 'error' => 'Too many reports, try again shortly']); exit;
}

/* Insert */
$ins = $pdo->prepare('INSERT INTO chat_message_reports (message_id, reporter_user_id, reason) VALUES (?,?,?)');
$ins->execute([$msgId, $uid, $label]);

echo json_encode(['ok' => true]);
