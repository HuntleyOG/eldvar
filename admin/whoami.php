<?php
declare(strict_types=1);
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/auth.php';

require_login();
$cached = current_user_role(false);
$fresh  = current_user_role(true);
?>
<!doctype html><meta charset="utf-8">
<pre>
User ID:        <?= htmlspecialchars((string)current_user_id()) ?>

Role (cached):  <?= htmlspecialchars($cached) ?>

Role (fresh):   <?= htmlspecialchars($fresh) ?>

DB selected:    <?php
try { $pdo = get_pdo(); echo htmlspecialchars($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '(none)'); }
catch (Throwable $e) { echo 'ERR: ' . htmlspecialchars($e->getMessage()); } ?>

Users row:      <?php
try {
  $pdo = get_pdo();
  $stmt = $pdo->prepare('SELECT id, username, role FROM users WHERE id = ?');
  $stmt->execute([current_user_id()]);
  echo htmlspecialchars(json_encode($stmt->fetch(), JSON_UNESCAPED_SLASHES));
} catch (Throwable $e) { echo 'ERR: ' . htmlspecialchars($e->getMessage()); }
?>
</pre>
