<?php
declare(strict_types=1);

/**
 * guild.php — View a single guild
 * - If ?id=123 is supplied, shows that guild (if it exists)
 * - If no id is given, shows the viewer's own guild (if any)
 * - Shows emblem, name/tag, description, founded date, recruiting flag, member list
 * - Allows member to leave guild (safe, CSRF-protected)
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
  if (!empty($_SESSION['guild_view_csrf'])) return $_SESSION['guild_view_csrf'];
  try { $_SESSION['guild_view_csrf'] = bin2hex(random_bytes(32)); }
  catch (Throwable) { $_SESSION['guild_view_csrf'] = sha1(uniqid('', true)); }
  return $_SESSION['guild_view_csrf'];
}
function csrf_check(?string $t): bool {
  return !empty($t) && !empty($_SESSION['guild_view_csrf']) && hash_equals($_SESSION['guild_view_csrf'], $t);
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
function fmt_date(?string $ts): string {
  if (!$ts) return '';
  try { $d = new DateTime($ts); return $d->format('M j, Y'); } catch (Throwable) { return (string)$ts; }
}

/* ---------- DB ---------- */
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/* ---------- schema detection ---------- */
$have_guilds   = table_exists($pdo,'guilds');
$have_members  = table_exists($pdo,'guild_members');
$have_users    = table_exists($pdo,'users');

$g_cols = [
  'id'           => $have_guilds && column_exists($pdo,'guilds','id'),
  'name'         => $have_guilds && column_exists($pdo,'guilds','name'),
  'tag'          => $have_guilds && column_exists($pdo,'guilds','tag'),
  'description'  => $have_guilds && column_exists($pdo,'guilds','description'),
  'owner_id'     => $have_guilds && column_exists($pdo,'guilds','owner_id'),
  'created_at'   => $have_guilds && (column_exists($pdo,'guilds','created_at') || column_exists($pdo,'guilds','founded_at')),
  'is_recruiting'=> $have_guilds && column_exists($pdo,'guilds','is_recruiting'),
];

$m_cols = [
  'id'        => $have_members && column_exists($pdo,'guild_members','id'),
  'guild_id'  => $have_members && column_exists($pdo,'guild_members','guild_id'),
  'user_id'   => $have_members && column_exists($pdo,'guild_members','user_id'),
  'role'      => $have_members && column_exists($pdo,'guild_members','role'),
  'joined_at' => $have_members && column_exists($pdo,'guild_members','joined_at'),
];

$u_cols = [
  'id'         => $have_users && column_exists($pdo,'users','id'),
  'username'   => $have_users && column_exists($pdo,'users','username'),
  'display_name'=> $have_users && column_exists($pdo,'users','display_name'),
  'level'      => $have_users && column_exists($pdo,'users','level'),
];

/* ---------- error/notice buffers ---------- */
$errors = [];
$notices = [];

/* ---------- helpers (queries) ---------- */
function my_membership(PDO $pdo, int $user_id, array $m_cols): ?array {
  if (!$m_cols['user_id'] || !$m_cols['guild_id']) return null;
  try {
    $st = $pdo->prepare("SELECT * FROM guild_members WHERE user_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$user_id]);
    return $st->fetch() ?: null;
  } catch (Throwable) { return null; }
}

function load_guild(PDO $pdo, int $gid, array $g_cols): ?array {
  if (!$g_cols['id']) return null;
  $cols = [];
  foreach (['id','name','tag','description','owner_id','is_recruiting'] as $c) {
    if (!empty($g_cols[$c])) $cols[] = $c;
  }
  if ($g_cols['created_at']) {
    $cols[] = column_exists($pdo,'guilds','created_at') ? 'created_at' : 'founded_at AS created_at';
  }
  if (!$cols) $cols = ['id'];
  $sql = "SELECT ".implode(',', $cols)." FROM guilds WHERE id=? LIMIT 1";
  try {
    $st = $pdo->prepare($sql);
    $st->execute([$gid]);
    return $st->fetch() ?: null;
  } catch (Throwable) { return null; }
}

function load_guild_members(PDO $pdo, int $gid, array $m_cols, array $u_cols): array {
  if (!$m_cols['guild_id'] || !$m_cols['user_id']) return [];
  try {
    if ($u_cols['id'] && ($u_cols['username'] || $u_cols['display_name'])) {
      $nameExpr = $u_cols['display_name'] ? 'COALESCE(u.display_name,u.username)' : 'u.username';
      $joined   = $m_cols['joined_at'] ? ', m.joined_at' : '';
      $role     = $m_cols['role'] ? ', m.role' : '';
      $sql = "SELECT m.user_id, {$nameExpr} AS name {$role} {$joined}
              FROM guild_members m
              JOIN users u ON u.id = m.user_id
              WHERE m.guild_id = ?
              ORDER BY m.id ASC";
      $st = $pdo->prepare($sql);
      $st->execute([$gid]);
      return $st->fetchAll();
    } else {
      // No users table/columns; return bare IDs/role
      $cols = "user_id";
      if ($m_cols['role']) $cols .= ", role";
      if ($m_cols['joined_at']) $cols .= ", joined_at";
      $sql = "SELECT {$cols} FROM guild_members WHERE guild_id=? ORDER BY id ASC";
      $st = $pdo->prepare($sql);
      $st->execute([$gid]);
      $rows = $st->fetchAll();
      // synthesize name as "User #ID"
      foreach ($rows as &$r) {
        $r['name'] = 'User #'.(int)$r['user_id'];
      }
      return $rows;
    }
  } catch (Throwable $e) {
    return [];
  }
}

function guild_member_count(PDO $pdo, int $gid, array $m_cols): int {
  if (!$m_cols['guild_id']) return 0;
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM guild_members WHERE guild_id=?");
    $st->execute([$gid]);
    return (int)$st->fetchColumn();
  } catch (Throwable $e) { return 0; }
}

