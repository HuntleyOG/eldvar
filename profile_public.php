<?php
declare(strict_types=1);
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$pdo = get_pdo();

$viewerId  = $_SESSION['user_id'] ?? null;
$profileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($profileId <= 0) {
  if ($viewerId) {
    $profileId = (int)$viewerId;
  } else {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
  }
}

/* -------- Fetch user row safely (no unknown columns in SELECT) -------- */
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$profileId]);
$row = $stmt->fetch();

if (!$row) {
  http_response_code(404);
  ?>
  <!doctype html><html lang="en"><head><meta charset="utf-8"><title>Profile not found — Eldvar</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css"></head>
  <body class="with-sidebar">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <div id="backdrop" class="backdrop"></div>
    <div class="app-shell">
      <?php include __DIR__ . '/includes/header.php'; ?>
      <main class="app-main">
        <section class="card"><h2 class="card-title">Profile not found</h2><p class="muted">That character doesn’t exist.</p></section>
      </main>
      <?php include __DIR__ . '/includes/footer.php'; ?>
    </div>
  </body></html>
  <?php
  exit;
}

/* -------- Derive fields with fallbacks (only use if present) ---------- */
$profile = [
  'id'           => (int)$row['id'],
  'username'     => (string)$row['username'],
  'display_name' => (string)($row['display_name'] ?? $row['username']),
  'bio'          => (string)($row['bio'] ?? ''),
  'avatar_url'   => (string)($row['avatar_url'] ?? ''),
  'banner_url'   => (string)($row['banner_url'] ?? ''),
  'level'        => (int)($row['level'] ?? 1),
  'verified'     => (int)($row['verified'] ?? 0),
  'status_text'  => (string)($row['status_text'] ?? ''),
  'role'         => strtolower((string)($row['role'] ?? 'player')),
  'created_at'   => (string)($row['created_at'] ?? ''),
];

/* last_seen is optional in your schema; fall back gracefully */
$lastSeen = $row['last_seen'] ?? ($row['updated_at'] ?? $row['created_at'] ?? null);

/* Fallback assets */
$avatar = $profile['avatar_url'] ?: BASE_URL . '/public/img/avatar-default.png';
$banner = $profile['banner_url'] ?: BASE_URL . '/public/img/banner-default.jpg';

function time_ago(?string $ts): string {
  if (!$ts) return '—';
  $t = is_numeric($ts) ? (int)$ts : strtotime($ts);
  if (!$t) return '—';
  $diff = time() - $t;
  if ($diff < 60) return $diff . 's ago';
  if ($diff < 3600) return floor($diff/60) . 'm ago';
  if ($diff < 86400) return floor($diff/3600) . 'h ago';
  if ($diff < 604800) return floor($diff/86400) . 'd ago';
  return date('M j, Y', $t);
}

/* ---------------------- Comments bootstrap/handlers ------------------- */
$pdo->exec("
CREATE TABLE IF NOT EXISTS profile_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  profile_user_id INT NOT NULL,
  author_user_id INT NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  deleted TINYINT(1) NOT NULL DEFAULT 0,
  INDEX (profile_user_id),
  INDEX (author_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    // soft fail; PRG
  } else {
    $action = $_POST['action'];
    if ($action === 'add' && $viewerId) {
      $body = trim((string)($_POST['body'] ?? ''));
      if ($body !== '') {
        $stmt = $pdo->prepare('INSERT INTO profile_comments (profile_user_id, author_user_id, body) VALUES (?,?,?)');
        $stmt->execute([$profile['id'], $viewerId, $body]);
      }
    } elseif ($action === 'delete' && $viewerId) {
      $cid = (int)($_POST['cid'] ?? 0);
      if ($cid > 0) {
        $vRole = strtolower((string)($row['role'] ?? 'player')); // current viewer role; quick fetch
        $rs = $pdo->prepare('SELECT author_user_id FROM profile_comments WHERE id=? AND profile_user_id=?');
        $rs->execute([$cid, $profile['id']]);
        $c = $rs->fetch();
        if ($c && ((int)$c['author_user_id'] === (int)$viewerId || in_array($vRole, ['admin','governor','govenor'], true))) {
          $pdo->prepare('UPDATE profile_comments SET deleted=1 WHERE id=?')->execute([$cid]);
        }
      }
    }
  }
  header('Location: ' . BASE_URL . '/profile_public.php?id=' . (int)$profile['id']);
  exit;
}

