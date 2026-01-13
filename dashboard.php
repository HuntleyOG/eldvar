<?php
declare(strict_types=1);

require __DIR__ . '/config/config.php';
require __DIR__ . '/config/db.php';
session_start();

/* --- Require login --- */
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}

/* --- Helpers --- */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function intish(mixed $v, int $d=0): int { return is_numeric($v) ? (int)$v : $d; }

/* information_schema helpers (safe on shared hosts) */
function table_exists(PDO $pdo, string $name): bool {
  try {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $st->execute([$name]);
    return (bool)$st->fetchColumn();
  } catch (Throwable) { return false; }
}
function column_exists(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable) { return false; }
}

/* ---------- Game settings (shared with battle/admin) ---------- */
function gs_all(PDO $pdo): array {
  try {
    $q = $pdo->query("SELECT `key`,`value` FROM game_settings");
    return $q ? $q->fetchAll(PDO::FETCH_KEY_PAIR) : [];
  } catch (Throwable) { return []; }
}
function gs_int(array $all, string $key, int $default, ?int $min=null, ?int $max=null): int {
  $v = isset($all[$key]) ? (int)round((float)$all[$key]) : $default;
  if ($min !== null && $v < $min) $v = $min;
  if ($max !== null && $v > $max) $v = $max;
  return $v;
}

/* ---------- DB ---------- */
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$uid = (int)$_SESSION['user_id'];

/* What columns do we have? */
$have_users           = table_exists($pdo,'users');
$u_has_display_name   = $have_users && column_exists($pdo,'users','display_name');
$u_has_level          = $have_users && column_exists($pdo,'users','level');
$u_has_current_floor  = $have_users && column_exists($pdo,'users','current_floor');
$u_has_deepest_floor  = $have_users && column_exists($pdo,'users','deepest_floor');
$u_has_last_seen      = $have_users && column_exists($pdo,'users','last_seen');
$have_battles         = table_exists($pdo,'battles');
$b_has_floor          = $have_battles && column_exists($pdo,'battles','floor');
$have_bturns          = table_exists($pdo,'battle_turns');

/* Load server tunables (for required wins, etc.) */
$GS = gs_all($pdo);
$wins_required_per_floor = gs_int($GS, 'wins_required_per_floor', 3, 1, 20);

/* ---------- Load user row (defensive) ---------- */
$user = [
  'username'      => 'Adventurer',
  'display_name'  => null,
  'level'         => 1,
  'created_at'    => null,
  'current_floor' => 1,
  'deepest_floor' => 1,
  'last_seen'     => null,
];

try {
  $cols = ['username'];
  if ($u_has_display_name)  $cols[] = 'display_name';
  if ($u_has_level)         $cols[] = 'level';
  if ($u_has_current_floor) $cols[] = 'current_floor';
  if ($u_has_deepest_floor) $cols[] = 'deepest_floor';
  if ($have_users && column_exists($pdo,'users','created_at')) $cols[] = 'created_at';
  if ($u_has_last_seen)     $cols[] = 'last_seen';

  $sql = "SELECT " . implode(', ', $cols) . " FROM users WHERE id = ? LIMIT 1";
  $st  = $pdo->prepare($sql);
  $st->execute([$uid]);
  $row = $st->fetch() ?: [];
  if ($row) {
    $user['username']      = (string)$row['username'];
    $user['display_name']  = isset($row['display_name']) ? (string)$row['display_name'] : null;
    $user['level']         = intish($row['level'] ?? 1, 1);
    $user['created_at']    = $row['created_at'] ?? null;
    $user['current_floor'] = intish($row['current_floor'] ?? 1, 1);
    $user['deepest_floor'] = intish($row['deepest_floor'] ?? 1, 1);
    $user['last_seen']     = $row['last_seen'] ?? null;
  }
} catch (Throwable) {}

/* ---------- Quick stats ---------- */
$ongoing_battle = null;
$wins_today = 0;
$recent_battles = [];

if ($have_battles) {
  try {
    // Ongoing
    $st = $pdo->prepare("SELECT * FROM battles WHERE user_id=? AND status='ongoing' ORDER BY id DESC LIMIT 1");
    $st->execute([$uid]);
    $ongoing_battle = $st->fetch() ?: null;
  } catch (Throwable) {}

  // Wins today
  try {
    $wins_sql = "SELECT COUNT(*) FROM battles WHERE user_id=? AND status='won' AND DATE(created_at)=CURDATE()";
    if (!column_exists($pdo,'battles','created_at')) {
      // Fallback: last 24h via id desc approximate (no created_at column)
      $wins_sql = "SELECT COUNT(*) FROM battles WHERE user_id=? AND status='won'";
    }
    $st = $pdo->prepare($wins_sql);
    $st->execute([$uid]);
    $wins_today = (int)$st->fetchColumn();
  } catch (Throwable) {}

  // Recent (limit 8)
  try {
    $orderCol = column_exists($pdo,'battles','created_at') ? 'created_at' : 'id';
    $st = $pdo->prepare("SELECT id, mob_name, status, reward_xp, reward_gold, char_hp_current, mob_hp_current"
                      . ($b_has_floor ? ", floor" : "")
                      . " FROM battles WHERE user_id=? ORDER BY {$orderCol} DESC LIMIT 8");
    $st->execute([$uid]);
    $recent_battles = $st->fetchAll();
  } catch (Throwable) {}
}

