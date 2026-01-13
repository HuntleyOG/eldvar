<?php
declare(strict_types=1);

require __DIR__ . '/config/config.php';
require __DIR__ . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* --- Require login (same as dashboard) --- */
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}

/* ---------- Helpers ---------- */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function intish(mixed $v, int $d = 0): int { return is_numeric($v) ? (int)$v : $d; }
function floatish(mixed $v, float $d = 0.0): float { return is_numeric($v) ? (float)$v : $d; }
function safe_random_int(int $min, int $max): int {
  try { return random_int($min, $max); }
  catch (Throwable) { return $min + (mt_rand() % max(1, $max - $min + 1)); }
}
function csrf_token(): string {
  if (!empty($_SESSION['csrf'])) return $_SESSION['csrf'];
  try { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
  catch (Throwable) { $_SESSION['csrf'] = hash('sha256', (session_id() ?: uniqid('', true)).'|'.microtime(true).'|'.mt_rand()); }
  return $_SESSION['csrf'];
}
function csrf_check(?string $t): bool { return !empty($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t); }

/* information_schema helpers (placeholders allowed) */
function table_exists(PDO $pdo, string $name): bool {
  try {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $st->execute([$name]); return (bool)$st->fetchColumn();
  } catch (Throwable) { return false; }
}
function column_exists(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$table, $column]); return (bool)$st->fetchColumn();
  } catch (Throwable) { return false; }
}

/* ---------- Game settings (with safe fallbacks) ---------- */
function gs_all(PDO $pdo): array {
  try {
    $st = $pdo->query("SELECT `key`,`value` FROM game_settings");
    $rows = $st ? $st->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    return is_array($rows) ? $rows : [];
  } catch (Throwable) {
    return [];
  }
}
function gs_int(array $all, string $key, int $default, ?int $min = null, ?int $max = null): int {
  $v = isset($all[$key]) ? (int)round((float)$all[$key]) : $default;
  if ($min !== null && $v < $min) $v = $min;
  if ($max !== null && $v > $max) $v = $max;
  return $v;
}
function gs_float(array $all, string $key, float $default, ?float $min = null, ?float $max = null): float {
  $v = isset($all[$key]) && is_numeric($all[$key]) ? (float)$all[$key] : $default;
  if ($min !== null && $v < $min) $v = $min;
  if ($max !== null && $v > $max) $v = $max;
  return $v;
}
function scaled_reward(int $base, int $floor, float $perPct): int {
  $per = max(0.0, $perPct) / 100.0;
  return max(0, (int)round($base * (1 + $per * max(0, $floor - 1))));
}
function required_wins_for_floor_from_settings(int $floor, int $wins_required_per_floor): int {
  return max(1, $wins_required_per_floor);
}

/* ---------- DB ---------- */
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$user_id = (int)$_SESSION['user_id'];

/* ---------- Capabilities ---------- */
$has_user_floor     = table_exists($pdo,'users')   && column_exists($pdo,'users','current_floor') && column_exists($pdo,'users','deepest_floor');
$has_overall_xp     = table_exists($pdo,'users')   && column_exists($pdo,'users','overall_xp');
$has_mob_ranges     = table_exists($pdo,'mobs')    && column_exists($pdo,'mobs','min_floor')      && column_exists($pdo,'mobs','max_floor');
$have_mobs          = table_exists($pdo, 'mobs');
$have_battles       = table_exists($pdo, 'battles');
$have_battle_turns  = table_exists($pdo, 'battle_turns');
$has_battle_floor   = $have_battles && column_exists($pdo,'battles','floor') && column_exists($pdo,'battles','void_intensity');
$has_battle_style   = $have_battles && column_exists($pdo,'battles','combat_style'); // optional column

