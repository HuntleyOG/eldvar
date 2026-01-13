<?php
declare(strict_types=1);

// /tavern.php — Eldvar Tavern (bulletin-board style)

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_login(); // must be logged in

/* ---------- helpers ---------- */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function tavern_csrf_token(): string {
  if (!empty($_SESSION['tavern_csrf'])) return $_SESSION['tavern_csrf'];
  try { $_SESSION['tavern_csrf'] = bin2hex(random_bytes(32)); }
  catch (Throwable) { $_SESSION['tavern_csrf'] = sha1(uniqid('', true)); }
  return $_SESSION['tavern_csrf'];
}
function tavern_csrf_check(?string $t): bool {
  return !empty($t) && !empty($_SESSION['tavern_csrf']) && hash_equals($_SESSION['tavern_csrf'], $t);
}
function table_exists(PDO $pdo, string $name): bool {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
  $st->execute([$name]); return (bool)$st->fetchColumn();
}
function can_moderate_tavern(): bool {
  $role = function_exists('current_user_role') ? current_user_role(true) : 'player';
  $role = strtolower($role);
  return in_array($role, ['admin','governor'], true);
}

/* ---------- db ---------- */
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$errors = [];
$notices = [];
$user_id = (int)($_SESSION['user_id'] ?? 0);

/* ---------- schema check ---------- */
$have_posts = false;
try {
  $have_posts = table_exists($pdo, 'tavern_posts');
} catch (Throwable $e) {
  $errors[] = 'Table check failed: ' . $e->getMessage();
}

/* ---------- actions ---------- */
$action = (string)($_POST['action'] ?? '');
if ($have_posts && $_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
  if (!tavern_csrf_check($_POST['csrf'] ?? '')) {
    $errors[] = 'Invalid session token.';
  } else {
    if ($action === 'create') {
      $title = trim((string)($_POST['title'] ?? ''));
      $body  = trim((string)($_POST['body'] ?? ''));
      $days  = (int)($_POST['expires_days'] ?? 7); // default 7 days
      if ($days < 0) $days = 0;
      if ($days > 90) $days = 90;

      if ($title === '' || $body === '') {
        $errors[] = 'Please enter a title and a message.';
      } elseif (mb_strlen($title) > 120) {
        $errors[] = 'Title is too long (max 120).';
      } elseif (mb_strlen($body) > 3000) {
        $errors[] = 'Message is too long (max 3000).';
      } else {
        try {
          if ($days === 0) {
            $st = $pdo->prepare("INSERT INTO tavern_posts (user_id, title, body, expires_at) VALUES (?,?,?, NULL)");
            $st->execute([$user_id, $title, $body]);
          } else {
            $st = $pdo->prepare("INSERT INTO tavern_posts (user_id, title, body, expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY))");
            $st->execute([$user_id, $title, $body, $days]);
          }
          $notices[] = 'Posted!';
          // PRG pattern to avoid resubmission
          header('Location: ' . BASE_URL . '/tavern.php');
          exit;
        } catch (Throwable $e) {
          $errors[] = 'Post failed: ' . $e->getMessage();
        }
      }
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        try {
          // Only owner or moderator can delete
          $st = $pdo->prepare("SELECT user_id FROM tavern_posts WHERE id=?");
          $st->execute([$id]);
          $owner = (int)($st->fetchColumn() ?: 0);

          if ($owner === $user_id || can_moderate_tavern()) {
            $pdo->prepare("DELETE FROM tavern_posts WHERE id=?")->execute([$id]);
            $notices[] = 'Post removed.';
            header('Location: ' . BASE_URL . '/tavern.php');
            exit;
          } else {
            $errors[] = 'You cannot delete this post.';
          }
        } catch (Throwable $e) {
          $errors[] = 'Delete failed: ' . $e->getMessage();
        }
      }
    }
  }
}

