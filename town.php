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

/* ---------- Load minimal user info ---------- */
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$user = [
  'username'      => 'Adventurer',
  'display_name'  => null,
  'level'         => 1,
  'current_floor' => null,
  'area_code'     => null,
];

try {
  // Grab what we safely can (columns may or may not exist)
  $has_display = column_exists($pdo, 'users', 'display_name');
  $has_level   = column_exists($pdo, 'users', 'level');
  $has_floor   = column_exists($pdo, 'users', 'current_floor');
  $has_area    = column_exists($pdo, 'users', 'current_area_code');

  $cols = ['username'];
  if ($has_display) $cols[] = 'display_name';
  if ($has_level)   $cols[] = 'level';
  if ($has_floor)   $cols[] = 'current_floor';
  if ($has_area)    $cols[] = 'current_area_code';

  $sql = 'SELECT ' . implode(', ', $cols) . ' FROM users WHERE id = ? LIMIT 1';
  $st  = $pdo->prepare($sql);
  $st->execute([(int)$_SESSION['user_id']]);
  if ($row = $st->fetch()) {
    $user['username']      = (string)$row['username'];
    $user['display_name']  = isset($row['display_name']) ? (string)$row['display_name'] : null;
    $user['level']         = isset($row['level']) ? (int)$row['level'] : 1;
    $user['current_floor'] = isset($row['current_floor']) ? (int)$row['current_floor'] : null;
    $user['area_code']     = isset($row['current_area_code']) ? (string)$row['current_area_code'] : null;
  }
} catch (Throwable $e) {
  // Soft-fail; page still renders as a cosmetic hub
}

$charName = $user['display_name'] ?: $user['username'];
$floorStr = $user['current_floor'] ? 'Floor ' . (int)$user['current_floor'] : 'No tower progress yet';
$areaStr  = $user['area_code'] ? strtoupper($user['area_code']) : 'Eldralis';

