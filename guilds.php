<?php
declare(strict_types=1);

/**
 * guilds.php — Eldvar
 * - Lists guilds with search + pagination
 * - Shows your current guild (if any)
 * - Lets a player create a guild (basic) if they’re guildless
 * - Lets a member leave their guild
 *
 * This file is defensive:
 *   • Detects tables/columns and adapts queries
 *   • Shows readable alerts instead of 500s
 */

require __DIR__ . '/config/config.php';
require __DIR__ . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$user_id = (int)$_SESSION['user_id'];

/* ---------- helpers ---------- */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function intish(mixed $v, int $d=0): int { return is_numeric($v) ? (int)$v : $d; }
function csrf_token(): string {
  if (!empty($_SESSION['guilds_csrf'])) return $_SESSION['guilds_csrf'];
  try { $_SESSION['guilds_csrf'] = bin2hex(random_bytes(32)); }
  catch (Throwable) { $_SESSION['guilds_csrf'] = sha1(uniqid('', true)); }
  return $_SESSION['guilds_csrf'];
}
function csrf_check(?string $t): bool {
  return !empty($t) && !empty($_SESSION['guilds_csrf']) && hash_equals($_SESSION['guilds_csrf'], $t);
}
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

/* ---------- DB ---------- */
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/* ---------- schema detection ---------- */
$have_guilds   = table_exists($pdo,'guilds');
$have_members  = table_exists($pdo,'guild_members');

$g_cols = [
  'id'           => $have_guilds && column_exists($pdo,'guilds','id'),
  'name'         => $have_guilds && column_exists($pdo,'guilds','name'),
  'tag'          => $have_guilds && column_exists($pdo,'guilds','tag'),
  'description'  => $have_guilds && column_exists($pdo,'guilds','description'),
  'owner_id'     => $have_guilds && column_exists($pdo,'guilds','owner_id'),
  'created_at'   => $have_guilds && (column_exists($pdo,'guilds','created_at') || column_exists($pdo,'guilds','founded_at')),
  'is_recruiting'=> $have_guilds && column_exists($pdo,'guilds','is_recruiting'),
  'emblem'       => $have_guilds && column_exists($pdo,'guilds','emblem'),
];

$m_cols = [
  'id'        => $have_members && column_exists($pdo,'guild_members','id'),
  'guild_id'  => $have_members && column_exists($pdo,'guild_members','guild_id'),
  'user_id'   => $have_members && column_exists($pdo,'guild_members','user_id'),
  'role'      => $have_members && column_exists($pdo,'guild_members','role'),
  'joined_at' => $have_members && column_exists($pdo,'guild_members','joined_at'),
];

/* ---------- soft requirements ---------- */
$errors = [];
$notices = [];