/* ---------- paging ---------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 20;
$off  = ($page - 1) * $per;

$total = 0;
$posts = [];
if ($have_posts) {
  try {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM tavern_posts WHERE expires_at IS NULL OR expires_at > NOW()")->fetchColumn();
    $st = $pdo->prepare("
      SELECT p.id, p.user_id, p.title, p.body, p.created_at, p.expires_at,
             u.username, u.display_name, u.avatar_url
      FROM tavern_posts p
      LEFT JOIN users u ON u.id = p.user_id
      WHERE p.expires_at IS NULL OR p.expires_at > NOW()
      ORDER BY p.id DESC
      LIMIT :per OFFSET :off
    ");
    $st->bindValue(':per', $per, PDO::PARAM_INT);
    $st->bindValue(':off', $off, PDO::PARAM_INT);
    $st->execute();
    $posts = $st->fetchAll();
  } catch (Throwable $e) {
    $errors[] = 'Load failed: ' . $e->getMessage();
  }
}
$total_pages = max(1, (int)ceil($total / $per));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Tavern — Eldvar</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  <style>
    .tavern-wrap{max-width:1000px;margin:0 auto}
    .post-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px}
    .post-card .meta{color:var(--muted);font-size:12px;margin-top:4px}
    .post-card .body{margin-top:8px;white-space:pre-wrap;word-wrap:break-word}
    .post-card header{display:flex;align-items:center;gap:10px}
    .avatar{width:36px;height:36px;border-radius:50%;border:1px solid var(--border);object-fit:cover;background:#0b1322}
    .avatar-fallback{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#0b1322;border:1px solid var(--border);color:var(--accent-2);font-weight:700}
    .actions-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    .pager{display:flex;gap:8px;justify-content:center;margin-top:14px}
    .alert.error{border:1px solid #7d2a2a;background:#2a1717;color:#f4c6c6;padding:10px;border-radius:8px}
    .alert.success{border:1px solid #2a7d4a;background:#172a1d;color:#c6f4d0;padding:10px;border-radius:8px}
    input[type=text], textarea, select{
      width:100%;background:#0b1322;border:1px solid rgba(255,255,255,.2);
      border-radius:8px;color:#e6edf3;padding:.6rem
    }
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="app-main">
      <div class="tavern-wrap">
        <section class="hero">
          <div class="hero-card">
            <h1 class="pixel-title">The Gilded Sprig — Tavern</h1>
            <p class="lead">Pin a note, seek a party, trade supplies, or share rumors overheard near the Elder Tree.</p>
          </div>
        </section>

        <?php if ($errors): ?>
          <div class="alert error" style="margin-bottom:12px;"><?php foreach ($errors as $e) echo '<div>'.h($e).'</div>'; ?></div>
        <?php endif; ?>
        <?php if ($notices): ?>
          <div class="alert success" style="margin-bottom:12px;"><?php foreach ($notices as $n) echo '<div>'.h($n).'</div>'; ?></div>
        <?php endif; ?>

        <section class="card" style="margin-bottom:14px;">
          <h2 class="card-title">Post a Note</h2>
          <?php if (!$have_posts): ?>
            <p class="muted">The tavern isn’t initialized. Ask an admin to create the <code>tavern_posts</code> table.</p>
          <?php else: ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h(tavern_csrf_token()) ?>">
              <input type="hidden" name="action" value="create">
              <div style="display:grid;gap:10px;grid-template-columns:1fr 180px">
                <div>
                  <label><strong>Title</strong><br>
                    <input type="text" name="title" maxlength="120" placeholder="Short, descriptive title">
                  </label>
                </div>
                <div>
                  <label><strong>Auto-expire</strong><br>
                    <select name="expires_days">
                      <option value="1">1 day</option>
                      <option value="3">3 days</option>
                      <option value="7" selected>7 days</option>
                      <option value="14">14 days</option>
                      <option value="30">30 days</option>
                      <option value="0">Never</option>
                    </select>
                  </label>
                </div>
              </div>
              <div style="margin-top:10px;">
                <label><strong>Message</strong><br>
                  <textarea name="body" rows="5" maxlength="3000" placeholder="What’s on your mind? Looking for a group? Trading supplies? Share it here."></textarea>
                </label>
              </div>
              <div class="actions-row">
                <button class="btn primary" type="submit">Post</button>
                <a class="btn" href="<?= BASE_URL ?>/tavern.php">Refresh</a>
              </div>
            </form>
          <?php endif; ?>
        </section>

        <section class="card">
          <h2 class="card-title">Recent Notes</h2>
          <?php if (!$have_posts): ?>
            <p class="muted">No board yet — table missing.</p>
          <?php elseif (!$posts): ?>
            <p class="muted">Nothing posted yet.</p>
          <?php else: ?>
            <div class="post-grid">
              <?php foreach ($posts as $p): ?>
                <article class="card post-card">
                  <header>
                    <?php
                      $disp = $p['display_name'] ?: $p['username'] ?: 'Adventurer';
                      $initials = strtoupper(mb_substr((string)$disp, 0, 1));
                      $avatar = (string)($p['avatar_url'] ?? '');
                    ?>
                    <?php if ($avatar): ?>
                      <img class="avatar" src="<?= h($avatar) ?>" alt="">
                    <?php else: ?>
                      <div class="avatar-fallback"><?= h($initials) ?></div>
                    <?php endif; ?>
                    <div>
                      <div><strong><?= h((string)$p['title']) ?></strong></div>
                      <div class="meta">
                        by <?= h($disp) ?> · <?= h((string)$p['created_at']) ?>
                        <?php if (!empty($p['expires_at'])): ?>
                          · expires <?= h((string)$p['expires_at']) ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  </header>

                  <div class="body"><?= nl2br(h((string)$p['body'])) ?></div>

                  <?php if ((int)$p['user_id'] === $user_id || can_moderate_tavern()): ?>
                    <form method="post" class="actions-row">
                      <input type="hidden" name="csrf" value="<?= h(tavern_csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <button class="btn" type="submit" onclick="return confirm('Delete this post?')">Delete</button>
                    </form>
                  <?php endif; ?>
                </article>
              <?php endforeach; ?>
            </div>

            <?php if ($total_pages > 1): ?>
              <div class="pager">
                <?php if ($page > 1): ?>
                  <a class="btn" href="<?= BASE_URL ?>/tavern.php?page=<?= $page-1 ?>">← Prev</a>
                <?php endif; ?>
                <span class="btn"><?= $page ?> / <?= $total_pages ?></span>
                <?php if ($page < $total_pages): ?>
                  <a class="btn" href="<?= BASE_URL ?>/tavern.php?page=<?= $page+1 ?>">Next →</a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>

      </div>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>

  <?php include __DIR__ . '/includes/chat.php'; ?>
</body>
</html>