/* ---------- Page ---------- */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Town — Eldvar</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  <style>
    /* Town-only polish (scoped) */
    .district-grid{ display:grid; gap:16px; grid-template-columns: repeat(3, minmax(0,1fr)); }
    @media (max-width: 1100px){ .district-grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width: 760px){ .district-grid{ grid-template-columns: 1fr; } }

    .district{ background: var(--panel); border:1px solid var(--border); border-radius:14px; padding:16px; }
    .district h2{ margin:0 0 8px; }
    .district .muted{ margin: 4px 0 12px; }

    .npc-row{ display:grid; gap:10px; grid-template-columns: repeat(2, minmax(0,1fr)); }
    @media (max-width: 760px){ .npc-row{ grid-template-columns: 1fr; } }

    .npc{
      display:flex; gap:10px; align-items:flex-start;
      border:1px solid var(--border); border-radius:12px; background:var(--panel-2);
      padding:12px;
    }
    .npc .glyph{
      width:32px; height:32px; border-radius:8px;
      display:inline-flex; align-items:center; justify-content:center;
      background: var(--panel); border:1px solid var(--border);
      font-size:18px;
    }
    .npc .title{ font-weight:700; }
    .npc .actions{ margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; }

    .town-hero{
      background: linear-gradient(180deg, #151822, #11131a);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 20px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.35), 0 0 0 2px rgba(119,221,119,0.07) inset;
      margin-bottom: 16px;
    }
    .stat-chips{ display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
    .chip{
      border:1px solid var(--border); border-radius:999px; padding:6px 10px;
      background: var(--panel-2); color: var(--muted);
    }

    .panel-link{ text-decoration:none; color: var(--text); }
    .panel-link:hover{ filter:brightness(1.05); }
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="app-main">

      <!-- Town banner -->
      <section class="town-hero">
        <h1 class="pixel-title" style="margin:0;">Eldralis Town Square</h1>
        <p class="lead" style="margin:.4rem 0 0;">
          Welcome, <?= h($charName) ?>. Market stalls creak open; lanterns flicker under the Elder Tree.
        </p>
        <div class="stat-chips">
          <span class="chip">Level <?= (int)$user['level'] ?></span>
          <span class="chip">Area: <?= h($areaStr) ?></span>
          <span class="chip"><?= h($floorStr) ?></span>
          <a class="chip panel-link" href="<?= BASE_URL ?>/battle.php">To the Battlefields →</a>
        </div>
      </section>

      <section class="district-grid">

        <!-- PLAZA -->
        <article class="district">
          <h2 class="card-title">Plaza & Noticeboard</h2>
          <p class="muted">Latest happenings, rumors, and tasks pinned for travelers.</p>
          <div class="npc-row">
            <div class="npc">
              <div class="glyph">📜</div>
              <div>
                <div class="title">Town Noticeboard</div>
                <div class="muted">Patch notes, server news, and community bounties.</div>
                <div class="actions">
                  <a class="btn" href="<?= BASE_URL ?>/news.php">Read News</a>
                  <a class="btn" href="<?= BASE_URL ?>/quests.php">Bounties</a>
                </div>
              </div>
            </div>
            <div class="npc">
              <div class="glyph">💬</div>
              <div>
                <div class="title">Rumor Mill</div>
                <div class="muted">Chat with other adventurers near the fountain.</div>
                <div class="actions">
                  <a class="btn" href="<?= BASE_URL ?>/chatroom.php">Open Chat</a>
                </div>
              </div>
            </div>
          </div>
        </article>

        <!-- SERVICES ROW -->
        <article class="district">
          <h2 class="card-title">Town Services</h2>
          <p class="muted">Stock up, rest, and prepare for the climb.</p>
          <div class="npc-row">
            <div class="npc">
              <div class="glyph">🛒</div>
              <div>
                <div class="title">General Merchant</div>
                <div class="muted">Basic supplies and odd trinkets.</div>
                <div class="actions">
                  <a class="btn" href="<?= BASE_URL ?>/shop.php">Browse Wares</a>
                </div>
              </div>
            </div>
            <div class="npc">
              <div class="glyph">🛏️</div>
              <div>
                <div class="title">The Elder’s Rest (Inn)</div>
                <div class="muted">A warm meal, a soft bed, and a story or two.</div>
                <div class="actions">
                  <!-- Hook up to healing / restore later -->
                  <a class="btn" href="<?= BASE_URL ?>/inn.php">Stay the Night</a>
                </div>
              </div>
            </div>
            <div class="npc">
              <div class="glyph">⚒️</div>
              <div>
                <div class="title">Blacksmith</div>
                <div class="muted">Repairs, simple gear, and sharpening.</div>
                <div class="actions">
                  <a class="btn" href="<?= BASE_URL ?>/smithy.php">Enter Forge</a>
                </div>
              </div>
            </div>
            <div class="npc">
              <div class="glyph">🏦</div>
              <div>
                <div class="title">Bank of Eldralis</div>
                <div class="muted">Keep your valuables under dragon-grade wards.</div>
                <div class="actions">
                  <a class="btn" href="<?= BASE_URL ?>/bank.php">Visit Bank</a>
                </div>
              </div>
            </div>
          </div>
        </article>

        <!-- CRAFTING / TRAINING -->
        <article class="district">
          <h2 class="card-title">Crafting Row & Training Grounds</h2>
          <p class="muted">Hone your craft and harden your resolve.</p>
          <div class="npc-row">
            <div class="npc">
              <div class="glyph">🧪</div>
              <div>
                <div class="title">Alchemist</div>
                <div class="muted">Tonics, reagents, and curious fumes.</div>
                <div class="actions">
                  <a class="btn" href="<?= BASE_URL ?>/alchemy.php">Brew Potions</a>
                </div>
              </div>
            </div>
            <div class="npc">
              <div class="glyph">🏹</div>
              <div>
                <div class="title">Training Grounds</div>
                <div class="muted">Drills for melee, ranged, and magical forms.</div>
                <div class="actions">
                  <a class="btn" href="<?= BASE_URL ?>/training.php">Begin Drills</a>
                </div>
              </div>
            </div>
          </div>
        </article>

        <!-- GUILD / SOCIAL -->
        <article class="district">
          <h2 class="card-title">Guild Hall</h2>
          <p class="muted">Form parties, share knowledge, and claim halls.</p>
          <div class="npc-row">
            <div class="npc">
              <div class="glyph">🏰</div>
              <div>
                <div class="title">Reception</div>
                <div class="muted">Register your guild or join an existing one.</div>
                <div class="actions">
                  <a class="btn" href="<?= BASE_URL ?>/guilds.php">Enter Hall</a>
                </div>
              </div>
            </div>
            <div class="npc">
              <div class="glyph">📯</div>
              <div>
                <div class="title">Bulletin</div>
                <div class="muted">Group requests and expedition postings.</div>
                <div class="actions">
                  <a class="btn" href="<?= BASE_URL ?>/lfg.php">Find Group</a>
                </div>
              </div>
            </div>
          </div>
        </article>

        <!-- TRAVEL -->
        <article class="district">
          <h2 class="card-title">Travel & World Map</h2>
          <p class="muted">Set out to distant lands and seek taller towers.</p>
          <div class="npc-row">
            <div class="npc">
              <div class="glyph">🗺️</div>
              <div>
                <div class="title">World Map</div>
                <div class="muted">Mystic Harshlands, Yulon Forest, Reichal, Undar, Frostbound Tundra…</div>
                <div class="actions">
                  <a class="btn primary" href="<?= BASE_URL ?>/maps.php">Open Map</a>
                </div>
              </div>
            </div>
            <div class="npc">
              <div class="glyph">🗼</div>
              <div>
                <div class="title">Nearest Tower</div>
                <div class="muted">Continue your current climb or choose a new challenge.</div>
                <div class="actions">
                  <a class="btn" href="<?= BASE_URL ?>/battle.php">Enter Tower</a>
                </div>
              </div>
            </div>
          </div>
        </article>

        <!-- TAVERN / RUMORS -->
        <article class="district">
          <h2 class="card-title">Tavern: The Whispering Bough</h2>
          <p class="muted">Minstrels hum old marches; adventurers trade tall tales.</p>
          <div class="npc-row">
            <div class="npc">
              <div class="glyph">🎲</div>
              <div>
                <div class="title">Games & Pastimes</div>
                <div class="muted">Friendly wagers & table games (coming soon).</div>
                <div class="actions">
                  <a class="btn" href="<?= BASE_URL ?>/tavern.php">Enter Tavern</a>
                </div>
              </div>
            </div>
            <div class="npc">
              <div class="glyph">🗣️</div>
              <div>
                <div class="title">Local Rumors</div>
                <div class="muted">Hooks for side quests and hidden vendors (soon™).</div>
                <div class="actions">
                  <a class="btn" href="<?= BASE_URL ?>/quests.php">Check Rumors</a>
                </div>
              </div>
            </div>
          </div>
        </article>

      </section>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>

  <!-- Floating chat widget -->
  <?php include __DIR__ . '/includes/chat.php'; ?>
</body>
</html>