/* ---------- resolve which guild to show ---------- */
$gid = max(0, intish($_GET['id'] ?? 0, 0));
$my_member = $have_members ? my_membership($pdo, $user_id, $m_cols) : null;

if ($gid <= 0 && $my_member) { $gid = (int)$my_member['guild_id']; }

$guild = null;
if ($have_guilds && $gid > 0) {
  $guild = load_guild($pdo, $gid, $g_cols);
}
if (!$guild && $gid > 0) {
  $errors[] = 'Guild not found.';
}

/* ---------- actions (leave) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? '')) {
  $act = (string)($_POST['action'] ?? '');
  try {
    if ($act === 'leave' && $my_member) {
      $pdo->prepare("DELETE FROM guild_members WHERE user_id=?")->execute([$user_id]);
      $notices[] = 'You left your guild.';
      // After leaving, redirect to guilds list
      header('Location: guilds.php'); exit;
    }
  } catch (Throwable $e) {
    $errors[] = 'Action failed: ' . $e->getMessage();
  }
}

/* ---------- load members ---------- */
$members = [];
if ($guild) {
  $members = load_guild_members($pdo, (int)$guild['id'], $m_cols, $u_cols);
}

/* ---------- derived bits for view ---------- */
$viewer_in_this_guild = $my_member && $guild && ((int)$my_member['guild_id'] === (int)$guild['id']);
$viewer_role = $viewer_in_this_guild ? (string)($my_member['role'] ?? 'member') : null;
$member_count = $guild ? guild_member_count($pdo, (int)$guild['id'], $m_cols) : 0;
$emblem_text = $guild ? strtoupper(substr(($guild['tag'] ?? $guild['name'] ?? 'G'), 0, 2)) : '??';

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= $guild ? h($guild['name'] ?? ('Guild #'.(int)$guild['id'])) : 'Guild' ?> — Eldvar</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  <style>
    /* scoped styles */
    .guild-view .header {display:flex; gap:12px; align-items:center; flex-wrap:wrap}
    .guild-view .emblem {width:56px; height:56px; border-radius:12px; border:1px solid var(--border); background:var(--panel-2); display:grid; place-items:center; font-weight:800; color:var(--accent-2)}
    .guild-view .title {margin:0; font-size:1.4rem; display:flex; gap:8px; align-items:center; flex-wrap:wrap}
    .guild-view .tag {display:inline-block; font-size:12px; padding:4px 8px; border-radius:999px; border:1px solid var(--border); background:#0f1726; color:var(--muted)}
    .guild-view .meta {display:flex; gap:10px; flex-wrap:wrap; color:var(--muted); margin-top:6px}
    .guild-view .badge {display:inline-flex; gap:6px; align-items:center; padding:4px 10px; border-radius:999px; border:1px solid var(--border); background:var(--panel-2)}
    .guild-view .desc {margin:10px 0 14px; color:var(--muted)}
    .guild-view .btn {display:inline-flex; align-items:center; gap:8px; padding:10px 12px; border-radius:10px; border:1px solid var(--border); background:var(--panel-2); color:var(--text); text-decoration:none; cursor:pointer}
    .guild-view .btn.primary {border-color:rgba(119,221,119,.38); background:rgba(119,221,119,.12); box-shadow:0 0 14px rgba(119,221,119,.22)}
    .guild-view .grid {display:grid; gap:14px; grid-template-columns: 2fr 1fr}
    @media(max-width:960px){ .guild-view .grid{ grid-template-columns: 1fr } }
    .guild-view .card {background:var(--panel); border:1px solid var(--border); border-radius:14px; padding:14px}
    .guild-view table {width:100%; border-collapse: collapse}
    .guild-view th, .guild-view td {border-bottom:1px solid var(--border); padding:8px 6px; text-align:left}
    .guild-view th {color:var(--accent-2)}
    .guild-view .alert {padding:10px 12px; border-radius:10px; border:1px solid var(--border); background:var(--panel-2)}
    .guild-view .alert.error {border-color:#6d2d2d; background:rgba(255,60,60,.08)}
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="app-main guild-view">
      <div class="card">
        <div class="header">
          <div class="emblem"><?= h($emblem_text) ?></div>
          <div>
            <h1 class="title">
              <?= $guild ? h($guild['name'] ?? ('Guild #'.(int)$guild['id'])) : 'Guild' ?>
              <?php if (!empty($guild['tag'])): ?><span class="tag"><?= h((string)$guild['tag']) ?></span><?php endif; ?>
            </h1>
            <?php if ($guild): ?>
              <div class="meta">
                <span class="badge">Members: <?= (int)$member_count ?></span>
                <?php if (!empty($guild['created_at'])): ?><span class="badge">Founded: <?= h(fmt_date((string)$guild['created_at'])) ?></span><?php endif; ?>
                <?php if (isset($guild['is_recruiting'])): ?><span class="badge"><?= ((int)$guild['is_recruiting'] ? 'Recruiting' : 'Closed') ?></span><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($errors): ?>
          <div class="alert error" style="margin-top:10px;"><?php foreach ($errors as $e) echo '<div>'.$e.'</div>'; ?></div>
        <?php endif; ?>
        <?php if ($notices): ?>
          <div class="alert" style="margin-top:10px; border-color:rgba(119,221,119,.35); background:rgba(119,221,119,.08);">
            <?php foreach ($notices as $n) echo '<div>'.h($n).'</div>'; ?>
          </div>
        <?php endif; ?>

        <?php if ($guild && !empty($guild['description'])): ?>
          <p class="desc"><?= nl2br(h((string)$guild['description'])) ?></p>
        <?php elseif ($guild): ?>
          <p class="desc" style="opacity:.8;">This guild has not written a description yet.</p>
        <?php endif; ?>

        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:6px;">
          <a class="btn" href="guilds.php">← Back to Guilds</a>
          <?php if ($viewer_in_this_guild): ?>
            <form method="post" onsubmit="return confirm('Leave your guild?');" style="display:inline">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <button class="btn" type="submit" name="action" value="leave">Leave Guild</button>
            </form>
          <?php elseif ($guild && (!isset($guild['is_recruiting']) || (int)$guild['is_recruiting'] === 1)): ?>
            <button class="btn primary" onclick="alert('Application flow coming soon.');">Apply</button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Layout: Members list (left), Sidebar (right) -->
      <section class="grid" style="margin-top:12px;">
        <article class="card">
          <h2 class="card-title">Members</h2>
          <?php if (!$guild): ?>
            <p class="muted">No guild selected.</p>
          <?php elseif (!$members): ?>
            <p class="muted">No members found.</p>
          <?php else: ?>
            <table>
              <thead>
                <tr>
                  <th>Member</th>
                  <?php if ($m_cols['role']): ?><th>Role</th><?php endif; ?>
                  <?php if ($m_cols['joined_at']): ?><th>Joined</th><?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($members as $m): ?>
                  <tr>
                    <td><?= h((string)$m['name']) ?></td>
                    <?php if ($m_cols['role']): ?><td><?= h((string)($m['role'] ?? 'member')) ?></td><?php endif; ?>
                    <?php if ($m_cols['joined_at']): ?><td><?= h(fmt_date($m['joined_at'] ?? '')) ?></td><?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </article>

        <aside class="card">
          <h2 class="card-title">About</h2>
          <p class="muted">Rally under the banner, pool resources, and carve your legend. Guild halls and upgrades are coming soon.</p>
          <?php if ($guild && isset($guild['owner_id']) && (int)$guild['owner_id'] > 0): ?>
            <p class="muted" style="margin-top:8px;">Founder: Player #<?= (int)$guild['owner_id'] ?></p>
          <?php endif; ?>
          <?php if ($viewer_role): ?>
            <p class="muted" style="margin-top:8px;">Your role: <strong><?= h($viewer_role) ?></strong></p>
          <?php endif; ?>
        </aside>
      </section>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>

  <?php include __DIR__ . '/includes/chat.php'; ?>
</body>
</html>
