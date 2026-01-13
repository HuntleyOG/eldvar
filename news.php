<?php
declare(strict_types=1);
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$pdo = get_pdo();

/* ------------------------------------------------------------------
   Ensure the same schema your Admin uses
-------------------------------------------------------------------*/
$pdo->exec("
  CREATE TABLE IF NOT EXISTS news (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    body MEDIUMTEXT NOT NULL,
    type ENUM('news','patch') NOT NULL DEFAULT 'news',
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    image_path VARCHAR(255) NULL,
    author_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
/* Defensive migrations — harmless if already present */
try { $pdo->exec("ALTER TABLE news ADD COLUMN type   ENUM('news','patch') NOT NULL DEFAULT 'news'"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE news ADD COLUMN status ENUM('draft','published') NOT NULL DEFAULT 'draft'"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE news ADD COLUMN image_path VARCHAR(255) NULL"); } catch (Throwable $e) {}
try { $pdo->exec("ALTER TABLE news ADD COLUMN slug VARCHAR(190) NOT NULL UNIQUE"); } catch (Throwable $e) {}

/* ------------------------------------------------------------------
   Filters (search + draft visibility)
-------------------------------------------------------------------*/
$q          = trim((string)($_GET['q'] ?? ''));
$showDrafts = isset($_SESSION['user_id']); // allow drafts to logged-in users

$where  = [];
$params = [];
if (!$showDrafts) {
  $where[] = "n.status = 'published'";
}
if ($q !== '') {
  $where[] = "(n.title LIKE :q OR n.body LIKE :q OR n.slug LIKE :q)";
  $params[':q'] = "%{$q}%";
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
  SELECT n.id, n.slug, n.title, n.body, n.type, n.status, n.image_path, n.created_at,
         u.username AS author
  FROM news n
  LEFT JOIN users u ON u.id = n.author_id
  $whereSql
  ORDER BY n.created_at DESC
  LIMIT 50
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ------------------------------------------------------------------
   Helpers/paths
-------------------------------------------------------------------*/
$MEDIA_URL = rtrim(BASE_URL, '/') . '/news/media';
$asset     = fn(string $p) => rtrim(BASE_URL,'/') . '/' . ltrim($p,'/');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Eldvar — News & Patch Notes</title>
  <link rel="stylesheet" href="<?= $asset('public/css/style.css') ?>">
  <style>
/* Card layout stays the same */
.news-list{display:grid;gap:14px;margin-top:16px}
.news-card{
  display:flex;gap:12px;align-items:flex-start;
  background:var(--panel);border:1px solid var(--border);
  border-radius:12px;padding:12px;text-decoration:none;color:var(--text);
}

/* Fixed-size frame so cards line up */
.news-thumb{
  flex:0 0 120px;               /* reserve the space in the row */
  width:120px; height:80px;     /* consistent frame (3:2) */
  border:1px solid var(--border);
  border-radius:8px;
  background: var(--panel-2);   /* subtle letterbox bg */
  object-fit: contain;          /* <-- keep the image’s aspect ratio */
  object-position: center;
  image-rendering: pixelated;   /* nice for pixel-art assets */
  padding: 2px;                 /* tiny inner gutter so borders don’t touch */
}

/* When there’s no image, keep the same box so rows don’t jump */
.news-thumb.placeholder{
  display:block;                 /* empty frame */
}

.news-body{flex:1;min-width:0}
.news-body h3{margin:0 0 4px;font-size:1.05rem}
.news-meta{color:var(--muted);font-size:12px;margin-bottom:6px}
.pill{display:inline-block;padding:2px 8px;border:1px solid var(--border);border-radius:999px;font-size:12px;margin-right:6px}
.pill.patch{border-color:rgba(160,224,255,.35);color:#a0e0ff}
.pill.news{border-color:rgba(119,221,119,.35);color:#9fe7a9}
.pill.status{color:var(--muted)}
.excerpt{margin:0;opacity:.95}

@media (max-width:700px){
  .news-card{flex-direction:column}
  .news-thumb{width:100%; height:180px; flex:0 0 auto;}
}
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="app-main">
      <article>
        <h1 class="pixel-title">News & Patch Notes</h1>
        <p class="muted">Latest updates, announcements, and patch notes for Eldvar.</p>

        <!-- Search -->
        <form method="get" style="display:flex;gap:8px;margin:10px 0 16px;">
          <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search news…"
                 style="flex:1;background:var(--panel-2);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:10px 12px;">
          <button class="btn" type="submit">Search</button>
          <?php if ($q !== ''): ?>
            <a class="btn" href="<?= BASE_URL ?>/news.php">Clear</a>
          <?php endif; ?>
        </form>

        <div class="muted" style="font-size:12px;margin:-6px 0 8px;">
          <?php if ($items): ?>
            Showing <?= count($items) ?> post<?= count($items)===1?'':'s' ?><?= $q ? " for “".htmlspecialchars($q)."”" : '' ?>.
          <?php endif; ?>
        </div>

        <?php if (!$items): ?>
          <p class="muted">No news posts<?= $q ? " for “" . htmlspecialchars($q) . "”" : '' ?>.</p>
        <?php else: ?>
          <div class="news-list">
            <?php foreach ($items as $n): ?>
              <a class="news-card" href="<?= BASE_URL ?>/news_view.php?slug=<?= urlencode($n['slug']) ?>">
                <?php if (!empty($n['image_path'])): ?>
                  <img class="news-thumb"
                       src="<?= $MEDIA_URL . '/' . rawurlencode($n['image_path']) ?>"
                       alt="<?= htmlspecialchars($n['title']) ?>">
                <?php else: ?>
                  <div class="news-thumb" aria-hidden="true"></div>
                <?php endif; ?>

                <div class="news-body">
                  <h3><?= htmlspecialchars($n['title']) ?></h3>
                  <div class="news-meta">
                    <span class="pill <?= ($n['type'] ?? 'news')==='patch' ? 'patch' : 'news' ?>">
                      <?= ($n['type'] ?? 'news')==='patch' ? 'Patch Notes' : 'News' ?>
                    </span>
                    <span class="pill status"><?= ucfirst($n['status'] ?? 'published') ?></span>
                    • <?= htmlspecialchars(date('Y-m-d', strtotime($n['created_at']))) ?>
                    <?php if (!empty($n['author'])): ?> • By <?= htmlspecialchars($n['author']) ?><?php endif; ?>
                  </div>
                  <p class="excerpt"><?= htmlspecialchars(mb_strimwidth(strip_tags($n['body']), 0, 220, '…')) ?></p>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>
</body>
</html>
