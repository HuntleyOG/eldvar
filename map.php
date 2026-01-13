<?php
declare(strict_types=1);

require __DIR__ . '/config/config.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_login();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function map_csrf_token(): string {
  if (!empty($_SESSION['map_csrf'])) return $_SESSION['map_csrf'];
  try { $_SESSION['map_csrf'] = bin2hex(random_bytes(32)); }
  catch (Throwable) { $_SESSION['map_csrf'] = sha1(uniqid('', true)); }
  return $_SESSION['map_csrf'];
}
function map_csrf_check(?string $t): bool {
  return !empty($t) && !empty($_SESSION['map_csrf']) && hash_equals($_SESSION['map_csrf'], $t);
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$user_id = (int)($_SESSION['user_id'] ?? 0);

/* Load user */
$u = ['username'=>'Adventurer','display_name'=>null,'current_floor'=>1,'deepest_floor'=>1];
$st = $pdo->prepare("SELECT username, display_name, current_floor, deepest_floor FROM users WHERE id=? LIMIT 1");
$st->execute([$user_id]);
if ($row = $st->fetch()) {
  $u['username'] = (string)$row['username'];
  $u['display_name'] = $row['display_name'] ?: null;
  $u['current_floor'] = max(1, (int)$row['current_floor']);
  $u['deepest_floor'] = max(1, (int)$row['deepest_floor']);
}
$player_name = $u['display_name'] ?: $u['username'];

/* Pull areas + towers */
$areas = $pdo->query("SELECT * FROM world_areas ORDER BY id ASC")->fetchAll();

$area_towers = [];
if ($areas) {
  $ids = implode(',', array_map('intval', array_column($areas,'id')));
  $tt = $pdo->query("SELECT * FROM world_towers WHERE area_id IN ($ids) ORDER BY min_floor ASC, id ASC")->fetchAll();
  foreach ($tt as $t) { $area_towers[(int)$t['area_id']][] = $t; }
}

/* Ensure we have (session) current tower & per-tower progress row on demand */
function ensure_tower_progress(PDO $pdo, int $user_id, int $tower_id): int {
  $q = $pdo->prepare("SELECT deepest_floor FROM tower_progress WHERE user_id=? AND tower_id=?");
  $q->execute([$user_id, $tower_id]);
  $d = $q->fetchColumn();
  if ($d === false) {
    $ins = $pdo->prepare("INSERT INTO tower_progress (user_id,tower_id,deepest_floor) VALUES (?,?,1)");
    $ins->execute([$user_id, $tower_id]);
    return 1;
  }
  return max(1, (int)$d);
}

/* Handle travel */
$errors = []; $notices = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'travel' && map_csrf_check($_POST['csrf'] ?? '')) {
  try {
    $tower_id = (int)($_POST['tower_id'] ?? 0);
    $floor    = (int)($_POST['target_floor'] ?? 0);

    // Load the tower with area join
    $ts = $pdo->prepare("SELECT t.*, a.name AS area_name FROM world_towers t JOIN world_areas a ON a.id=t.area_id WHERE t.id=? LIMIT 1");
    $ts->execute([$tower_id]);
    $t = $ts->fetch();
    if (!$t) throw new RuntimeException('That tower does not exist.');

    $t_min = (int)$t['min_floor'];
    $t_max = (int)$t['max_floor'];

    // Player’s per-tower deepest (ensure row)
    $tp_deepest = ensure_tower_progress($pdo, $user_id, $tower_id);

    // Clamp selected floor to tower bounds and player progress (both global & tower)
    $allowed_max = max(1, min($t_max, $u['deepest_floor'], $tp_deepest));
    $floor = max($t_min, min($floor ?: $t_min, $allowed_max));

    // Persist current location in session + users.current_floor for compatibility
    $_SESSION['current_tower_id'] = $tower_id;
    $_SESSION['current_tower_name'] = (string)$t['name'];
    $up = $pdo->prepare("UPDATE users SET current_floor=? WHERE id=?");
    $up->execute([$floor, $user_id]);

    $u['current_floor'] = $floor;
    $notices[] = "Entered <strong>".h($t['name'])."</strong> (".h($t['area_name']).") · Floor ".(int)$floor.".";
  } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

$current_tower_name = $_SESSION['current_tower_name'] ?? 'None';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>World Map — Eldvar</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  <style>
    .areas { display:grid; gap:14px; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); }
    .cardx { border:1px solid rgba(255,255,255,.12); border-radius:14px; overflow:hidden; background:#0b1322; }
    .hrow { display:flex; justify-content:space-between; align-items:center; gap:8px; }
    .pill { border:1px solid rgba(255,255,255,.22); border-radius:999px; padding:.15rem .5rem; font-size:.85rem; }
    .tower { border:1px dashed rgba(255,255,255,.14); border-radius:10px; padding:10px; display:flex; justify-content:space-between; align-items:center; gap:8px; }
    input[type=number] { width:110px; background:#0f1726; border:1px solid rgba(255,255,255,.2); border-radius:8px; padding:.4rem .5rem; color:#e6edf3; }
    .muted2 { color: rgba(255,255,255,.7); }
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>
    <main class="app-main">
      <h1 class="pixel-title">World Map</h1>
      <p class="muted">Hello, <?= h($player_name) ?>. Current floor: <strong><?= (int)$u['current_floor'] ?></strong> · Deepest (global): <strong><?= (int)$u['deepest_floor'] ?></strong> · Tower: <strong><?= h($current_tower_name) ?></strong></p>

      <?php if ($errors): ?>
        <div class="alert error" style="margin:10px 0;"><?php foreach ($errors as $e) echo '<div>'.h($e).'</div>'; ?></div>
      <?php endif; ?>
      <?php if ($notices): ?>
        <div class="alert success" style="margin:10px 0;"><?php foreach ($notices as $n) echo '<div>'.$n.'</div>'; ?></div>
      <?php endif; ?>

      <?php if (!$areas): ?>
        <div class="alert info">No areas yet. Seed the world in the ACP.</div>
      <?php else: ?>
        <div class="areas">
          <?php foreach ($areas as $a): ?>
          <div class="cardx">
            <div style="padding:12px;">
              <div class="hrow">
                <strong style="font-size:1.1rem;"><?= h($a['name']) ?></strong>
                <span class="pill"><?= h($a['slug']) ?></span>
              </div>
              <?php if (!empty($a['description'])): ?>
                <p class="muted2" style="margin:6px 0 10px 0;"><?= h($a['description']) ?></p>
              <?php endif; ?>

              <?php $towers = $area_towers[(int)$a['id']] ?? []; ?>
              <?php if (!$towers): ?>
                <div class="muted">No towers defined here yet.</div>
              <?php else: ?>
                <div style="display:grid; gap:10px;">
                  <?php foreach ($towers as $t):
                    $minf = (int)$t['min_floor']; $maxf = (int)$t['max_floor'];
                    // Player’s per-tower deepest (lazy query per card, cheap table)
                    $tp = $pdo->prepare("SELECT deepest_floor FROM tower_progress WHERE user_id=? AND tower_id=?");
                    $tp->execute([$user_id, (int)$t['id']]);
                    $tp_deepest = ($tp->fetchColumn() !== false) ? (int)$tp->fetchColumn() : 1; // avoid double fetch later
                    $tp->closeCursor();
                    if (!$tp_deepest) $tp_deepest = 1;
                    $allowed_max = max(1, min($maxf, $u['deepest_floor'], $tp_deepest));
                    $default_floor = max($minf, min($u['current_floor'], $allowed_max));
                  ?>
                  <div class="tower">
                    <div>
                      <div><strong><?= h($t['name']) ?></strong></div>
                      <div class="muted2">Floors <?= (int)$minf ?>–<?= (int)$maxf ?></div>
                    </div>
                    <form method="post" style="display:flex; gap:8px; align-items:center;">
                      <input type="hidden" name="csrf" value="<?= h(map_csrf_token()) ?>">
                      <input type="hidden" name="action" value="travel">
                      <input type="hidden" name="tower_id" value="<?= (int)$t['id'] ?>">
                      <label class="muted2">Floor</label>
                      <input type="number" name="target_floor" min="<?= (int)$minf ?>" max="<?= (int)$allowed_max ?>" value="<?= (int)$default_floor ?>">
                      <button class="btn primary" type="submit">Enter</button>
                    </form>
                  </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </main>
    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>
  <?php include __DIR__ . '/includes/chat.php'; ?>
</body>
</html>
