<?php
declare(strict_types=1);

// /news_view.php
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/auth.php'; // current_user_role()
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$pdo = get_pdo();

/* Predictable PDO */
try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* Visibility: drafts only for librarian/admin/governor */
$role = strtolower(current_user_role());
$canSeeDrafts = in_array($role, ['librarian','admin','governor','govenor'], true);

/* Input */
$slug = trim((string)($_GET['slug'] ?? ''));

/* Fetch post */
$post = null;
if ($slug !== '') {
  if ($canSeeDrafts) {
    $st = $pdo->prepare("SELECT n.*, u.username AS author_name
                         FROM news n
                         LEFT JOIN users u ON u.id = n.author_id
                         WHERE LOWER(n.slug) = LOWER(?) LIMIT 1");
    $st->execute([$slug]);
  } else {
    $st = $pdo->prepare("SELECT n.*, u.username AS author_name
                         FROM news n
                         LEFT JOIN users u ON u.id = n.author_id
                         WHERE LOWER(n.slug) = LOWER(?) AND n.status='published'
                         LIMIT 1");
    $st->execute([$slug]);
  }
  $post = $st->fetch();
}

/* Media URL for featured images uploaded via admin/news.php */
$MEDIA_URL = rtrim(BASE_URL, '/') . '/news/media';

/* Helper: safe asset path */
$asset = fn(string $p) => rtrim(BASE_URL,'/') . '/' . ltrim($p,'/');

/* Helper: render body (allow HTML from admin) */
function render_body(?string $html): string {
  if ($html === null) return '';
  // If plain text, escape + nl2br; otherwise assume trusted admin HTML
  if ($html === strip_tags($html)) {
    return nl2br(htmlspecialchars($html));
  }
  return $html;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= $post ? htmlspecialchars($post['title']).' — Eldvar News' : 'Not found — Eldvar News' ?></title>
  <link rel="stylesheet" href="<?= $asset('public/css/style.css') ?>">
  <style>
    /* Page-scoped helpers (feel free to move to style.css) */
    .news-wrap{max-width: 980px; margin: 0 auto;}
    .news-article{background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:16px;}
    .meta{color:var(--muted); font-size:12px; margin-bottom:12px}
  .cover {
    display: block;
    max-width: 100%;
    max-height: 480px;        /* cap, but image won’t be forced */
    height: auto;
    margin: 10px 0 14px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--panel-2);

    object-fit: contain;      /* <= important: fit inside instead of cropping */
  }

    .badge{display:inline-block; padding:2px 8px; border:1px solid var(--border); border-radius:999px; font-size:12px; color:var(--muted)}
    .badge.pub{ border-color: rgba(119,221,119,.35); color:#9fe7a9 }
    .badge.type{ border-color: rgba(160,224,255,.35); color: var(--accent-2) }
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>
    <main class="app-main">
      <div class="news-wrap">
        <article class="news-article">
          <?php if ($post): ?>
            <h1 class="pixel-title" style="margin:0 0 6px;"><?= htmlspecialchars($post['title']) ?></h1>

            <div class="meta">
              <span class="badge type"><?= $post['type'] === 'patch' ? 'Patch Notes' : 'News' ?></span>
              <?php if ($post['status'] === 'published'): ?>
                <span class="badge pub" style="margin-left:6px;">Published</span>
              <?php else: ?>
                <span class="badge" style="margin-left:6px;">Draft</span>
              <?php endif; ?>
              • By <?= htmlspecialchars($post['author_name'] ?? 'Unknown') ?>
              • Posted <?= htmlspecialchars($post['created_at']) ?>
              • Updated <?= htmlspecialchars($post['updated_at']) ?>
            </div>

            <?php if (!empty($post['image_path'])): ?>
              <!-- Featured image (this is what was missing) -->
              <img class="cover" src="<?= $MEDIA_URL . '/' . rawurlencode($post['image_path']) ?>" alt="">
            <?php endif; ?>

            <div><?= render_body($post['body']) ?></div>

            <div style="margin-top:12px;">
              <a class="btn" href="<?= $asset('news.php') ?>">Back to News</a>
              <?php if ($canSeeDrafts): ?>
                <a class="btn" href="<?= $asset('admin/news.php?edit='.(int)$post['id']) ?>">Edit</a>
              <?php endif; ?>
            </div>

          <?php else: ?>
            <h1>Not found</h1>
            <p class="muted">That post doesn’t exist or isn’t published.</p>
            <a class="btn" href="<?= $asset('news.php') ?>">Back to News</a>
          <?php endif; ?>
        </article>
      </div>
    </main>
    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>
</body>
</html>