/* ---------- Load server tunables ---------- */
$GS = gs_all($pdo);
/* Defaults mirror /admin/gamesettings.php */
$wins_required_per_floor   = gs_int  ($GS, 'wins_required_per_floor',   3, 1, 20);
$void_step_per_floor       = gs_int  ($GS, 'void_step_per_floor',       3, 0, 50);   // %
$void_cap_percent          = gs_int  ($GS, 'void_cap_percent',         60, 0, 100);  // %
$player_acc_pen_divisor    = gs_float($GS, 'player_acc_pen_divisor',  5.0, 1.0, 50.0);
$player_dmg_min_multiplier = gs_float($GS, 'player_dmg_min_multiplier',0.70, 0.10, 1.00);
$player_dmg_divisor        = gs_float($GS, 'player_dmg_divisor',     200.0, 10.0, 1000.0);
$mob_dmg_divisor           = gs_float($GS, 'mob_dmg_divisor',        200.0, 10.0, 1000.0);
$reward_xp_per_floor_pct   = gs_float($GS, 'reward_xp_per_floor_pct',   5.0, 0.0, 100.0);
$reward_gold_per_floor_pct = gs_float($GS, 'reward_gold_per_floor_pct', 4.0, 0.0, 100.0);

/* ---------- World Map context ---------- */
$area  = $_SESSION['world_area']  ?? 'Mystic Harshlands';
$tower = $_SESSION['world_tower'] ?? 'Eldvar Tower';

/* ---------- Load user ---------- */
$u = ['username'=>'Adventurer','display_name'=>null,'level'=>1,'current_floor'=>1,'deepest_floor'=>1];
try {
  $sel = "SELECT username, display_name, level" . ($has_user_floor ? ", current_floor, deepest_floor" : "") . " FROM users WHERE id = ? LIMIT 1";
  $st = $pdo->prepare($sel); $st->execute([$user_id]); $row = $st->fetch();
  if ($row) {
    $u['username']      = (string)$row['username'];
    $u['display_name']  = $row['display_name'] !== null ? (string)$row['display_name'] : null;
    $u['level']         = intish($row['level'] ?? 1, 1);
    if ($has_user_floor) {
      $u['current_floor'] = max(1, intish($row['current_floor'] ?? 1, 1));
      $u['deepest_floor'] = max(1, intish($row['deepest_floor'] ?? 1, 1));
    }
  }
} catch (Throwable) {}
$floor = $has_user_floor ? $u['current_floor'] : 1;

/* ---------- Derive combat stats from level ---------- */
$level = max(1, (int)$u['level']);
$player = [
  'id'           => $user_id,
  'name'         => $u['display_name'] ?: $u['username'],
  'level'        => $level,
  'attack'       => 3 + (int)floor($level / 2),
  'strength'     => 3 + (int)floor($level / 2),
  'defense'      => 3 + (int)floor($level / 2),
  'magic'        => 2 + (int)floor($level / 3),
  'range'        => 2 + (int)floor($level / 3),
  'constitution' => 5 + (int)floor($level / 2),
];
$player['hp_max']       = 50 + ($player['constitution'] * 10);
$player['melee_power']  = $player['attack'] + $player['strength'];
$player['ranged_power'] = $player['range'];
$player['magic_power']  = $player['magic'];

/* ---------- Skills map (style -> skill_id) ---------- */
$skillIdByKey = [];
if (table_exists($pdo,'skills') && column_exists($pdo,'skills','skey')) {
  $q = $pdo->query("SELECT id, skey FROM skills WHERE skey IN ('attack','strength','defense','range','magic')");
  foreach ($q->fetchAll() as $r) { $skillIdByKey[(string)$r['skey']] = (int)$r['id']; }
}
/* Fallbacks (IDs are harmless if skills table missing; awarding will be skipped) */
$STYLE_KEYS = ['attack','strength','defense','range','magic'];

/* ---------- Player-chosen combat style (persisted) ---------- */
$chosenStyle = $_SESSION['combat_style'] ?? 'attack';
if (!in_array($chosenStyle, $STYLE_KEYS, true)) $chosenStyle = 'attack';
if (isset($_POST['combat_style']) && in_array($_POST['combat_style'], $STYLE_KEYS, true) && csrf_check($_POST['csrf'] ?? '')) {
  $chosenStyle = $_POST['combat_style'];
  $_SESSION['combat_style'] = $chosenStyle;
}

/* ---------- Schema presence (tables) ---------- */
$schema_note = '';
if (!$have_mobs || !$have_battles || !$have_battle_turns) {
  $missing = [];
  if (!$have_mobs)         $missing[] = 'mobs';
  if (!$have_battles)      $missing[] = 'battles';
  if (!$have_battle_turns) $missing[] = 'battle_turns';
  $schema_note = 'Missing tables: ' . implode(', ', $missing) . '. Please install the schema.';
}