/* Explain missing schema (but don’t 500) */
if (!$have_guilds || !$have_members) {
  $miss = [];
  if (!$have_guilds)  $miss[] = 'guilds';
  if (!$have_members) $miss[] = 'guild_members';
  $errors[] = 'Missing tables: ' . implode(', ', $miss) . '.';
  $errors[] = 'Quick minimal schema:
<pre style="white-space:pre-wrap">
CREATE TABLE guilds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  tag VARCHAR(10) NOT NULL,
  description TEXT NULL,
  owner_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_recruiting TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE guild_members (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  guild_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT \'member\',
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_member (guild_id,user_id),
  INDEX (guild_id),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
</pre>';
}

/* ---------- define cheap selectors safely ---------- */

/** Current membership row for user (or null) */
function my_membership(PDO $pdo, int $user_id, array $m_cols): ?array {
  if (!$m_cols['user_id'] || !$m_cols['guild_id']) return null;
  try {
    $st = $pdo->prepare("SELECT * FROM guild_members WHERE user_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$user_id]);
    return $st->fetch() ?: null;
  } catch (Throwable $e) { return null; }
}

/** Load a guild by id with safe column list */
function load_guild(PDO $pdo, int $gid, array $g_cols): ?array {
  if (!$g_cols['id']) return null;
  $cols = [];
  foreach (['id','name','tag','description','owner_id','is_recruiting'] as $c) {
    if (!empty($g_cols[$c])) $cols[] = $c;
  }
  // prefer created_at, else founded_at, else NULL
  if ($g_cols['created_at']) {
    $cols[] = column_exists($pdo,'guilds','created_at') ? 'created_at' : 'founded_at AS created_at';
  }
  if (!$cols) $cols = ['id'];
  $sql = "SELECT ".implode(',', $cols)." FROM guilds WHERE id=? LIMIT 1";
  try {
    $st = $pdo->prepare($sql);
    $st->execute([$gid]);
    return $st->fetch() ?: null;
  } catch (Throwable $e) { return null; }
}

/** Count members for a guild (if members table present) */
function guild_member_count(PDO $pdo, int $gid, array $m_cols): int {
  if (!$m_cols['guild_id']) return 0;
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM guild_members WHERE guild_id=?");
    $st->execute([$gid]);
    return (int)$st->fetchColumn();
  } catch (Throwable $e) { return 0; }
}

/* ---------- membership + actions ---------- */

$my_member = $have_members ? my_membership($pdo, $user_id, $m_cols) : null;
$my_guild  = null;
if ($my_member && $have_guilds) {
  $my_guild = load_guild($pdo, (int)$my_member['guild_id'], $g_cols);
}

/* Handle POST actions (create, leave) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
  $act = (string)($_POST['action'] ?? '');
  try {
    if ($act === 'create' && $have_guilds && $have_members) {
      if ($my_member) {
        $errors[] = 'You are already in a guild.';
      } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $tag  = trim((string)($_POST['tag'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));

        if ($name === '' || $tag === '') {
          $errors[] = 'Name and tag are required.';
        } elseif (!preg_match('/^[A-Za-z0-9 _\-]{3,80}$/', $name)) {
          $errors[] = 'Guild name must be 3–80 chars (letters, numbers, space, _ or -).';
        } elseif (!preg_match('/^[A-Za-z0-9]{2,10}$/', $tag)) {
          $errors[] = 'Tag must be 2–10 alphanumeric characters.';
        } else {
          $pdo->beginTransaction();

          // Build insert for guilds with only existing columns
          $cols = []; $qs = []; $vals = [];
          if ($g_cols['name'])         { $cols[]='name';         $qs[]='?'; $vals[]=$name; }
          if ($g_cols['tag'])          { $cols[]='tag';          $qs[]='?'; $vals[]=$tag; }
          if ($g_cols['description'])  { $cols[]='description';  $qs[]='?'; $vals[]=$desc; }
          if ($g_cols['owner_id'])     { $cols[]='owner_id';     $qs[]='?'; $vals[]=$user_id; }
          if ($g_cols['is_recruiting']){ $cols[]='is_recruiting';$qs[]='?'; $vals[]=1; }

          if (!$cols) { throw new RuntimeException('Guilds table missing required columns.'); }

          $sql = "INSERT INTO guilds (".implode(',',$cols).") VALUES (".implode(',',$qs).")";
          $pdo->prepare($sql)->execute($vals);
          $gid = (int)$pdo->lastInsertId();

          // Insert membership
          if (!$m_cols['guild_id'] || !$m_cols['user_id']) {
            throw new RuntimeException('Members table missing required columns.');
          }
          $mcols = ['guild_id','user_id'];
          $mqs   = ['?','?'];
          $mvals = [$gid, $user_id];
          if ($m_cols['role']) { $mcols[]='role'; $mqs[]='?'; $mvals[]='leader'; }
          $pdo->prepare("INSERT INTO guild_members (".implode(',',$mcols).") VALUES (".implode(',',$mqs).")")->execute($mvals);

          $pdo->commit();
          $notices[] = 'Guild created!';
          $my_member = ['guild_id'=>$gid, 'user_id'=>$user_id, 'role'=>($m_cols['role']?'leader':'member')];
          $my_guild  = load_guild($pdo, $gid, $g_cols);
        }
      }
    }
    elseif ($act === 'leave' && $have_members) {
      if (!$my_member) {
        $errors[] = 'You are not in a guild.';
      } else {
        // Simple leave: remove membership row for this user
        $pdo->prepare("DELETE FROM guild_members WHERE user_id=?")->execute([$user_id]);
        $notices[] = 'You left your guild.';
        $my_member = null;
        $my_guild  = null;
      }
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errors[] = 'Action failed: ' . $e->getMessage();
  }
}

/* ---------- search + pagination ---------- */
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, intish($_GET['page'] ?? 1, 1));
$per  = 9; // 3x3 grid on desktop
$offset = ($page - 1) * $per;

