<?php
declare(strict_types=1);
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/**
 * /character.php            -> current user (requires login)
 * /character.php?id=123     -> public profile for user 123
 */
$pdo = get_pdo();

$viewerId  = $_SESSION['user_id'] ?? null;
$profileId = isset($_GET['id']) ? (int)$_GET['id'] : ($viewerId ?? 0);

if ($profileId <= 0) {
  header('Location: login.php');
  exit;
}

/* ---------------- Helpers ---------------- */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function ago(?string $ts): string {
  if (!$ts) return '';
  $t = is_numeric($ts) ? (int)$ts : strtotime($ts);
  $diff = max(1, time()-$t);
  foreach ([31536000=>'yr',2592000=>'mo',604800=>'wk',86400=>'d',3600=>'h',60=>'m'] as $s=>$lbl) {
    if ($diff >= $s) return floor($diff/$s) . $lbl;
  }
  return $diff . 's';
}
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

/* ---------------- Load profile ---------------- */
$has_overall_xp = column_exists($pdo, 'users', 'overall_xp');

$select = "
  SELECT
    id,
    username,
    COALESCE(display_name, username) AS display_name,
    COALESCE(bio, '')                AS bio,
    COALESCE(avatar_url, '')         AS avatar_url,
    COALESCE(banner_url, '')         AS banner_url,
    COALESCE(level, 1)               AS level,
    COALESCE(verified, 0)            AS verified,
    COALESCE(status_text, '')        AS status_text,
    created_at
";
if ($has_overall_xp) {
  $select .= ", COALESCE(overall_xp, 0) AS overall_xp";
}
$select .= " FROM users WHERE id = ? LIMIT 1";

$stmt = $pdo->prepare($select);
$stmt->execute([$profileId]);
$profile = $stmt->fetch();

if (!$profile) {
  http_response_code(404);
  ?>
  <!doctype html>
  <html lang="en">
  <head>
    <meta charset="utf-8">
    <title>Character not found — Eldvar</title>
    <link rel="stylesheet" href="public/css/style.css">
  </head>
  <body class="with-sidebar">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div id="backdrop" class="backdrop"></div>
    <div class="app-shell">
      <?php include __DIR__ . '/includes/header.php'; ?>
      <main class="app-main">
        <section class="card">
          <h2 class="card-title">Character not found</h2>
          <p class="muted">The requested character does not exist or is private.</p>
        </section>
      </main>
      <?php include __DIR__ . '/includes/footer.php'; ?>
    </div>
  </body>
  </html>
  <?php
  exit;
}

$isOwner = ($viewerId && (int)$viewerId === (int)$profile['id']);

$avatar = ($profile['avatar_url'] ?? '') ?: BASE_URL . '/public/img/avatar-default.png';
$banner = ($profile['banner_url'] ?? '') ?: BASE_URL . '/public/img/banner-default.jpg';

/* ---------------- Skills: DB-authoritative ---------------- */
$skills = [];
$xpGridAvailable = table_exists($pdo,'xp_thresholds') && column_exists($pdo,'xp_thresholds','xp_required');

if (table_exists($pdo, 'user_skills') && table_exists($pdo,'skills')) {
  try {
    $sql = "
      SELECT
        s.id AS skill_id, s.skey, s.name,
        us.level, us.xp,
        curr.xp_required AS curr_req,
        nextt.xp_required AS next_req
      FROM skills s
      JOIN user_skills us
        ON us.user_id = ? AND us.skill_id = s.id
      JOIN xp_thresholds curr
        ON curr.level = us.level
      LEFT JOIN xp_thresholds nextt
        ON nextt.level = LEAST(us.level + 1, 99)
      ORDER BY s.id
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$profileId]);
    $skills = $st->fetchAll();
  } catch (Throwable $e) {
    // if xp_thresholds not there yet, fallback without pct
    try {
      $st = $pdo->prepare("
        SELECT s.id AS skill_id, s.skey, s.name, us.level, us.xp, 0 AS curr_req, 0 AS next_req
        FROM skills s
        JOIN user_skills us ON us.user_id=? AND us.skill_id=s.id
        ORDER BY s.id
      ");
      $st->execute([$profileId]);
      $skills = $st->fetchAll();
    } catch (Throwable) {}
  }
}