/* ---------- Current battle ---------- */
$battle = null;
if ($have_battles) {
  try {
    $st = $pdo->prepare("SELECT * FROM battles WHERE user_id=? AND status='ongoing' ORDER BY id DESC LIMIT 1");
    $st->execute([$user_id]);
    $battle = $st->fetch() ?: null;
  } catch (Throwable) {}
}

/* ---------- Wins this floor (for descent gating) ---------- */
$wins_this_floor = 0;
if ($have_battles && $has_battle_floor && $has_user_floor) {
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM battles WHERE user_id=? AND status='won' AND floor=?");
    $st->execute([$user_id, $floor]);
    $wins_this_floor = (int)$st->fetchColumn();
  } catch (Throwable) {}
}
$wins_required = required_wins_for_floor_from_settings($floor, $wins_required_per_floor);
$can_descend   = ($has_user_floor && $has_battle_floor && $wins_this_floor >= $wins_required);

/* ---------- Actions ---------- */
$errors = [];
$notices = [];

/* DESCEND */
if (($_POST['action_type'] ?? '') === 'descend' && csrf_check($_POST['csrf'] ?? '')) {
  if (!$has_user_floor || !$has_battle_floor) {
    $errors[] = "Descending isn’t available yet.";
  } elseif (!$can_descend) {
    $errors[] = "You must defeat at least {$wins_required} enemies on Floor {$floor} before descending.";
  } else {
    try {
      $pdo->beginTransaction();
      $pdo->prepare("UPDATE users
                     SET current_floor = current_floor + 1,
                         deepest_floor = GREATEST(deepest_floor, current_floor + 1)
                     WHERE id = ?")->execute([$user_id]);
      $pdo->commit();
      header('Location: battle.php'); exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = "Descend failed: " . $e->getMessage();
    }
  }
}

/* START battle */
if (!$schema_note && ($_POST['action_type'] ?? '') === 'start' && csrf_check($_POST['csrf'] ?? '')) {
  try {
    $mid = intish($_POST['mob_id'] ?? 0);
    if (!$mid) throw new RuntimeException('Invalid opponent.');
    if ($battle) throw new RuntimeException('You already have an ongoing battle.');

    $m = $pdo->prepare("SELECT * FROM mobs WHERE id=?");
    $m->execute([$mid]);
    $mob = $m->fetch();
    if (!$mob) throw new RuntimeException('That opponent does not exist.');

    // Compute void intensity if supported
    $void = 0;
    if ($has_battle_floor) {
      $void = max(0, min($void_cap_percent, $floor * $void_step_per_floor)); // %
    }

    if ($has_battle_floor) {
      $sql = "INSERT INTO battles
        (user_id, character_id, mob_id, char_name, char_hp_current, char_hp_max,
         mob_name, mob_hp_current, mob_hp_max, reward_xp, reward_gold, floor, void_intensity"
        . ($has_battle_style ? ", combat_style" : "")
        . ")
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?"
        . ($has_battle_style ? ",?" : "")
        . ")";
      $args = [
        $user_id, null, (int)$mob['id'], $player['name'],
        $player['hp_max'], $player['hp_max'],
        $mob['name'], (int)$mob['hp'], (int)$mob['hp'],
        (int)$mob['reward_xp'], (int)$mob['reward_gold'],
        $floor, $void
      ];
      if ($has_battle_style) $args[] = $chosenStyle;
    } else {
      $sql = "INSERT INTO battles
        (user_id, character_id, mob_id, char_name, char_hp_current, char_hp_max,
         mob_name, mob_hp_current, mob_hp_max, reward_xp, reward_gold"
        . ($has_battle_style ? ", combat_style" : "")
        . ")
        VALUES (?,?,?,?,?,?,?,?,?,?,?"
        . ($has_battle_style ? ",?" : "")
        . ")";
      $args = [
        $user_id, null, (int)$mob['id'], $player['name'],
        $player['hp_max'], $player['hp_max'],
        $mob['name'], (int)$mob['hp'], (int)$mob['hp'],
        (int)$mob['reward_xp'], (int)$mob['reward_gold']
      ];
      if ($has_battle_style) $args[] = $chosenStyle;
    }

    $pdo->prepare($sql)->execute($args);

    $st = $pdo->prepare("SELECT * FROM battles WHERE user_id=? AND status='ongoing' ORDER BY id DESC LIMIT 1");
    $st->execute([$user_id]);
    $battle = $st->fetch();

    $notices[] = "A wild ".h($mob['name'])." appears!";
  } catch (Throwable $e) {
    $errors[] = "Start battle failed: " . $e->getMessage();
  }
}

/* TURN */
if (!$schema_note && ($_POST['action_type'] ?? '') === 'turn' && $battle && csrf_check($_POST['csrf'] ?? '')) {
  try {
    // Refresh selected style if form posted
    if (!empty($_POST['combat_style']) && in_array($_POST['combat_style'], $STYLE_KEYS, true)) {
      $chosenStyle = $_POST['combat_style'];
      $_SESSION['combat_style'] = $chosenStyle;
      if ($has_battle_style) {
        $pdo->prepare("UPDATE battles SET combat_style=? WHERE id=? AND user_id=?")->execute([$chosenStyle, (int)$battle['id'], $user_id]);
      }
    }

    // refresh battle row
    $bst = $pdo->prepare("SELECT * FROM battles WHERE id=? AND user_id=? LIMIT 1");
    $bst->execute([(int)$battle['id'], $user_id]);
    $battle = $bst->fetch() ?: $battle;

    if (($battle['status'] ?? 'ongoing') !== 'ongoing') throw new RuntimeException('This battle is already over.');

    // load mob
    $m = $pdo->prepare("SELECT * FROM mobs WHERE id=?");
    $m->execute([(int)$battle['mob_id']]);
    $mob = $m->fetch();
    if (!$mob) throw new RuntimeException('Monster vanished.');

    // turn number
    $tn = 1;
    if ($have_battle_turns) {
      $tn = (int)$pdo->query("SELECT COALESCE(MAX(turn_no),0)+1 FROM battle_turns WHERE battle_id=".(int)$battle['id'])->fetchColumn();
      if ($tn < 1) $tn = 1;
    }

    // void effects (from settings, if column exists)
    $void = $has_battle_floor ? (int)($battle['void_intensity'] ?? 0) : 0;
    $player_acc_pen  = max(0.0, $void / max(1.0, $player_acc_pen_divisor));                  // percent points
    $player_dmg_mult = max($player_dmg_min_multiplier, 1.0 - ($void / max(1.0, $player_dmg_divisor))); // down to configured min
    $mob_dmg_mult    = 1.0 + ($void / max(1.0, $mob_dmg_divisor));                           // increases with void

    // Power routing by style
    $pwrByStyle = [
      'attack'   => $player['melee_power'],
      'strength' => $player['melee_power'],
      'defense'  => (int)floor($player['melee_power'] * 0.9), // defensive stance: slightly lower dmg
      'range'    => $player['ranged_power'],
      'magic'    => $player['magic_power'],
    ];
    $pwr = max(1, $pwrByStyle[$chosenStyle] ?? $player['melee_power']);

    $acc = $player['attack'];
    $eva = max(0, (int)$mob['level'] + (int)$mob['defense']);

    $base_hit = max(35, min(95, 70 + ($acc - $eva)));
    $hit = safe_random_int(1,100) <= max(5, (int)round($base_hit - $player_acc_pen));
    $raw = (int)ceil($pwr * 0.6 + safe_random_int(0, max(1, (int)ceil($pwr * 0.5))));
    $raw = (int)ceil($raw * $player_dmg_mult);
    $mit = (int)floor(((int)$mob['defense']) * 0.35);
    $dmg = $hit ? max(0, $raw - $mit) : 0;

    $mob_after = max(0, (int)$battle['mob_hp_current'] - $dmg);
    $you_after = (int)$battle['char_hp_current'];

    if ($have_battle_turns) {
      $pdo->prepare("INSERT INTO battle_turns (battle_id, turn_no, actor, action, damage, char_hp_after, mob_hp_after, log_text)
                     VALUES (?,?,?,?,?,?,?,?)")
          ->execute([(int)$battle['id'], $tn, 'player', $chosenStyle, $dmg, $you_after, $mob_after,
                     $hit ? "You strike with {$chosenStyle} for $dmg." : "You miss your {$chosenStyle} attack!"]);
    }

    if ($mob_after <= 0) {
      $pdo->prepare("UPDATE battles SET mob_hp_current=?, status='won' WHERE id=?")
          ->execute([$mob_after, (int)$battle['id']]);
      $battle['mob_hp_current'] = 0;
      $battle['status'] = 'won';
      $notices[] = "You defeated the ".h($mob['name'])."!";

      // Rewards (scaled by floor if enabled)
      $floor_for_display = $has_battle_floor ? (int)$battle['floor'] : $floor;
      $reward_xp   = $has_battle_floor ? scaled_reward((int)$battle['reward_xp'], $floor_for_display, $reward_xp_per_floor_pct) : (int)$battle['reward_xp'];
      $reward_gold = $has_battle_floor ? scaled_reward((int)$battle['reward_gold'], $floor_for_display, $reward_gold_per_floor_pct) : (int)$battle['reward_gold'];

      // === AWARD XP to chosen combat skill (DB triggers handle level + overall_xp) ===
      $skillId = $skillIdByKey[$chosenStyle] ?? null;
      if ($skillId) {
        try {
          $pdo->beginTransaction();

          // Level before (for instant toast)
          $pre = $pdo->prepare("SELECT level FROM user_skills WHERE user_id=? AND skill_id=? LIMIT 1");
          $pre->execute([$user_id, $skillId]);
          $beforeLevel = (int)$pre->fetchColumn();

          // Increment skill XP
          $upd = $pdo->prepare("UPDATE user_skills SET xp = xp + :gain WHERE user_id = :uid AND skill_id = :sid");
          $upd->execute([':gain'=>$reward_xp, ':uid'=>$user_id, ':sid'=>$skillId]);

          // Level after
          $post = $pdo->prepare("SELECT level FROM user_skills WHERE user_id=? AND skill_id=? LIMIT 1");
          $post->execute([$user_id, $skillId]);
          $afterLevel = (int)$post->fetchColumn();

          $pdo->commit();

          if ($afterLevel > $beforeLevel) {
            $pretty = ucfirst($chosenStyle);
            $notices[] = "🎉 {$pretty} leveled up to {$afterLevel}!";
          }
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          // Don’t fail the battle screen for XP issues; just log as notice
          $errors[] = "XP award failed: " . $e->getMessage();
        }
      }

      // No items yet; show gold as a preview only
      $notices[] = "Rewards: +{$reward_xp} xp" . ($reward_gold > 0 ? " and {$reward_gold} gold" : "");

    } else {
      // Mob -> Player
      $mp   = max(1, (int)$mob['attack']);
      $mraw = (int)ceil($mp * 0.6 + safe_random_int(0, max(1, (int)ceil($mp * 0.5))));
      $mraw = (int)ceil($mraw * $mob_dmg_mult);
      $mmit = (int)floor($player['defense'] * 0.35);
      $mhit = safe_random_int(1,100) <= max(30, min(90, 65 + ((int)$mob['level'] - $player['defense'])));
      $mdmg = $mhit ? max(0, $mraw - $mmit) : 0;

      $you_after = max(0, (int)$battle['char_hp_current'] - $mdmg);

      if ($have_battle_turns) {
        $pdo->prepare("INSERT INTO battle_turns (battle_id, turn_no, actor, action, damage, char_hp_after, mob_hp_after, log_text)
                       VALUES (?,?,?,?,?,?,?,?)")
            ->execute([(int)$battle['id'], $tn, 'mob', 'attack', $mdmg, $you_after, $mob_after,
                       $mhit ? "The {$mob['name']} hits you for $mdmg." : "The {$mob['name']} misses!"]);
      }

      $new_status = ($you_after <= 0) ? 'lost' : 'ongoing';
      $pdo->prepare("UPDATE battles SET char_hp_current=?, mob_hp_current=?, status=? WHERE id=?")
          ->execute([$you_after, $mob_after, $new_status, (int)$battle['id']]);
      $battle['char_hp_current'] = $you_after;
      $battle['mob_hp_current']  = $mob_after;
      $battle['status']          = $new_status;
    }
  } catch (Throwable $e) {
    $errors[] = "Turn failed: " . $e->getMessage();
  }
}

/* RESET */
if (!$schema_note && ($_POST['action_type'] ?? '') === 'reset' && $battle && csrf_check($_POST['csrf'] ?? '')) {
  try {
    $pdo->prepare("DELETE FROM battles WHERE id=? AND user_id=?")->execute([(int)$battle['id'], $user_id]);
    $battle = null;
    $notices[] = "Battle cleared.";
  } catch (Throwable $e) {
    $errors[] = "Reset failed: " . $e->getMessage();
  }
}

/* ---------- View data ---------- */
$available_mobs = [];
try {
  if ($have_mobs) {
    if ($has_mob_ranges && $has_user_floor) {
      $st = $pdo->prepare("SELECT * FROM mobs WHERE min_floor <= ? AND ? <= max_floor ORDER BY level ASC, id ASC");
      $st->execute([$floor, $floor]);
      $available_mobs = $st->fetchAll();
    } else {
      $available_mobs = $pdo->query("SELECT * FROM mobs ORDER BY level ASC, id ASC")->fetchAll();
    }
  }
} catch (Throwable $e) { $errors[] = "Load mobs failed: " . $e->getMessage(); }

$turns = [];
if ($battle && $have_battle_turns) {
  try {
    $ts = $pdo->prepare("SELECT * FROM battle_turns WHERE battle_id=? ORDER BY id DESC LIMIT 30");
    $ts->execute([(int)$battle['id']]);
    $turns = $ts->fetchAll();
  } catch (Throwable $e) { $errors[] = "Load turns failed: " . $e->getMessage(); }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Battle — Eldvar</title>
  <link rel="stylesheet" href="public/css/style.css">
  <style>
    .hpbar { height: 10px; background: #202a38; border-radius: 999px; overflow: hidden; border: 1px solid rgba(255,255,255,.08); }
    .hpbar > div { height: 100%; background: linear-gradient(90deg, #6aa8ff, #a6c8ff); transition: width .3s ease; }
    .pill-group { display:flex; gap:.5rem; flex-wrap:wrap; }
    .pill-group input[type=radio]{position:absolute;opacity:0;pointer-events:none;}
    .pill-group label{display:inline-flex;align-items:center;padding:.45rem .8rem;border:1px solid rgba(255,255,255,.12);border-radius:999px;background:#0f1726;cursor:pointer}
    .pill-group input[type=radio]:checked + label{border-color:#4d95ff;box-shadow:inset 0 0 0 1px rgba(77,149,255,.35);background:rgba(77,149,255,.12)}
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="app-main">

      <section class="grid">
        <!-- Left: player/alerts -->
        <article class="card">
          <h1 class="card-title">Battle</h1>
          <p class="muted">Turn-based PvE encounter.</p>

          <div class="card" style="margin-top:8px;">
            <strong>Area:</strong> <?= h($area) ?> —
            <strong>Tower:</strong> <?= h($tower) ?>
            <a class="btn" style="margin-left:8px;" href="<?= h(BASE_URL) ?>/locations.php">Open World Map</a>
          </div>

          <?php if ($has_user_floor): ?>
            <p class="muted" style="margin-top:8px;">
              You are on <strong>Floor <?= (int)$floor ?></strong>
              <?php if ($has_battle_floor): ?>
                <?php $void_preview = max(0, min($void_cap_percent, $floor * $void_step_per_floor)); ?>
                — Void pressure: <?= (int)$void_preview ?>%
              <?php endif; ?><br>
              Progress: <?= (int)$wins_this_floor ?> / <?= (int)$wins_required ?> wins required to descend
            </p>
          <?php endif; ?>

          <?php if ($errors): ?>
            <div class="alert error" style="margin-top:10px;"><?php foreach ($errors as $e) echo '<div>'.h($e).'</div>'; ?></div>
          <?php endif; ?>
          <?php if ($notices): ?>
            <div class="alert success" style="margin-top:10px;"><?php foreach ($notices as $n) echo '<div>'.h($n).'</div>'; ?></div>
          <?php endif; ?>
          <?php if ($schema_note): ?>
            <div class="alert info" style="margin-top:10px;"><?= h($schema_note) ?></div>
          <?php endif; ?>

          <div class="card" style="margin-top:12px;">
            <h2 class="card-title">Your Stats</h2>
            <p class="muted">Name: <?= h($player['name']) ?> · Level: <?= (int)$player['level'] ?> · HP max: <?= (int)$player['hp_max'] ?></p>
            <p class="muted">
              ATK <?= (int)$player['attack'] ?> · STR <?= (int)$player['strength'] ?> ·
              DEF <?= (int)$player['defense'] ?> · RNG <?= (int)$player['range'] ?> ·
              MGK <?= (int)$player['magic'] ?> · CON <?= (int)$player['constitution'] ?>
            </p>
          </div>

          <!-- Choose combat style (persists during battle) -->
          <form method="post" class="card" style="margin-top:12px;">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action_type" value="turn">
            <h2 class="card-title">Combat Style</h2>
            <div class="pill-group">
              <?php foreach ($STYLE_KEYS as $sk): ?>
                <?php $id = "style-{$sk}"; ?>
                <input id="<?= h($id) ?>" type="radio" name="combat_style" value="<?= h($sk) ?>" <?= $chosenStyle===$sk?'checked':''; ?>>
                <label for="<?= h($id) ?>"><?= ['attack'=>'⚔️ Attack','strength'=>'💪 Strength','defense'=>'🛡️ Defense','range'=>'🏹 Range','magic'=>'✨ Magic'][$sk] ?></label>
              <?php endforeach; ?>
            </div>
            <div class="muted" style="margin-top:6px;">Your selection persists between turns.</div>
            <div style="margin-top:10px;"><button class="btn" type="submit">Save Style</button></div>
          </form>
        </article>

        <!-- Right: battle panel or mob list -->
        <article class="card">
          <?php if (!$schema_note && $battle): ?>
            <?php
              $floor_for_display = $has_battle_floor ? (int)$battle['floor'] : $floor;
              $void_for_display  = $has_battle_floor ? (int)$battle['void_intensity'] : 0;
              $c = max(0, min(100, (int)floor($battle['char_hp_current'] / max(1, $battle['char_hp_max']) * 100)));
              $m = max(0, min(100, (int)floor($battle['mob_hp_current']  / max(1, $battle['mob_hp_max'])  * 100)));
              $reward_xp   = $has_battle_floor ? scaled_reward((int)$battle['reward_xp'], $floor_for_display, $reward_xp_per_floor_pct) : (int)$battle['reward_xp'];
              $reward_gold = $has_battle_floor ? scaled_reward((int)$battle['reward_gold'], $floor_for_display, $reward_gold_per_floor_pct) : (int)$battle['reward_gold'];
            ?>
            <div class="grid" style="grid-template-columns: 1fr 1fr;">
              <div>
                <h2 class="card-title"><?= h($player['name']) ?></h2>
                <div class="hpbar"><div style="width: <?= $c ?>%"></div></div>
                <p class="muted"><?= (int)$battle['char_hp_current'] ?> / <?= (int)$battle['char_hp_max'] ?> HP</p>
              </div>
              <div>
                <h2 class="card-title" style="text-align:right;"><?= h($battle['mob_name']) ?></h2>
                <div class="hpbar"><div style="width: <?= $m ?>%"></div></div>
                <p class="muted" style="text-align:right;"><?= (int)$battle['mob_hp_current'] ?> / <?= (int)$battle['mob_hp_max'] ?> HP</p>
              </div>
            </div>

            <?php if ($has_battle_floor): ?>
              <p class="muted" style="margin-top:6px;">Floor <?= (int)$floor_for_display ?> · Void pressure <?= (int)$void_for_display ?>%</p>
            <?php endif; ?>

            <?php if ($battle['status'] === 'ongoing'): ?>
              <form method="post" class="card" style="margin-top:12px;">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action_type" value="turn">
                <h3 class="card-title">Take your turn</h3>
                <div class="pill-group">
                  <?php foreach ($STYLE_KEYS as $sk): ?>
                    <?php $id2 = "go-{$sk}"; ?>
                    <input id="<?= h($id2) ?>" type="radio" name="combat_style" value="<?= h($sk) ?>" <?= $chosenStyle===$sk?'checked':''; ?>>
                    <label for="<?= h($id2) ?>"><?= ['attack'=>'⚔️ Attack','strength'=>'💪 Strength','defense'=>'🛡️ Defense','range'=>'🏹 Range','magic'=>'✨ Magic'][$sk] ?></label>
                  <?php endforeach; ?>
                </div>
                <div style="margin-top:10px;"><button class="btn primary" type="submit">Attack</button></div>
              </form>
            <?php else: ?>
              <div class="alert <?= $battle['status']==='won' ? 'success' : 'error' ?>" style="margin-top:12px;">
                <?= $battle['status']==='won' ? 'Victory!' : 'Defeat… try again.' ?>
                <?php if ($battle['status']==='won'): ?>
                  <div class="muted">Rewards preview: <?= (int)$reward_xp ?> XP<?= $reward_gold>0 ? ", {$reward_gold} gold" : "" ?></div>
                <?php endif; ?>
              </div>
              <form method="post" style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <button class="btn" name="action_type" value="reset">End Battle</button>
                <?php if ($can_descend): ?>
                  <button class="btn primary" name="action_type" value="descend">Descend to Floor <?= (int)($floor + 1) ?></button>
                <?php else: ?>
                  <?php if ($has_user_floor && $has_battle_floor): ?>
                    <button class="btn primary" type="button" disabled title="Defeat more enemies to descend">
                      Need <?= (int)max(0, $wins_required - $wins_this_floor) ?> more win<?= ($wins_required - $wins_this_floor) === 1 ? '' : 's' ?>
                    </button>
                  <?php endif; ?>
                <?php endif; ?>
              </form>
            <?php endif; ?>

            <div class="card" style="margin-top:12px;">
              <h3 class="card-title">Battle Log</h3>
              <?php if (!$turns): ?>
                <p class="muted">No turns yet.</p>
              <?php else: ?>
                <ol reversed style="padding-left:18px;margin:0;">
                  <?php foreach ($turns as $t): ?>
                    <li style="margin:6px 0;">
                      <span class="badge"><?= h($t['actor']) ?></span>
                      <?= h($t['log_text']) ?>
                      <span class="muted">[You: <?= (int)$t['char_hp_after'] ?> | <?= h($battle['mob_name']) ?>: <?= (int)$t['mob_hp_after'] ?>]</span>
                    </li>
                  <?php endforeach; ?>
                </ol>
              <?php endif; ?>
            </div>

          <?php else: ?>
            <h2 class="card-title">Choose an opponent</h2>
            <?php if ($schema_note): ?><div class="alert info"><?= h($schema_note) ?></div><?php endif; ?>

            <?php if ($has_user_floor): ?>
              <p class="muted">
                <strong><?= h($area) ?></strong> · <?= h($tower) ?><br>
                Floor <?= (int)$floor ?>
                <?php if ($has_battle_floor): ?>
                  <?php $void_preview = max(0, min($void_cap_percent, $floor * $void_step_per_floor)); ?>
                  · Void pressure <?= (int)$void_preview ?>%
                <?php endif; ?><br>
                Progress: <?= (int)$wins_this_floor ?> / <?= (int)$wins_required ?> wins required to descend
              </p>
            <?php endif; ?>

            <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; margin-top: 8px;">
              <?php foreach ($available_mobs as $m): ?>
                <div class="card">
                  <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                    <strong><?= h($m['name']) ?></strong>
                    <span class="badge">Lv <?= (int)$m['level'] ?></span>
                  </div>
                  <p class="muted" style="margin-top:6px;">
                    HP <?= (int)$m['hp'] ?> · ATK <?= (int)$m['attack'] ?> · DEF <?= (int)$m['defense'] ?> · MGK <?= (int)$m['magic'] ?> · RNG <?= (int)$m['range'] ?>
                    <?php if ($has_mob_ranges && $has_user_floor): ?>
                      <br><span class="muted">Floors <?= (int)$m['min_floor'] ?>–<?= (int)$m['max_floor'] ?></span>
                    <?php endif; ?>
                  </p>
                  <p class="muted" style="margin-top:4px;">Rewards: <?= (int)$m['reward_xp'] ?> XP / <?= (int)$m['reward_gold'] ?> gold</p>
                  <form method="post" style="margin-top:8px;">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action_type" value="start">
                    <input type="hidden" name="mob_id" value="<?= (int)$m['id'] ?>">
                    <button class="btn primary" type="submit" <?= $schema_note ? 'disabled' : '' ?>>Fight</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </article>
      </section>

    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>

  <?php include __DIR__ . '/includes/chat.php'; ?>
</body>
</html>