$total = 0;
$rows  = [];

if ($have_guilds) {
  try {
    // Build WHERE (if searching)
    $wheres = []; $params = [];
    if ($q !== '') {
      $like = '%'.$q.'%';
      if ($g_cols['name']) $wheres[] = "name LIKE ?";
      if ($g_cols['tag'])  $wheres[] = "tag LIKE ?";
      if ($g_cols['description']) $wheres[] = "description LIKE ?";
      if ($wheres) {
        // repeat param for each where
        $params = array_fill(0, count($wheres), $like);
        $whereSql = 'WHERE '.implode(' OR ', $wheres);
      } else {
        $whereSql = '';
      }
    } else {
      $whereSql = '';
    }

    // Count
    $countSql = "SELECT COUNT(*) FROM guilds {$whereSql}";
    $st = $pdo->prepare($countSql);
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    // Select list
    $colList = [];
    foreach (['id','name','tag','description','is_recruiting'] as $c) {
      if (!empty($g_cols[$c])) $colList[] = $c;
    }
    // Add created_at (or founded_at AS created_at) if present
    if ($g_cols['created_at']) {
      $colList[] = column_exists($pdo,'guilds','created_at') ? 'created_at' : 'founded_at AS created_at';
    }
    if (!$colList) { $colList = ['id']; }

    $listSql = "SELECT ".implode(',', $colList)." FROM guilds {$whereSql} ORDER BY id DESC LIMIT {$per} OFFSET {$offset}";
    $st = $pdo->prepare($listSql);
    $st->execute($params);
    $rows = $st->fetchAll();
  } catch (Throwable $e) {
    $errors[] = 'Load guilds failed: ' . $e->getMessage();
  }
}

