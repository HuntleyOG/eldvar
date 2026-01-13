<?php
declare(strict_types=1);

// /admin/gamesettings.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_acp(); // admin/governor only

/* ------------ helpers ------------ */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function gs_csrf_token(): string {
  if (!empty($_SESSION['gs_csrf'])) return $_SESSION['gs_csrf'];
  try { $_SESSION['gs_csrf'] = bin2hex(random_bytes(32)); }
  catch (Throwable) { $_SESSION['gs_csrf'] = sha1(uniqid('', true)); }
  return $_SESSION['gs_csrf'];
}
function gs_csrf_check(?string $t): bool {
  return !empty($t) && !empty($_SESSION['gs_csrf']) && hash_equals($_SESSION['gs_csrf'], $t);
}

/* ------------ DB bootstrap ------------ */
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/* Create settings table if missing */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS game_settings (
    `key`        VARCHAR(100) NOT NULL PRIMARY KEY,
    `value`      VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ------------ tunables (align with battle.php formulas) ------------ */
$SETTINGS = [
  // Progression
  'wins_required_per_floor' => [
    'label'   => 'Wins required to descend (per floor)',
    'section' => 'progression',
    'type'    => 'int', 'min' => 1, 'max' => 20,
    'default' => '3',
    'help'    => 'Victories needed on the current floor before a player can descend.',
  ],

  // Combat & Void
  'void_step_per_floor' => [
    'label'   => 'Void pressure increase per floor (%)',
    'section' => 'combat',
    'type'    => 'int', 'min' => 0, 'max' => 50,
    'default' => '3',
    'help'    => 'Per-floor increase; effective void = min(cap, floor × step).',
  ],
  'void_cap_percent' => [
    'label'   => 'Void pressure cap (%)',
    'section' => 'combat',
    'type'    => 'int', 'min' => 0, 'max' => 100,
    'default' => '60',
    'help'    => 'Maximum void pressure.',
  ],
  'player_acc_pen_divisor' => [
    'label'   => 'Player accuracy penalty divisor',
    'section' => 'combat',
    'type'    => 'float', 'min' => 1.0, 'max' => 50.0,
    'default' => '5.0',
    'help'    => 'Player acc penalty = void% / this.',
  ],
  'player_dmg_min_multiplier' => [
    'label'   => 'Player damage multiplier minimum',
    'section' => 'combat',
    'type'    => 'float', 'min' => 0.1, 'max' => 1.0,
    'default' => '0.70',
    'help'    => 'Lower bound for player damage multiplier under void.',
  ],
  'player_dmg_divisor' => [
    'label'   => 'Player damage divisor (void/this)',
    'section' => 'combat',
    'type'    => 'float', 'min' => 10.0, 'max' => 1000.0,
    'default' => '200.0',
    'help'    => 'Player dmg mult = max(min, 1 − void/this).',
  ],
  'mob_dmg_divisor' => [
    'label'   => 'Mob damage divisor (void/this)',
    'section' => 'combat',
    'type'    => 'float', 'min' => 10.0, 'max' => 1000.0,
    'default' => '200.0',
    'help'    => 'Mob dmg mult = 1 + void/this.',
  ],

  // Economy & Rewards
  'reward_xp_per_floor_pct' => [
    'label'   => 'XP scaling per floor (%)',
    'section' => 'economy',
    'type'    => 'float', 'min' => 0.0, 'max' => 100.0,
    'default' => '5.0',
    'help'    => 'Per-floor XP increase in percent.',
  ],
  'reward_gold_per_floor_pct' => [
    'label'   => 'Gold scaling per floor (%)',
    'section' => 'economy',
    'type'    => 'float', 'min' => 0.0, 'max' => 100.0,
    'default' => '4.0',
    'help'    => 'Per-floor gold increase in percent.',
  ],
];

/* ------------ load current values ------------ */
$current = [];
foreach ($pdo->query("SELECT `key`, `value` FROM game_settings") as $r) {
  $current[$r['key']] = (string)$r['value'];
}
$cur = function(string $key) use ($SETTINGS, $current): string {
  return $current[$key] ?? $SETTINGS[$key]['default'];
};

/* ------------ validation ------------ */
function gs_parse_and_validate(string $key, string $raw, array $SETTINGS): array {
  $meta = $SETTINGS[$key];
  $type = $meta['type'];
  $def  = (string)$meta['default'];
  $min  = $meta['min'] ?? null;
  $max  = $meta['max'] ?? null;

  if ($type === 'int') {
    if ($raw === '' || !is_numeric($raw)) return [false, $def, 'Must be a number'];
    $v = (int)round((float)$raw);
    if ($min !== null && $v < $min) return [false, $def, "Must be ≥ $min"];
    if ($max !== null && $v > $max) return [false, $def, "Must be ≤ $max"];
    return [true, (string)$v, null];
  }
  if ($type === 'float') {
    if ($raw === '' || !is_numeric($raw)) return [false, $def, 'Must be a number'];
    $v = (float)$raw;
    if ($min !== null && $v < $min) return [false, $def, "Must be ≥ $min"];
    if ($max !== null && $v > $max) return [false, $def, "Must be ≤ $max"];
    $v = rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    return [true, $v === '' ? '0' : $v, null];
  }
  return [true, trim($raw), null];
}

/* ------------ POST (no all-or-nothing rollback; PRG redirect) ------------ */
$errors = [];
$notices = [];

if (($_POST['action'] ?? '') === 'save' && gs_csrf_check($_POST['csrf'] ?? '')) {
  try {
    $stmt = $pdo->prepare("REPLACE INTO game_settings (`key`,`value`) VALUES (?,?)");

    foreach ($SETTINGS as $key => $meta) {
      $raw = isset($_POST[$key]) ? (string)$_POST[$key] : '';
      [$ok, $val, $err] = gs_parse_and_validate($key, $raw, $SETTINGS);
      if (!$ok) {
        $errors[] = $meta['label'] . ': ' . $err;
        continue; // keep going; save the rest
      }
      $stmt->execute([$key, $val]);
    }

    if (!$errors) {
      $_SESSION['gs_flash'] = 'Settings saved.';
    } else {
      $_SESSION['gs_flash'] = 'Some settings were saved, but there were errors: ' . implode(' • ', $errors);
    }

    // Redirect so the page reloads current DB values (avoids stale POST view)
    header('Location: ' . BASE_URL . '/admin/gamesettings.php');
    exit;
  } catch (Throwable $e) {
    $errors[] = 'Save failed: ' . $e->getMessage();
    // fall through to render errors without redirect
  }
}

// reload after PRG or initial view
$current = [];
foreach ($pdo->query("SELECT `key`, `value` FROM game_settings") as $r) {
  $current[$r['key']] = (string)$r['value'];
}
$cur = function(string $key) use ($SETTINGS, $current): string {
  return $current[$key] ?? $SETTINGS[$key]['default'];
};

// small debug line to confirm DB being used and rowcount
try {
  $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
  $rows   = (int)$pdo->query("SELECT COUNT(*) FROM game_settings")->fetchColumn();
} catch (Throwable $e) {
  $dbName = '(unknown)'; $rows = 0;
}

// flash message
$flash = $_SESSION['gs_flash'] ?? '';
unset($_SESSION['gs_flash']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Game Settings — Eldvar</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  <style>
    .settings-grid { display:grid; gap:12px; grid-template-columns: repeat(2, minmax(0,1fr)); }
    @media (max-width: 1100px){ .settings-grid{ grid-template-columns: 1fr; } }
    .setting { display:flex; flex-direction:column; gap:6px; padding:12px; border:1px solid rgba(255,255,255,.10); border-radius:12px; background:#0f1726; }
    .setting small { color: rgba(255,255,255,.65); }
    .section-card { padding:14px; border:1px solid rgba(255,255,255,.14); border-radius:12px; background:#0b1322; margin-bottom:14px; }
    .section-card h2 { margin: 0 0 8px 0; }
    .actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
    input[type=number], input[type=text] { background:#0b1322; border:1px solid rgba(255,255,255,.2); border-radius:8px; padding:.5rem .6rem; color:#e6edf3; }
    .meta { margin: 10px 0; font-size: 12px; opacity: .8; }
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <main class="app-main">
      <div class="acp-header" style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
        <h1 class="pixel-title">Game Settings</h1>
        <a class="btn" href="<?= BASE_URL ?>/admin/index.php">← Back to ACP</a>
      </div>

      <div class="meta">DB: <strong><?= h($dbName) ?></strong> — settings rows: <strong><?= (int)$rows ?></strong></div>

      <?php if ($flash): ?>
        <div class="alert success" style="margin-bottom:12px;"><?= h($flash) ?></div>
      <?php endif; ?>
      <?php if ($errors): ?>
        <div class="alert error" style="margin-bottom:12px;"><?php foreach ($errors as $e) echo '<div>'.h($e).'</div>'; ?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(gs_csrf_token()) ?>">

        <!-- Progression -->
        <section class="section-card">
          <h2 class="card-title">Progression</h2>
          <div class="settings-grid">
            <?php $k='wins_required_per_floor'; $m=$SETTINGS[$k]; $v=$cur($k); ?>
            <div class="setting">
              <label for="<?= h($k) ?>"><strong><?= h($m['label']) ?></strong></label>
              <input type="number" id="<?= h($k) ?>" name="<?= h($k) ?>"
                     step="1" min="<?= h((string)$m['min']) ?>" max="<?= h((string)$m['max']) ?>"
                     value="<?= h($v) ?>">
              <small><?= h($m['help']) ?></small>
            </div>
          </div>
        </section>

        <!-- Combat & Void -->
        <section class="section-card">
          <h2 class="card-title">Combat & Void</h2>
          <div class="settings-grid">
            <?php foreach (['void_step_per_floor','void_cap_percent','player_acc_pen_divisor','player_dmg_min_multiplier','player_dmg_divisor','mob_dmg_divisor'] as $k): $m=$SETTINGS[$k]; $v=$cur($k); ?>
              <div class="setting">
                <label for="<?= h($k) ?>"><strong><?= h($m['label']) ?></strong></label>
                <input
                  type="number"
                  id="<?= h($k) ?>" name="<?= h($k) ?>"
                  step="<?= $m['type']==='float' ? '0.01' : '1' ?>"
                  <?php if (isset($m['min'])): ?>min="<?= h((string)$m['min']) ?>"<?php endif; ?>
                  <?php if (isset($m['max'])): ?>max="<?= h((string)$m['max']) ?>"<?php endif; ?>
                  value="<?= h($v) ?>">
                <small><?= h($m['help']) ?></small>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <!-- Economy & Rewards -->
        <section class="section-card">
          <h2 class="card-title">Economy & Rewards</h2>
          <div class="settings-grid">
            <?php foreach (['reward_xp_per_floor_pct','reward_gold_per_floor_pct'] as $k): $m=$SETTINGS[$k]; $v=$cur($k); ?>
              <div class="setting">
                <label for="<?= h($k) ?>"><strong><?= h($m['label']) ?></strong></label>
                <input
                  type="number"
                  id="<?= h($k) ?>" name="<?= h($k) ?>"
                  step="0.01"
                  min="<?= h((string)$m['min']) ?>"
                  max="<?= h((string)$m['max']) ?>"
                  value="<?= h($v) ?>">
                <small><?= h($m['help']) ?></small>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <div class="actions">
          <button class="btn primary" name="action" value="save" type="submit">Save Settings</button>
        </div>
      </form>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
  </div>
</body>
</html>
