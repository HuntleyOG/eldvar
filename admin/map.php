<?php
// /admin/maps.php
declare(strict_types=1);

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "FATAL: {$e['message']} in {$e['file']}:{$e['line']}\n";
  }
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_acp();

function m_csrf_token(): string {
  if (!empty($_SESSION['maps_csrf'])) return $_SESSION['maps_csrf'];
  try { $_SESSION['maps_csrf'] = bin2hex(random_bytes(32)); }
  catch (Throwable) { $_SESSION['maps_csrf'] = sha1(uniqid('', true)); }
  return $_SESSION['maps_csrf'];
}
function m_csrf_check(?string $t): bool {
  return !empty($t) && !empty($_SESSION['maps_csrf']) && hash_equals($_SESSION['maps_csrf'], $t);
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function intish($v, int $d=0): int { return is_numeric($v) ? (int)$v : $d; }
function boolish($v): int { return (isset($v) && (string)$v === '1') ? 1 : 0; }
function slugify(string $s): string {
  $s = strtolower(trim($s));
  $s = preg_replace('/[^a-z0-9]+/','-',$s) ?? '';
  $s = trim($s, '-');
  return $s !== '' ? $s : 'area-'.substr(sha1((string)microtime(true)),0,6);
}

/* ---------- schema helpers ---------- */
function col_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
  $q->execute([$table, $col]);
  return (bool)$q->fetchColumn();
}
function ensure_column(PDO $pdo, string $table, string $col, string $definition): void {
  if (!col_exists($pdo, $table, $col)) {
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
  }
}

$errors = [];
$notices = [];