/* ---------- small helpers for view ---------- */
function fmt_date(?string $ts): string {
  if (!$ts) return '';
  try { $t = new DateTime($ts); return $t->format('M j, Y'); } catch (Throwable) { return (string)$ts; }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Guilds — Eldvar</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  <style>
    /* Scoped styles for this page (uses your token vars) */
    <?php /* keep this minimal—main look comes from your global CSS */ ?>
    .guilds-page .toolbar{display:grid;gap:14px;grid-template-columns:1fr auto auto;align-items:center;margin:14px 0}
    @media(max-width:880px){.guilds-page .toolbar{grid-template-columns:1fr}}
    .guilds-page .search{display:flex;gap:8px;align-items:center;background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:10px 12px}
    .guilds-page .search input{all:unset;color:var(--text);flex:1}
    .guilds-page .grid{display:grid;gap:14px;grid-template-columns:repeat(3,minmax(0,1fr))}
    @media(max-width:1100px){.guilds-page .grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:720px){.guilds-page .grid{grid-template-columns:1fr}}
    .guilds-page .guild-card{background:var(--panel);border:1px solid var(--border);border-radius:14px;overflow:hidden}
    .guilds-page .guild-head{display:flex;gap:12px;align-items:center;padding:12px 14px;border-bottom:1px solid var(--border);background:linear-gradient(180deg,rgba(20,24,34,.55),rgba(20,24,34,.15))}
    .guilds-page .guild-emblem{width:40px;height:40px;border-radius:8px;background:var(--panel-2);border:1px solid var(--border);display:grid;place-items:center;font-weight:800;color:var(--accent-2)}
    .guilds-page .guild-title{margin:0;font-size:1.05rem;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .guilds-page .tag{display:inline-block;font-size:12px;line-height:1;padding:4px 8px;border-radius:999px;border:1px solid var(--border);background:#0f1726;color:var(--muted)}
    .guilds-page .guild-body{padding:12px 14px}
    .guilds-page .desc{color:var(--muted);margin:6px 0 8px}
    .guilds-page .meta{display:flex;flex-wrap:wrap;gap:8px;color:var(--muted);font-size:12px}
    .guilds-page .badge{display:inline-flex;gap:6px;align-items:center;padding:4px 10px;border-radius:999px;border:1px solid var(--border);background:var(--panel-2)}
    .guilds-page .guild-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 14px;border-top:1px solid var(--border);background:linear-gradient(180deg,rgba(16,19,28,.25),rgba(16,19,28,.1))}
    .guilds-page .pager{display:flex;justify-content:center;gap:10px;margin-top:14px}
    .guilds-page .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:var(--panel-2);color:var(--text);text-decoration:none;cursor:pointer}
    .guilds-page .btn.primary{border-color:rgba(119,221,119,.38);background:rgba(119,221,119,.12);box-shadow:0 0 14px rgba(119,221,119,.22)}
    .guilds-page .alert{padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:var(--panel-2)}
    .guilds-page .alert.error{border-color:#6d2d2d;background:rgba(255,60,60,.08)}
    .guilds-page .hero-card{background:linear-gradient(180deg,#151822,#11131a);border:1px solid var(--border);border-radius:14px;padding:18px;box-shadow:0 8px 24px rgba(0,0,0,.38),0 0 0 2px rgba(160,224,255,.06) inset}
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="app-main guilds-page">
      <!-- Hero -->
      <section class="hero-card">
        <h1 class="pixel-title hero-title">Guilds of Eldvar</h1>
        <p class="hero-sub">Join a banner or found a new order. Work together to push back the void.</p>
      </section>

      <!-- Notices / Errors -->
      <?php if ($errors): ?>
        <div class="alert error" style="margin:12px 0;">
          <?php foreach ($errors as $e) echo '<div>'. $e .'</div>'; ?>
        </div>
      <?php endif; ?>
      <?php if ($notices): ?>
        <div class="alert" style="margin:12px 0; border-color:rgba(119,221,119,.35); background:rgba(119,221,119,.08);">
          <?php foreach ($notices as $n) echo '<div>'. h($n) .'</div>'; ?>
        </div>
      <?php endif; ?>

      <!-- Your Guild (if any) -->
      <?php if ($my_guild): ?>
        <section class="card" style="margin:12px 0;">
          <h2 class="card-title">Your Guild</h2>
          <p class="muted">You are a <?= isset($my_member['role']) ? h((string)$my_member['role']) : 'member' ?> of <strong><?= h($my_guild['name'] ?? ('Guild #'.(string)$my_guild['id'])) ?></strong>
            <?php if (!empty($my_guild['tag'])): ?> <span class="tag"><?= h((string)$my_guild['tag']) ?></span><?php endif; ?>
          </p>
          <p class="muted" style="margin:6px 0;">
            Members: <?= guild_member_count($pdo, (int)$my_guild['id'], $m_cols) ?>
            <?php if (!empty($my_guild['created_at'])): ?>
              · Founded <?= h(fmt_date((string)$my_guild['created_at'])) ?>
            <?php endif; ?>
            <?php if (isset($my_guild['is_recruiting'])): ?>
              · <?= ((int)$my_guild['is_recruiting'] ? 'Recruiting' : 'Closed') ?>
            <?php endif; ?>
          </p>
          <?php if (!empty($my_guild['description'])): ?>
            <p class="muted"><?= nl2br(h((string)$my_guild['description'])) ?></p>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('Leave your guild?');" style="margin-top:8px;">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <button class="btn" name="action" value="leave" type="submit">Leave Guild</button>
          </form>
        </section>
      <?php endif; ?>

      <!-- Create Guild (only if guildless and schema supports) -->
      <?php if (!$my_guild && $have_guilds && $have_members && $g_cols['name'] && $g_cols['tag']): ?>
        <section class="card" style="margin:12px 0;">
          <h2 class="card-title">Found a Guild</h2>
          <form method="post" class="form-grid" style="margin-top:8px;">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <div class="field">
              <input type="text" name="name" maxlength="80" placeholder="Guild Name (3–80)" required>
            </div>
            <div class="field">
              <input type="text" name="tag" maxlength="10" placeholder="Tag (2–10 letters/numbers)" required>
            </div>
            <div class="field">
              <textarea name="description" rows="3" placeholder="Short description (optional)" style="width:100%; background:transparent; border:0; color:var(--text); resize:vertical;"></textarea>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
              <button class="btn primary" type="submit" name="action" value="create">Create Guild</button>
            </div>
          </form>
        </section>
      <?php endif; ?>

      <!-- Toolbar: search + actions -->
      <section class="toolbar">
        <form class="search" method="get" action="guilds.php">
          <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search guilds by name or tag…">
          <button class="btn" type="submit">Search</button>
        </form>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <a class="btn" href="guilds.php">All</a>
          <?php if ($q !== ''): ?>
            <span class="btn" style="pointer-events:none; opacity:.8">Filtering</span>
          <?php endif; ?>
        </div>
        <?php if (!$my_guild && $have_guilds && $have_members && $g_cols['name'] && $g_cols['tag']): ?>
          <a class="btn primary" href="#found">Found Guild</a>
        <?php endif; ?>
      </section>

      <!-- Guilds grid -->
      <section class="grid">
        <?php if (!$have_guilds): ?>
          <div class="alert error">Guilds table not found.</div>
        <?php elseif (!$rows): ?>
          <div class="alert">No guilds found<?= $q!=='' ? ' for “'.h($q).'”' : '' ?>.</div>
        <?php else: ?>
          <?php foreach ($rows as $g): ?>
            <?php
              $gid   = (int)($g['id'] ?? 0);
              $gname = (string)($g['name'] ?? ('Guild #'.$gid));
              $gtag  = (string)($g['tag']  ?? '');
              $gdesc = (string)($g['description'] ?? '');
              $grec  = isset($g['is_recruiting']) ? (int)$g['is_recruiting'] : 1;
              $gdate = isset($g['created_at']) ? (string)$g['created_at'] : null;
              $mcnt  = guild_member_count($pdo, $gid, $m_cols);
            ?>
            <article class="guild-card">
              <header class="guild-head">
                <div class="guild-emblem"><?= h(strtoupper(substr($gtag !== '' ? $gtag : $gname, 0, 2))) ?></div>
                <h3 class="guild-title"><?= h($gname) ?> <?php if ($gtag!==''): ?><span class="tag"><?= h($gtag) ?></span><?php endif; ?></h3>
              </header>
              <div class="guild-body">
                <?php if ($gdesc!==''): ?>
                  <p class="desc"><?= nl2br(h($gdesc)) ?></p>
                <?php else: ?>
                  <p class="desc" style="opacity:.8;">No description yet.</p>
                <?php endif; ?>
                <div class="meta">
                  <span class="badge">Members: <?= (int)$mcnt ?></span>
                  <?php if ($gdate): ?><span class="badge">Founded: <?= h(fmt_date($gdate)) ?></span><?php endif; ?>
                  <span class="badge"><?= $grec ? 'Recruiting' : 'Closed' ?></span>
                </div>
              </div>
              <footer class="guild-foot">
                <div class="foot-hint"><?= $grec ? 'Open to applications' : 'Not recruiting' ?></div>
                <div class="foot-actions">
                  <!-- Placeholder; you may add a view/apply flow later -->
                  <?php if (!$my_guild && $grec): ?>
                    <a class="btn primary" href="#" onclick="alert('Application flow coming soon.'); return false;">Apply</a>
                  <?php else: ?>
                    <a class="btn" href="guild.php?id=<?= (int)$gid ?>">View</a>
                  <?php endif; ?>
                </div>
              </footer>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>

      <!-- Pagination -->
      <?php
        $pages = ($per > 0) ? (int)ceil($total / $per) : 1;
        if ($pages < 1) $pages = 1;
      ?>
      <?php if ($pages > 1): ?>
        <nav class="pager">
          <?php
            $base = 'guilds.php';
            $qs   = $q!=='' ? '&q='.urlencode($q) : '';
            $prev = max(1, $page-1);
            $next = min($pages, $page+1);
          ?>
          <a class="btn" href="<?= $base.'?page='.$prev.$qs ?>">Previous</a>
          <span class="btn" style="pointer-events:none;">Page <?= (int)$page ?> / <?= (int)$pages ?></span>
          <a class="btn primary" href="<?= $base.'?page='.$next.$qs ?>">Next</a>
        </nav>
      <?php endif; ?>

      <!-- Anchor target for "Found Guild" quick link -->
      <div id="found"></div>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>

  <!-- Floating chat widget -->
  <?php include __DIR__ . '/includes/chat.php'; ?>
</body>
</html>
