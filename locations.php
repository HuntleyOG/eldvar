<?php
declare(strict_types=1);

require __DIR__ . '/config/config.php';
require __DIR__ . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* --- Require login (same as dashboard) --- */
if (!isset($_SESSION['user_id'])) {
  header('Location: ' . BASE_URL . '/login.php');
  exit;
}

/* ---------- Helpers ---------- */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function intish(mixed $v, int $d = 0): int { return is_numeric($v) ? (int)$v : $d; }

function csrf_token_locations(): string {
  if (!empty($_SESSION['loc_csrf'])) return $_SESSION['loc_csrf'];
  try { $_SESSION['loc_csrf'] = bin2hex(random_bytes(32)); }
  catch (Throwable) { $_SESSION['loc_csrf'] = sha1(uniqid('', true)); }
  return $_SESSION['loc_csrf'];
}
function csrf_check_locations(?string $t): bool {
  return !empty($t) && !empty($_SESSION['loc_csrf']) && hash_equals($_SESSION['loc_csrf'], $t);
}

/* information_schema helpers (placeholders allowed) */
function table_exists(PDO $pdo, string $name): bool {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
  $st->execute([$name]); return (bool)$st->fetchColumn();
}
function column_exists(PDO $pdo, string $table, string $column): bool {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
  $st->execute([$table, $column]); return (bool)$st->fetchColumn();
}

/* ---------- DB ---------- */
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$user_id = (int)$_SESSION['user_id'];

/* ---------- Capability checks ---------- */
$has_user_floor   = table_exists($pdo,'users')   && column_exists($pdo,'users','current_floor') && column_exists($pdo,'users','deepest_floor');
$have_battles     = table_exists($pdo,'battles');
$has_battle_floor = $have_battles && column_exists($pdo,'battles','floor');

/* ---------- Load user ---------- */
$u = ['username'=>'Adventurer','display_name'=>null,'current_floor'=>1,'deepest_floor'=>1];
try {
  if ($has_user_floor) {
    $st = $pdo->prepare("SELECT username, display_name, current_floor, deepest_floor FROM users WHERE id = ? LIMIT 1");
    $st->execute([$user_id]);
    if ($row = $st->fetch()) {
      $u['username']      = (string)$row['username'];
      $u['display_name']  = $row['display_name'] !== null ? (string)$row['display_name'] : null;
      $u['current_floor'] = max(1, intish($row['current_floor'] ?? 1, 1));
      $u['deepest_floor'] = max(1, intish($row['deepest_floor'] ?? 1, 1));
    }
  }
} catch (Throwable) {}

$errors  = [];
$notices = [];

if (!$has_user_floor) {
  $errors[] = "Locations are unavailable because the users table doesn’t have current_floor/deepest_floor.";
}

/* ---------- Prevent moves during an active battle ---------- */
$has_active_battle = false;
if ($have_battles) {
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM battles WHERE user_id=? AND status='ongoing'");
    $st->execute([$user_id]);
    $has_active_battle = ((int)$st->fetchColumn()) > 0;
  } catch (Throwable) {}
}

/* ---------- Handle POST (move) ---------- */
if (($_POST['action'] ?? '') === 'move' && csrf_check_locations($_POST['csrf'] ?? '')) {
  if ($has_user_floor) {
    $target = intish($_POST['target_floor'] ?? 0);
    if ($target < 1) {
      $errors[] = "Choose a valid floor.";
    } elseif ($has_active_battle) {
      $errors[] = "You can’t change location while a battle is active. End the battle first.";
    } elseif ($target > $u['deepest_floor']) {
      $errors[] = "You haven’t unlocked Floor {$target} yet.";
    } elseif ($target === $u['current_floor']) {
      $notices[] = "You’re already on Floor {$target}.";
    } else {
      try {
        $st = $pdo->prepare("UPDATE users SET current_floor = ? WHERE id = ?");
        $st->execute([$target, $user_id]);
        $u['current_floor'] = $target;
        $notices[] = "Moved to Floor {$target}.";
      } catch (Throwable $e) {
        $errors[] = "Move failed: " . $e->getMessage();
      }
    }
  }
}

/* ---------- Build floor list (1..deepest) ---------- */
$floors = range(1, (int)$u['deepest_floor']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Locations — Eldvar</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  <style>
    .floors-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px}
    .floor-card{display:flex;align-items:center;justify-content:space-between;padding:10px;border:1px solid rgba(255,255,255,.12);border-radius:10px;background:#0f1726}
    .floor-card .info{font-weight:600}
    .muted-small{opacity:.75;font-size:.9rem}
    .actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    .badge.current{background:#1e293b;border:1px solid rgba(255,255,255,.18);padding:.2rem .5rem;border-radius:999px}
    .alert + .alert { margin-top: 6px; }
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>
    <main class="app-main">
      <section class="card">
        <h1 class="card-title">Locations</h1>
        <p class="muted">Jump to any floor you’ve unlocked. You can’t go higher than your deepest floor, and you can’t move while a battle is active.</p>

        <?php if ($has_user_floor): ?>
          <p class="muted-small">
            Current: <strong>Floor <?= (int)$u['current_floor'] ?></strong>
            · Deepest: <strong>Floor <?= (int)$u['deepest_floor'] ?></strong>
            <?php if ($has_active_battle): ?>
              · <span class="badge">Active battle</span>
            <?php endif; ?>
          </p>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="alert error"><?php foreach ($errors as $e) echo '<div>'.h($e).'</div>'; ?></div>
        <?php endif; ?>
        <?php if ($notices): ?>
          <div class="alert success"><?php foreach ($notices as $n) echo '<div>'.h($n).'</div>'; ?></div>
        <?php endif; ?>

        <?php if ($has_user_floor): ?>
          <div class="floors-grid" style="margin-top:12px;">
            <?php foreach ($floors as $f): ?>
              <div class="floor-card">
                <div class="info">Floor <?= (int)$f ?></div>
                <div class="actions">
                  <?php if ($f == $u['current_floor']): ?>
                    <span class="badge current">Current</span>
                  <?php else: ?>
                    <form method="post" style="margin:0;">
                      <input type="hidden" name="csrf" value="<?= h(csrf_token_locations()) ?>">
                      <input type="hidden" name="action" value="move">
                      <input type="hidden" name="target_floor" value="<?= (int)$f ?>">
                      <button class="btn" <?= $has_active_battle ? 'disabled title="End battle to move"' : '' ?>>Move</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if ($u['deepest_floor'] > 1): ?>
            <div class="actions" style="margin-top:12px;">
              <!-- Quick actions -->
              <form method="post" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= h(csrf_token_locations()) ?>">
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="target_floor" value="1">
                <button class="btn" <?= $has_active_battle ? 'disabled' : '' ?>>Go to Floor 1</button>
              </form>
              <form method="post" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= h(csrf_token_locations()) ?>">
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="target_floor" value="<?= (int)$u['deepest_floor'] ?>">
                <button class="btn" <?= $has_active_battle ? 'disabled' : '' ?>>Go to Deepest</button>
              </form>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>
</body>
</html>