/* Overall XP/level progress (uses users.level for display) */
$overallLevel = max(1, (int)($profile['level'] ?? 1));
$overallXp    = $has_overall_xp ? max(0, (int)($profile['overall_xp'] ?? 0)) : 0;
$ovCur = 0; $ovNext = 0; $overallPct = 0;
if ($xpGridAvailable) {
  try {
    $c = $pdo->prepare("SELECT xp_required FROM xp_thresholds WHERE level=?");
    $n = $pdo->prepare("SELECT xp_required FROM xp_thresholds WHERE level=?");
    $c->execute([$overallLevel]);
    $n->execute([min(99, $overallLevel+1)]);
    $ovCur = (int)($c->fetchColumn() ?: 0);
    $ovNext = (int)($n->fetchColumn() ?: $ovCur);
    $overallPct  = ($ovNext > $ovCur) ? (int)floor(($overallXp - $ovCur) / max(1, $ovNext - $ovCur) * 100) : 100;
    $overallPct  = max(0, min(100, $overallPct));
  } catch (Throwable) {}
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($profile['display_name']) ?> — Character</title>
  <link rel="stylesheet" href="public/css/style.css">
  <style>
    .crumbs{display:flex;align-items:center;gap:8px;margin-bottom:10px;color:var(--muted)}
    .crumbs a{color:var(--muted);text-decoration:none;border:1px solid var(--border);padding:6px 10px;border-radius:8px;background:var(--panel-2)}
    .crumbs a:hover{color:var(--text)}
    .character-main{padding-top:0}
    .char-hero{position:relative;margin:-8px -8px 16px}
    .char-banner{display:block;width:100%;height:300px;object-fit:cover;border-radius:12px;border:1px solid var(--border);filter:saturate(.96)}
    .char-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none}
    .char-card{pointer-events:auto;display:grid;grid-template-columns:auto 1fr;gap:18px;align-items:center;background:linear-gradient(180deg,rgba(18,20,26,.92),rgba(18,20,26,.88));border:1px solid var(--border);border-radius:16px;padding:18px 20px;box-shadow:0 10px 40px rgba(0,0,0,.45);max-width:780px;width:92%}
    .char-avatar-wrap{position:relative}
    .char-avatar{width:92px;height:92px;border-radius:50%;border:2px solid var(--border);background:var(--panel);object-fit:cover}
    .char-online-dot{position:absolute;right:6px;bottom:6px;width:14px;height:14px;border-radius:50%;background:#6be989;border:2px solid #0f1116}
    .char-meta{display:flex;flex-direction:column;gap:6px}
    .char-name-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .char-name{margin:0;font-size:1.8rem}
    .char-verified{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:rgba(160,224,255,.15);color:var(--accent-2);border:1px solid var(--border);font-size:13px}
    .level-pill{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);background:var(--panel-2);padding:6px 10px;border-radius:999px;font-weight:700}
    .level-dot{display:inline-block;width:10px;height:10px;border-radius:50%;background:rgba(119,221,119,.8)}
    .char-status{font-style:italic;color:var(--muted)}
    .char-actions{display:flex;gap:8px;margin-top:6px;flex-wrap:wrap}
    .meta-pills{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
    .meta-pills .pill{border:1px solid var(--border);background:var(--panel-2);padding:6px 10px;border-radius:999px;color:var(--muted);font-size:.9rem}
    .char-body.grid{grid-template-columns:2fr 1fr 1fr}
    .muted small{opacity:.9}
    @media (max-width:1100px){ .char-body.grid{grid-template-columns:1fr 1fr} }
    @media (max-width:820px){
      .char-banner{height:220px}
      .char-card{grid-template-columns:1fr;text-align:center}
      .char-avatar{margin:0 auto}
      .char-actions{justify-content:center}
      .char-body.grid{grid-template-columns:1fr}
    }
    .bar { height: 10px; background: #202a38; border-radius: 999px; overflow: hidden; border: 1px solid rgba(255,255,255,.08); }
    .bar > div { height: 100%; background: linear-gradient(90deg, #6aa8ff, #a6c8ff); transition: width .35s ease; }
    .skill-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; }
    @media (max-width:1100px){ .skill-grid{grid-template-columns:repeat(2,minmax(0,1fr));} }
    @media (max-width:700px){ .skill-grid{grid-template-columns:1fr;} }
    .skill-card{ padding:12px; border-radius:12px; border:1px solid var(--border); background:var(--panel-2); }
    .skill-card .head{ display:flex; justify-content:space-between; align-items:center; gap:8px; }
    .skill-card .name{ font-weight:700; }
    .skill-card .lvl{ padding:2px 8px; border-radius:999px; border:1px solid var(--border); background:rgba(255,255,255,.06); font-size:.85rem; line-height:1.6; }
    .skill-meta{ margin-top:4px; color:var(--muted); font-size:.9rem; display:flex; align-items:baseline; gap:8px; white-space:nowrap; }
    .skill-meta .sep{ opacity:.5; }
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="app-main character-main">

      <div class="crumbs">
        <a href="dashboard.php">← Go Back</a>
        <span class="muted">/ Character</span>
      </div>

      <section class="char-hero">
        <img class="char-banner" src="<?= h($banner) ?>" alt="Profile banner">
        <div class="char-overlay">
          <div class="char-card">
            <div class="char-avatar-wrap">
              <img class="char-avatar" src="<?= h($avatar) ?>" alt="Avatar">
              <span class="char-online-dot" title="Online"></span>
            </div>

            <div class="char-meta">
              <div class="char-name-row">
                <h1 class="char-name"><?= h($profile['display_name']) ?></h1>
                <?php if ((int)($profile['verified'] ?? 0) === 1): ?>
                  <span class="char-verified" title="Verified">✔</span>
                <?php endif; ?>
                <span class="level-pill"><span class="level-dot"></span> Level <?= (int)($profile['level'] ?? 1) ?></span>
              </div>

              <?php if (!empty($profile['status_text'])): ?>
                <div class="char-status">“<?= h($profile['status_text']) ?>”</div>
              <?php endif; ?>

              <div class="meta-pills">
                <span class="pill">Joined <?= h(date('M j, Y', strtotime($profile['created_at']))) ?></span>
                <span class="pill"><?= h(ago($profile['created_at'])) ?> on Eldvar</span>
                <span class="pill">@<?= h($profile['username']) ?></span>
              </div>

              <?php if ($has_overall_xp && $xpGridAvailable): ?>
                <div class="muted" style="margin-top:6px;">
                  Overall XP: <strong><?= number_format((int)$overallXp) ?></strong>
                  <?php if ($ovNext > $ovCur): ?>
                    <span class="sep">·</span>
                    <small><?= number_format(max(0, $ovNext - $overallXp)) ?> to Level <?= (int)$overallLevel + 1 ?></small>
                  <?php endif; ?>
                  <div class="bar" style="margin-top:6px;"><div style="width:<?= (int)$overallPct ?>%"></div></div>
                </div>
              <?php elseif ($has_overall_xp): ?>
                <div class="muted" style="margin-top:6px;">Overall XP: <strong><?= number_format((int)$overallXp) ?></strong></div>
              <?php endif; ?>

              <div class="char-actions">
                <?php if ($isOwner): ?>
                  <a class="btn primary" href="settings_profile.php">Customise</a>
                  <a class="btn" href="profile_public.php?id=<?= (int)$profile['id'] ?>">View Public Profile</a>
                  <a class="btn" href="character.php">My Character</a>
                <?php else: ?>
                  <a class="btn primary" href="message.php?to=<?= (int)$profile['id'] ?>">Message</a>
                  <a class="btn" href="#">Add Friend</a>
                  <a class="btn" href="character.php?id=<?= (int)$profile['id'] ?>">Refresh</a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Body -->
      <section class="char-body grid">
        <article class="card">
          <h2 class="card-title">About</h2>
          <?php if (!empty($profile['bio'])): ?>
            <p><?= nl2br(h($profile['bio'])) ?></p>
          <?php else: ?>
            <p class="muted">
              <?= $isOwner
                ? 'Tell the world about your character. Add a short bio in Customise.'
                : 'This adventurer has not shared a bio yet.' ?>
            </p>
          <?php endif; ?>
        </article>

        <article class="card">
          <h2 class="card-title">Basics</h2>
          <ul class="char-list">
            <li><span>Username</span><strong><?= h($profile['username']) ?></strong></li>
            <li><span>Joined</span><strong><?= h(date('M j, Y', strtotime($profile['created_at']))) ?></strong></li>
            <li><span>Member for</span><strong><?= h(ago($profile['created_at'])) ?></strong></li>
          </ul>
        </article>

        <article class="card">
          <h2 class="card-title">Highlights</h2>
          <p class="muted">We’ll surface key stats, titles, or recent achievements here.</p>
          <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:8px">
            <div class="card" style="padding:12px"><div class="muted">Title</div><div><strong>Novice Adventurer</strong></div></div>
            <div class="card" style="padding:12px"><div class="muted">Reputation</div><div><strong>Neutral</strong></div></div>
          </div>
        </article>
      </section>

      <!-- Skills -->
      <?php if ($skills): ?>
      <section class="card" style="margin-top:12px;">
        <h2 class="card-title">Skills</h2>
        <p class="muted">Train skills to increase your combat power and unlock new activities.</p>
        <div class="skill-grid" style="margin-top:8px;">
          <?php foreach ($skills as $s):
            $lvl = (int)$s['level'];
            $xp  = (int)$s['xp'];
            $curr = (int)$s['curr_req'];
            $next = (int)$s['next_req'];
            $pct = ($next > $curr) ? (int)floor( ($xp - $curr) / max(1, $next - $curr) * 100 ) : 100;
            $pct = max(0, min(100, $pct));
          ?>
            <div class="skill-card">
              <div class="head">
                <span class="name"><?= h($s['name']) ?></span>
                <span class="lvl">Lv <?= $lvl ?></span>
              </div>
              <div class="bar" style="margin-top:6px;">
                <div style="width:<?= (int)$pct ?>%"></div>
              </div>
              <div class="skill-meta">
                <span><?= number_format($xp) ?> xp</span>
                <?php if ($next > $curr): ?>
                  <span class="sep">·</span>
                  <span><?= number_format(max(0, $next - $xp)) ?> to <?= $lvl + 1 ?></span>
                <?php else: ?>
                  <span class="sep">·</span>
                  <span>Maxed</span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>

  <?php include __DIR__ . '/includes/chat.php'; ?>
</body>
</html>