/* ---------- bootstrap (create if missing) ---------- */
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS world_areas (
      id                INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      slug              VARCHAR(80)  NOT NULL UNIQUE,
      name              VARCHAR(120) NOT NULL,
      short_blurb       VARCHAR(255) NULL,
      image_path        VARCHAR(255) NULL,
      recommended_level INT NOT NULL DEFAULT 1,
      is_active         TINYINT(1) NOT NULL DEFAULT 1,
      sort_order        INT NOT NULL DEFAULT 0,
      created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS world_towers (
      id                INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      area_id           INT NOT NULL,
      name              VARCHAR(120) NOT NULL,
      description       TEXT NULL,
      min_floor         INT NOT NULL DEFAULT 1,
      max_floor         INT NOT NULL DEFAULT 1,
      recommended_level INT NOT NULL DEFAULT 1,
      is_active         TINYINT(1) NOT NULL DEFAULT 1,
      sort_order        INT NOT NULL DEFAULT 0,
      created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_towers_area (area_id),
      CONSTRAINT fk_tower_area FOREIGN KEY (area_id) REFERENCES world_areas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {
  $errors[] = 'Bootstrap failed: ' . $e->getMessage();
}

/* ---------- auto-migrate missing columns from older installs ---------- */
try {
  ensure_column($pdo, 'world_areas',  'sort_order', 'INT NOT NULL DEFAULT 0');
  ensure_column($pdo, 'world_areas',  'is_active',  'TINYINT(1) NOT NULL DEFAULT 1');
  ensure_column($pdo, 'world_towers', 'sort_order', 'INT NOT NULL DEFAULT 0');
  ensure_column($pdo, 'world_towers', 'is_active',  'TINYINT(1) NOT NULL DEFAULT 1');
} catch (Throwable $e) {
  $errors[] = 'Migration notice: ' . $e->getMessage();
}

/* flags to decide ORDER BY safely */
$areas_has_sort  = col_exists($pdo, 'world_areas',  'sort_order');
$towers_has_sort = col_exists($pdo, 'world_towers', 'sort_order');

/* ---------- actions ---------- */
$action = $_POST['action'] ?? '';
if ($action && !m_csrf_check($_POST['csrf'] ?? '')) {
  $errors[] = 'Your session expired. Please reload and try again.';
  $action = '';
}

if ($action !== '') {
  try {
    if ($action === 'create_area') {
      $name = trim($_POST['name'] ?? '');
      $slug = trim($_POST['slug'] ?? '');
      $short_blurb = trim($_POST['short_blurb'] ?? '');
      $image_path = trim($_POST['image_path'] ?? '');
      $rec_level = max(1, intish($_POST['recommended_level'] ?? 1, 1));
      $is_active = boolish($_POST['is_active'] ?? '1');
      $sort_order= intish($_POST['sort_order'] ?? 0, 0);

      if ($name === '') throw new RuntimeException('Area name is required.');
      if ($slug === '') $slug = slugify($name);

      $st = $pdo->prepare("INSERT INTO world_areas (slug,name,short_blurb,image_path,recommended_level,is_active,sort_order)
                           VALUES (?,?,?,?,?,?,?)");
      $st->execute([$slug, $name, $short_blurb ?: null, $image_path ?: null, $rec_level, $is_active, $sort_order]);
      $notices[] = 'Area created.';

    } elseif ($action === 'update_area') {
      $id   = intish($_POST['id'] ?? 0);
      $name = trim($_POST['name'] ?? '');
      $slug = trim($_POST['slug'] ?? '');
      $short_blurb = trim($_POST['short_blurb'] ?? '');
      $image_path  = trim($_POST['image_path'] ?? '');
      $rec_level   = max(1, intish($_POST['recommended_level'] ?? 1, 1));
      $is_active   = boolish($_POST['is_active'] ?? '0');
      $sort_order  = intish($_POST['sort_order'] ?? 0, 0);

      if ($id <= 0) throw new RuntimeException('Invalid area id.');
      if ($name === '') throw new RuntimeException('Area name is required.');
      if ($slug === '') $slug = slugify($name);

      $st = $pdo->prepare("UPDATE world_areas
                           SET slug=?, name=?, short_blurb=?, image_path=?, recommended_level=?, is_active=?, sort_order=?
                           WHERE id=?");
      $st->execute([$slug, $name, $short_blurb ?: null, $image_path ?: null, $rec_level, $is_active, $sort_order, $id]);
      $notices[] = 'Area updated.';

    } elseif ($action === 'delete_area') {
      $id = intish($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid area id.');
      $pdo->prepare("DELETE FROM world_areas WHERE id=?")->execute([$id]);
      $notices[] = 'Area (and its towers) deleted.';

    } elseif ($action === 'create_tower') {
      $area_id   = intish($_POST['area_id'] ?? 0);
      $name      = trim($_POST['name'] ?? '');
      $desc      = trim($_POST['description'] ?? '');
      $min_floor = max(1, intish($_POST['min_floor'] ?? 1, 1));
      $max_floor = max($min_floor, intish($_POST['max_floor'] ?? $min_floor, $min_floor));
      $rec_level = max(1, intish($_POST['recommended_level'] ?? 1, 1));
      $is_active = boolish($_POST['is_active'] ?? '1');
      $sort_order= intish($_POST['sort_order'] ?? 0, 0);

      if ($area_id <= 0) throw new RuntimeException('Select a valid area.');
      if ($name === '') throw new RuntimeException('Tower name is required.');

      $st = $pdo->prepare("INSERT INTO world_towers (area_id,name,description,min_floor,max_floor,recommended_level,is_active,sort_order)
                           VALUES (?,?,?,?,?,?,?,?)");
      $st->execute([$area_id, $name, $desc ?: null, $min_floor, $max_floor, $rec_level, $is_active, $sort_order]);
      $notices[] = 'Tower created.';

    } elseif ($action === 'update_tower') {
      $id        = intish($_POST['id'] ?? 0);
      $area_id   = intish($_POST['area_id'] ?? 0);
      $name      = trim($_POST['name'] ?? '');
      $desc      = trim($_POST['description'] ?? '');
      $min_floor = max(1, intish($_POST['min_floor'] ?? 1, 1));
      $max_floor = max($min_floor, intish($_POST['max_floor'] ?? $min_floor, $min_floor));
      $rec_level = max(1, intish($_POST['recommended_level'] ?? 1, 1));
      $is_active = boolish($_POST['is_active'] ?? '0');
      $sort_order= intish($_POST['sort_order'] ?? 0, 0);

      if ($id <= 0) throw new RuntimeException('Invalid tower id.');
      if ($area_id <= 0) throw new RuntimeException('Select a valid area.');
      if ($name === '') throw new RuntimeException('Tower name is required.');

      $st = $pdo->prepare("UPDATE world_towers
                           SET area_id=?, name=?, description=?, min_floor=?, max_floor=?, recommended_level=?, is_active=?, sort_order=?
                           WHERE id=?");
      $st->execute([$area_id, $name, $desc ?: null, $min_floor, $max_floor, $rec_level, $is_active, $sort_order, $id]);
      $notices[] = 'Tower updated.';

    } elseif ($action === 'delete_tower') {
      $id = intish($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid tower id.');
      $pdo->prepare("DELETE FROM world_towers WHERE id=?")->execute([$id]);
      $notices[] = 'Tower deleted.';
    }
  } catch (Throwable $e) {
    $errors[] = 'Action failed: ' . $e->getMessage();
  }
}

/* ---------- load data (safe ORDER BY) ---------- */
try {
  $orderAreas = $areas_has_sort ? "ORDER BY sort_order ASC, name ASC" : "ORDER BY name ASC";
  $areas = $pdo->query("SELECT * FROM world_areas $orderAreas")->fetchAll();
} catch (Throwable $e) {
  $areas = [];
  $errors[] = 'Load areas failed: ' . $e->getMessage();
}

try {
  $orderT = $towers_has_sort ? "a.sort_order ASC, a.name ASC, t.sort_order ASC, t.name ASC"
                             : "a.name ASC, t.name ASC";
  $sql = "
    SELECT t.*, a.name AS area_name
    FROM world_towers t
    JOIN world_areas a ON a.id = t.area_id
    ORDER BY $orderT
  ";
  $towers = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) {
  $towers = [];
  $errors[] = 'Load towers failed: ' . $e->getMessage();
}

/* ---------- UI ---------- */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>World Map — Eldvar</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  <style>
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    @media (max-width: 1100px) { .grid-2 { grid-template-columns: 1fr; } }
    .card { background:#0f1726; border:1px solid rgba(255,255,255,.12); border-radius:12px; padding:12px; }
    .muted { color: rgba(255,255,255,.7); }
    .table { width:100%; border-collapse: collapse; }
    .table th, .table td { padding:8px; border-bottom: 1px solid rgba(255,255,255,.08); text-align:left; }
    input[type=text], input[type=number], select { width:100%; background:#0b1322; border:1px solid rgba(255,255,255,.2); border-radius:8px; padding:.45rem .6rem; color:#e6edf3; }
    .row { display:grid; grid-template-columns: repeat(12, 1fr); gap:10px; }
    .col-12{grid-column: span 12;} .col-6{grid-column: span 6;} .col-4{grid-column: span 4;} .col-3{grid-column: span 3;}
    .actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .badge { display:inline-block; padding:.1rem .5rem; border:1px solid rgba(255,255,255,.2); border-radius:999px; font-size:.8rem; }
    details > summary.btn { cursor:pointer; list-style:none; display:inline-block; padding:.3rem .6rem; border:1px solid rgba(255,255,255,.2); border-radius:8px; }
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <main class="app-main">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px; margin-bottom:12px;">
        <h1 class="pixel-title">World Map (Areas & Towers)</h1>
        <a class="btn" href="<?= BASE_URL ?>/admin/index.php">← Back to ACP</a>
      </div>

      <?php if ($errors): ?>
        <div class="alert error" style="margin-bottom:12px;"><?php foreach ($errors as $e) echo '<div>'.h($e).'</div>'; ?></div>
      <?php endif; ?>
      <?php if ($notices): ?>
        <div class="alert success" style="margin-bottom:12px;"><?php foreach ($notices as $n) echo '<div>'.h($n).'</div>'; ?></div>
      <?php endif; ?>

      <section class="grid-2">
        <!-- AREAS -->
        <div class="card">
          <h2 class="card-title">Areas</h2>
          <p class="muted">Define regions (e.g., Mystic Harshlands, Yulon Forest, Reichal, Undar, Frostbound Tundra).</p>

          <h3 style="margin-top:10px;">Create Area</h3>
          <form method="post" class="card" style="margin-top:8px;">
            <input type="hidden" name="csrf" value="<?= h(m_csrf_token()) ?>">
            <input type="hidden" name="action" value="create_area">
            <div class="row">
              <div class="col-6"><label>Name<br><input type="text" name="name" required></label></div>
              <div class="col-6"><label>Slug (optional)<br><input type="text" name="slug" placeholder="auto-from-name if blank"></label></div>
              <div class="col-12"><label>Short blurb<br><input type="text" name="short_blurb" placeholder="Brief flavor text"></label></div>
              <div class="col-6"><label>Image path (optional)<br><input type="text" name="image_path" placeholder="/public/images/map/area.png"></label></div>
              <div class="col-3"><label>Recommended Lv<br><input type="number" name="recommended_level" value="1" min="1"></label></div>
              <div class="col-3"><label>Sort order<br><input type="number" name="sort_order" value="0"></label></div>
              <div class="col-12 actions">
                <label><input type="checkbox" name="is_active" value="1" checked> Active</label>
                <button class="btn primary" type="submit">Add Area</button>
              </div>
            </div>
          </form>

          <h3 style="margin-top:16px;">Existing Areas</h3>
          <table class="table">
            <thead><tr><th>Name</th><th>Slug</th><th>Lv</th><th>Active</th><th>Sort</th><th style="width:220px;">Actions</th></tr></thead>
            <tbody>
              <?php
              if (!$areas): ?>
                <tr><td colspan="6" class="muted">No areas yet.</td></tr>
              <?php else:
                foreach ($areas as $a): ?>
                <tr>
                  <td><?= h($a['name']) ?></td>
                  <td class="muted"><?= h($a['slug']) ?></td>
                  <td><?= (int)$a['recommended_level'] ?></td>
                  <td><?= ((int)$a['is_active']===1) ? '<span class="badge">Active</span>' : '<span class="badge">Hidden</span>' ?></td>
                  <td><?= (int)($a['sort_order'] ?? 0) ?></td>
                  <td>
                    <details>
                      <summary class="btn">Edit</summary>
                      <form method="post" class="card" style="margin-top:8px;">
                        <input type="hidden" name="csrf" value="<?= h(m_csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_area">
                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                        <div class="row">
                          <div class="col-6"><label>Name<br><input type="text" name="name" value="<?= h($a['name']) ?>" required></label></div>
                          <div class="col-6"><label>Slug<br><input type="text" name="slug" value="<?= h($a['slug']) ?>"></label></div>
                          <div class="col-12"><label>Short blurb<br><input type="text" name="short_blurb" value="<?= h((string)($a['short_blurb'] ?? '')) ?>"></label></div>
                          <div class="col-6"><label>Image path<br><input type="text" name="image_path" value="<?= h((string)($a['image_path'] ?? '')) ?>"></label></div>
                          <div class="col-3"><label>Recommended Lv<br><input type="number" name="recommended_level" min="1" value="<?= (int)$a['recommended_level'] ?>"></label></div>
                          <div class="col-3"><label>Sort order<br><input type="number" name="sort_order" value="<?= (int)($a['sort_order'] ?? 0) ?>"></label></div>
                          <div class="col-12 actions">
                            <label><input type="checkbox" name="is_active" value="1" <?= ((int)$a['is_active']===1)?'checked':'' ?>> Active</label>
                            <button class="btn primary" type="submit">Save</button>
                          </div>
                        </div>
                      </form>
                      <form method="post" onsubmit="return confirm('Delete this area? All towers in it will also be deleted.');" style="margin-top:6px;">
                        <input type="hidden" name="csrf" value="<?= h(m_csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_area">
                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                        <button class="btn" type="submit">Delete Area</button>
                      </form>
                    </details>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <!-- TOWERS -->
        <div class="card">
          <h2 class="card-title">Towers</h2>
          <p class="muted">Attach towers to areas. Each tower defines a floor range and suggested level.</p>

          <h3 style="margin-top:10px;">Create Tower</h3>
          <form method="post" class="card" style="margin-top:8px;">
            <input type="hidden" name="csrf" value="<?= h(m_csrf_token()) ?>">
            <input type="hidden" name="action" value="create_tower">
            <div class="row">
              <div class="col-6">
                <label>Area<br>
                  <select name="area_id" required>
                    <option value="">Select area…</option>
                    <?php foreach ($areas as $a): ?>
                      <option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </div>
              <div class="col-6"><label>Name<br><input type="text" name="name" required></label></div>
              <div class="col-12"><label>Description (optional)<br><input type="text" name="description"></label></div>
              <div class="col-3"><label>Min Floor<br><input type="number" name="min_floor" value="1" min="1"></label></div>
              <div class="col-3"><label>Max Floor<br><input type="number" name="max_floor" value="1" min="1"></label></div>
              <div class="col-3"><label>Recommended Lv<br><input type="number" name="recommended_level" value="1" min="1"></label></div>
              <div class="col-3"><label>Sort order<br><input type="number" name="sort_order" value="0"></label></div>
              <div class="col-12 actions">
                <label><input type="checkbox" name="is_active" value="1" checked> Active</label>
                <button class="btn primary" type="submit">Add Tower</button>
              </div>
            </div>
          </form>

          <h3 style="margin-top:16px;">Existing Towers</h3>
          <table class="table">
            <thead>
              <tr>
                <th>Area</th><th>Name</th><th>Floors</th><th>Lv</th><th>Active</th><th>Sort</th><th style="width:260px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$towers): ?>
                <tr><td colspan="7" class="muted">No towers yet.</td></tr>
              <?php else: foreach ($towers as $t): ?>
                <tr>
                  <td><?= h($t['area_name']) ?></td>
                  <td><?= h($t['name']) ?></td>
                  <td><?= (int)$t['min_floor'] ?>–<?= (int)$t['max_floor'] ?></td>
                  <td><?= (int)$t['recommended_level'] ?></td>
                  <td><?= ((int)$t['is_active']===1) ? '<span class="badge">Active</span>' : '<span class="badge">Hidden</span>' ?></td>
                  <td><?= (int)($t['sort_order'] ?? 0) ?></td>
                  <td>
                    <details>
                      <summary class="btn">Edit</summary>
                      <form method="post" class="card" style="margin-top:8px;">
                        <input type="hidden" name="csrf" value="<?= h(m_csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_tower">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <div class="row">
                          <div class="col-6">
                            <label>Area<br>
                              <select name="area_id" required>
                                <?php foreach ($areas as $a): ?>
                                  <option value="<?= (int)$a['id'] ?>" <?= ((int)$a['id']===(int)$t['area_id'])?'selected':'' ?>><?= h($a['name']) ?></option>
                                <?php endforeach; ?>
                              </select>
                            </label>
                          </div>
                          <div class="col-6"><label>Name<br><input type="text" name="name" value="<?= h($t['name']) ?>" required></label></div>
                          <div class="col-12"><label>Description<br><input type="text" name="description" value="<?= h((string)($t['description'] ?? '')) ?>"></label></div>
                          <div class="col-3"><label>Min Floor<br><input type="number" name="min_floor" min="1" value="<?= (int)$t['min_floor'] ?>"></label></div>
                          <div class="col-3"><label>Max Floor<br><input type="number" name="max_floor" min="1" value="<?= (int)$t['max_floor'] ?>"></label></div>
                          <div class="col-3"><label>Recommended Lv<br><input type="number" name="recommended_level" min="1" value="<?= (int)$t['recommended_level'] ?>"></label></div>
                          <div class="col-3"><label>Sort order<br><input type="number" name="sort_order" value="<?= (int)($t['sort_order'] ?? 0) ?>"></label></div>
                          <div class="col-12 actions">
                            <label><input type="checkbox" name="is_active" value="1" <?= ((int)$t['is_active']===1)?'checked':'' ?>> Active</label>
                            <button class="btn primary" type="submit">Save</button>
                          </div>
                        </div>
                      </form>
                      <form method="post" onsubmit="return confirm('Delete this tower?');" style="margin-top:6px;">
                        <input type="hidden" name="csrf" value="<?= h(m_csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_tower">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn" type="submit">Delete Tower</button>
                      </form>
                    </details>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
  </div>
</body>
</html>