/* Floor progress for “descend” requirement (battle.php uses same logic) */
$wins_on_current_floor = 0;
if ($have_battles && $b_has_floor && $u_has_current_floor) {
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM battles WHERE user_id=? AND status='won' AND floor=?");
    $st->execute([$uid, (int)$user['current_floor']]);
    $wins_on_current_floor = (int)$st->fetchColumn();
  } catch (Throwable) {}
}
$wins_required = max(1, $wins_required_per_floor);
$progress_pct = (int)max(0, min(100, floor($wins_on_current_floor / max(1,$wins_required) * 100)));

/* Name to show */
$display_name = $user['display_name'] ?: $user['username'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Dashboard — Eldvar</title>
  <link rel="stylesheet" href="public/css/style.css">
  <style>
    /* Scoped dashboard flair */
    .dash-hero{
      position: relative;
      margin: -8px 0 12px 0;
      border-radius: 16px;
      overflow: hidden;
      border:1px solid var(--border);
      background:
        radial-gradient(1200px 600px at 6% -10%, rgba(119,221,119,.08), transparent 60%),
        radial-gradient(800px 400px at 100% 0%, rgba(160,224,255,.08), transparent 60%),
        linear-gradient(180deg, #151822, #11131a);
      box-shadow: 0 10px 40px rgba(0,0,0,.45);
    }
    .dash-hero-inner{ display:grid; grid-template-columns: 1fr 380px; gap: 0; }
    @media (max-width: 1100px){ .dash-hero-inner{ grid-template-columns: 1fr } }

    .charcard{
      display:flex; gap:18px; padding:22px;
      align-items:center;
    }
    .charcard .avatar{
      width:88px; height:88px; border-radius:50%;
      border:2px solid var(--border); background:var(--panel);
      object-fit:cover;
    }
    .char-meta small{ color: var(--muted); }
    .stat-row{ display:flex; gap:10px; flex-wrap:wrap; color:var(--muted) }
    .stat-row .badge{
      display:inline-block;padding:2px 8px;border:1px solid var(--border);border-radius:999px;background:var(--panel-2);
    }

    .hero-right{
      padding:20px; border-left:1px solid var(--border);
      background:
        linear-gradient(180deg, rgba(12,16,22,.5), rgba(12,16,22,.2)),
        url('public/images/auth/eldvar-art.jpg') center/cover no-repeat;
      min-height: 160px;
    }
    .progress{height:10px;background:#202a38;border-radius:999px;overflow:hidden;border:1px solid rgba(255,255,255,.08)}
    .progress > div{height:100%; background: linear-gradient(90deg, #6aa8ff, #a6c8ff);}
    .quick-actions{ display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
    .list-compact{ list-style:none; padding:0; margin:0; display:grid; gap:8px; }
    .list-compact li{ display:flex; justify-content:space-between; gap:10px; align-items:center;
      padding:8px 10px; border:1px solid var(--border); border-radius:10px; background:var(--panel-2); }
    .map-teaser{
      border:1px solid var(--border); border-radius:12px; padding:14px; background:var(--panel);
    }
    .map-grid{ display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:10px; }
    @media (max-width:1100px){ .map-grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width:700px){ .map-grid{ grid-template-columns: 1fr; } }
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="app-main">

      <!-- MMO-style banner / character card -->
      <section class="dash-hero">
        <div class="dash-hero-inner">
          <div class="charcard">
            <img class="avatar" src="<?= h($user['avatar_url'] ?? '/public/images/default.png') ?>" alt="Avatar">
            <div class="char-meta">
              <h1 class="pixel-title" style="margin:0"><?= h($display_name) ?></h1>
              <div class="stat-row">
                <span>Level <strong><?= (int)$user['level'] ?></strong></span>
                <?php if ($u_has_current_floor): ?>
                  <span>Floor <strong><?= (int)$user['current_floor'] ?></strong></span>
                <?php endif; ?>
                <?php if ($u_has_deepest_floor): ?>
                  <span>Deepest <strong><?= (int)$user['deepest_floor'] ?></strong></span>
                <?php endif; ?>
                <?php if ($user['last_seen']): ?>
                  <span class="badge">Last seen: <?= h((string)$user['last_seen']) ?></span>
                <?php endif; ?>
              </div>

              <?php if ($u_has_current_floor && $have_battles && $b_has_floor): ?>
                <div style="margin-top:10px;">
                  <small class="muted">Floor progress: <?= (int)$wins_on_current_floor ?> / <?= (int)$wins_required ?> wins to descend</small>
                  <div class="progress"><div style="width:<?= $progress_pct ?>%"></div></div>
                </div>
              <?php endif; ?>

              <div class="quick-actions">
                <a class="btn primary" href="<?= BASE_URL ?>/battle.php">
                  <?= $ongoing_battle ? 'Resume Battle' : 'Continue Adventure' ?>
                </a>
                <a class="btn" href="<?= BASE_URL ?>/world.php">World Map</a>
                <a class="btn" href="#">Daily Reward</a>
              </div>
            </div>
          </div>

          <div class="hero-right">
            <h3 class="pixel-title" style="margin:0 0 6px;">Patch Notes</h3>
            <p class="lead" style="margin:0 0 8px;">New towers have appeared in Yulon Forest and Undar. Void balance tweaks applied.</p>
            <a class="btn" href="<?= BASE_URL ?>/news.php">Read Full Notes</a>
          </div>
        </div>
      </section>

      <!-- Quick stats -->
      <section class="grid">
        <article class="card">
          <h2 class="card-title">Daily Wins</h2>
          <p class="muted">Victories recorded today</p>
          <h3 style="margin:.2em 0;"><?= (int)$wins_today ?></h3>
        </article>

        <article class="card">
          <h2 class="card-title">Status</h2>
          <?php if ($ongoing_battle): ?>
            <p>You have an <strong>ongoing</strong> battle vs <strong><?= h($ongoing_battle['mob_name']) ?></strong>.</p>
            <?php if ($b_has_floor): ?><p class="muted">Floor <?= (int)$ongoing_battle['floor'] ?></p><?php endif; ?>
            <a class="btn primary" href="<?= BASE_URL ?>/battle.php">Jump Back In</a>
          <?php else: ?>
            <p>No current battle. Ready to fight?</p>
            <a class="btn" href="<?= BASE_URL ?>/battle.php">Start a Battle</a>
          <?php endif; ?>
        </article>

        <article class="card">
          <h2 class="card-title">Adventurer Since</h2>
          <p class="muted">Account created</p>
          <h3 style="margin:.2em 0;"><?= h($user['created_at'] ? (string)$user['created_at'] : '—') ?></h3>
        </article>
      </section>

      <!-- World Map Teaser -->
      <section class="card" style="margin-top:16px;">
        <h2 class="card-title">World Map</h2>
        <div class="map-teaser">
          <p class="muted" style="margin-top:0">Pick a region to explore. Each area hosts unique towers and mob families.</p>
          <div class="map-grid">
            <div class="card">
              <strong>Mystic Harshlands</strong>
              <p class="muted">Harsh dust seas and arcane storms.</p>
              <a class="btn" href="<?= BASE_URL ?>/world.php?area=harshlands">Enter</a>
            </div>
            <div class="card">
              <strong>Yulon Forest</strong>
              <p class="muted">Ancient trees, whispering spirits.</p>
              <a class="btn" href="<?= BASE_URL ?>/world.php?area=yulon">Enter</a>
            </div>
            <div class="card">
              <strong>Reichal</strong>
              <p class="muted">Forgotten ruins and relic wards.</p>
              <a class="btn" href="<?= BASE_URL ?>/world.php?area=reichal">Enter</a>
            </div>
            <div class="card">
              <strong>Undar</strong>
              <p class="muted">Caverns where the void murmurs.</p>
              <a class="btn" href="<?= BASE_URL ?>/world.php?area=undar">Enter</a>
            </div>
            <div class="card">
              <strong>Frostbound Tundra</strong>
              <p class="muted">Howling winds and frost giants.</p>
              <a class="btn" href="<?= BASE_URL ?>/world.php?area=frostbound">Enter</a>
            </div>
          </div>
        </div>
      </section>

      <!-- Recent Battles Feed -->
      <section class="card" style="margin-top:16px;">
        <h2 class="card-title">Recent Battles</h2>
        <?php if (!$recent_battles): ?>
          <p class="muted">No battles recorded yet.</p>
        <?php else: ?>
          <ul class="list-compact">
            <?php foreach ($recent_battles as $b): ?>
              <li>
                <div>
                  <strong><?= h($b['mob_name']) ?></strong>
                  <?php if ($b_has_floor): ?><span class="muted"> · Floor <?= (int)$b['floor'] ?></span><?php endif; ?>
                  <span class="muted"> · <?= h($b['status']) ?></span>
                </div>
                <div class="muted">XP <?= (int)$b['reward_xp'] ?> / Gold <?= (int)$b['reward_gold'] ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <!-- Lore / News -->
      <section class="card" style="margin-top:16px;">
        <h2 class="card-title">Lore of Eldvar</h2>
        <p>Deep in the roots of the Elder Tree, shadows stir as void pressure grows. New towers rise across the regions—only the bold will reach their peaks.</p>
      </section>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>

  <!-- Floating chat -->
  <?php include __DIR__ . '/includes/chat.php'; ?>
</body>
</html>