/* Fetch latest comments */
$stmt = $pdo->prepare("
  SELECT c.id, c.body, c.created_at, c.author_user_id,
         u.username, COALESCE(u.display_name, u.username) AS display_name,
         COALESCE(u.avatar_url, '') AS avatar_url,
         LOWER(COALESCE(u.role, 'player')) AS role
  FROM profile_comments c
  JOIN users u ON u.id = c.author_user_id
  WHERE c.profile_user_id = ? AND c.deleted = 0
  ORDER BY c.id DESC
  LIMIT 25
");
$stmt->execute([$profile['id']]);
$comments = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($profile['display_name']) ?> — Profile</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  <style>
    .tabs{display:flex;gap:8px;background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:6px 8px;margin-bottom:12px}
    .tabs a{color:var(--muted);text-decoration:none;padding:6px 10px;border-radius:8px}
    .tabs a.active,.tabs a:hover{background:var(--panel-2);color:var(--text)}
    .pub-hero{position:relative;border-radius:12px;overflow:hidden;border:1px solid var(--border)}
    .pub-hero img{display:block;width:100%;height:220px;object-fit:cover;filter:contrast(1.03) saturate(1.05)}
    .pub-hero .overlay{position:absolute;left:0;right:0;bottom:0;padding:14px;background:linear-gradient(180deg, rgba(0,0,0,0) 0%, rgba(10,12,16,.6) 40%, rgba(10,12,16,.85) 100%)}
    .pub-card{display:flex;gap:14px;align-items:center}
    .pub-avatar{width: 96px; height: 96px;max-width: 128px; max-height: 128px;border-radius: 999px;border: 3px solid rgba(255,255,255,.08);background: var(--panel);object-fit: cover;}
    .name-row{display:flex;align-items:center;gap:8px}
    .verified{display:inline-block;border-radius:999px;border:1px solid rgba(119,221,119,.4);color:#9fe7a9;font-size:11px;padding:0 6px}
    .pill{display:inline-block;border:1px solid var(--border);border-radius:999px;padding:2px 8px;font-size:12px;color:var(--muted)}
    .action-row{margin-left:auto;display:flex;gap:8px}
    .bar{height:8px;background:var(--panel-2);border:1px solid var(--border);border-radius:999px;overflow:hidden}
    .bar > span{display:block;height:100%;background:linear-gradient(90deg, rgba(119,221,119,.25), rgba(160,224,255,.25))}
    .comment-item{display:flex;gap:10px;padding:10px;border-top:1px solid var(--border)}
    .comment-item:first-child{border-top:none}
    .c-avatar{width:36px;height:36px;border-radius:999px;background:var(--panel-2);border:1px solid var(--border)}
    .c-header{display:flex;align-items:center;gap:8px}
    .c-meta{font-size:12px;color:var(--muted)}
    .c-body{white-space:pre-wrap}
    
    .comment-form textarea{width:100%;background:var(--panel-2);border:1px solid var(--border);color:var(--text);border-radius:10px;padding:10px;min-height:80px}
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="app-main">
      <nav class="tabs">
        <a class="active" href="#">Profile</a>
        <a href="#">Stats</a>
        <a href="#">Inventory</a>
        <a href="#">Equipped</a>
        <a href="#">Awards</a>
        <a href="#">Showcase</a>
      </nav>

      <section class="pub-hero">
        <img src="<?= htmlspecialchars($banner) ?>" alt="">
        <div class="overlay">
          <div class="pub-card">
            <img class="pub-avatar" src="<?= htmlspecialchars($avatar) ?>" alt="">
            <div>
              <div class="name-row">
                <h1 class="pixel-title" style="margin:0;font-size:20px;letter-spacing:.5px;">
                  <?= htmlspecialchars($profile['display_name']) ?>
                </h1>
                <?php if ($profile['verified'] === 1): ?>
                  <span class="verified">✔ verified</span>
                <?php endif; ?>
              </div>
              <div class="muted" style="font-size:12px;">
                Level <?= (int)$profile['level'] ?> •
                Last online <?= htmlspecialchars(time_ago($lastSeen)) ?>
              </div>
            </div>
            <div class="action-row">
              <a class="btn" href="#" title="(WIP)">Attack</a>
              <?php if ($viewerId && $viewerId !== $profile['id']): ?>
                <a class="btn primary" href="<?= BASE_URL ?>/message.php?to=<?= (int)$profile['id'] ?>">Message</a>
              <?php elseif (!$viewerId): ?>
                <a class="btn primary" href="<?= BASE_URL ?>/login.php">Login to message</a>
              <?php else: ?>
                <a class="btn" href="<?= BASE_URL ?>/settings_profile.php">Edit Profile</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>

      <section class="card" style="margin-top:16px;">
        <h2 class="card-title">Motto</h2>
        <div class="bar" style="margin:8px 0 12px;"><span style="width:100%"></span></div>
        <p style="text-align:center;margin:10px 0;">
          <?php if ($profile['status_text']): ?>
            “<?= htmlspecialchars($profile['status_text']) ?>”
          <?php else: ?>
            <span class="muted">There is no motto for this player.</span>
          <?php endif; ?>
        </p>
      </section>

      <div class="grid">
        <article class="card">
          <h2 class="card-title">Bio</h2>
          <?php if ($profile['bio']): ?>
            <p><?= nl2br(htmlspecialchars($profile['bio'])) ?></p>
          <?php else: ?>
            <p class="muted"><?= ($viewerId === $profile['id'])
              ? 'Add a short bio in settings.'
              : 'No bio yet.' ?></p>
          <?php endif; ?>
        </article>

        <article class="card">
          <h2 class="card-title">Feed</h2>
          <div class="muted">Recent milestone/activity feed coming soon.</div>
        </article>
      </div>

      <section class="card" style="margin-top:16px;">
        <h2 class="card-title">Profile Comments</h2>

        <?php if ($viewerId): ?>
          <form class="comment-form" method="post" style="margin-bottom:10px;">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <textarea name="body" placeholder="Write something nice…" maxlength="800" required></textarea>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px;">
              <button class="btn" type="reset">Reset</button>
              <button class="btn primary" type="submit">Reply</button>
            </div>
          </form>
        <?php else: ?>
          <p class="muted">Please <a href="<?= BASE_URL ?>/login.php">log in</a> to comment.</p>
        <?php endif; ?>

        <?php if (!$comments): ?>
          <p class="muted" style="margin:8px 0 0;">No comments yet.</p>
        <?php else: ?>
          <?php
            $viewerRole = 'player';
            if ($viewerId) {
              $r = $pdo->prepare('SELECT role FROM users WHERE id = ?');
              $r->execute([$viewerId]);
              $viewerRole = strtolower((string)$r->fetchColumn() ?: 'player');
            }
          ?>
          <?php foreach ($comments as $c): ?>
            <div class="comment-item">
              <img class="c-avatar" src="<?= htmlspecialchars($c['avatar_url'] ?: (BASE_URL . '/public/img/avatar-default.png')) ?>" alt="">
              <div style="flex:1;">
                <div class="c-header">
                  <strong><?= htmlspecialchars($c['display_name']) ?></strong>
                  <span class="c-meta">• <?= htmlspecialchars(time_ago($c['created_at'])) ?></span>
                  <?php if ($viewerId && ((int)$c['author_user_id'] === (int)$viewerId || in_array($viewerRole, ['admin','governor','govenor'], true))): ?>
                    <form method="post" style="margin-left:auto;">
                      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="cid" value="<?= (int)$c['id'] ?>">
                      <button class="btn" type="submit" onclick="return confirm('Delete this comment?')">Delete</button>
                    </form>
                  <?php endif; ?>
                </div>
                <div class="c-body"><?= nl2br(htmlspecialchars($c['body'])) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>

  <?php if (file_exists(__DIR__ . '/includes/chat.php')) { include __DIR__ . '/includes/chat.php'; } ?>
</body>
</html>
