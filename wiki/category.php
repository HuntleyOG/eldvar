<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$pdo = get_pdo();

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') {
  http_response_code(404);
  exit('Category slug is required.');
}

// Load category
$stmt = $pdo->prepare("SELECT id, name, slug, description
                       FROM wiki_categories
                       WHERE slug = ?
                       LIMIT 1");
$stmt->execute([$slug]);
$cat = $stmt->fetch();

if (!$cat) {
  http_response_code(404);
  ?>
  <!doctype html>
  <html lang="en">
  <head>
    <meta charset="utf-8">
    <title>Category not found — Eldvar Wiki</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  </head>
  <body class="with-sidebar">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div id="backdrop" class="backdrop"></div>
    <div class="app-shell">
      <?php include __DIR__ . '/../includes/header.php'; ?>
      <main class="app-main">
        <article class="wiki-article">
          <h1>Category not found</h1>
          <p class="muted">That category does not exist.</p>
          <p><a class="btn" href="<?= BASE_URL ?>/wiki/">Back to wiki</a></p>
        </article>
      </main>
      <?php include __DIR__ . '/../includes/footer.php'; ?>
    </div>
  </body>
  </html>
  <?php
  exit;
}

// Logged-in users can see drafts in lists
$showDrafts = isset($_SESSION['user_id']);

// Fetch pages in this category
$params = [':cid' => (int)$cat['id']];
$where  = $showDrafts ? '' : "AND p.status = 'published'";

$stmt = $pdo->prepare("
  SELECT p.id, p.title, p.slug, p.status, p.updated_at, p.image_path
  FROM wiki_page_categories pc
  JOIN wiki_pages p ON p.id = pc.page_id
  WHERE pc.category_id = :cid
  $where
  ORDER BY p.updated_at DESC
");
$stmt->execute($params);
$pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Small asset helper
$asset = function(string $path): string {
  return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
};
$MEDIA_URL = BASE_URL . '/wiki/media';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($cat['name']) ?> — Eldvar Wiki</title>
  <link rel="stylesheet" href="<?= $asset('public/css/style.css') ?>">
  <style>
    .wiki-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:12px}
    .wiki-item{display:flex;gap:12px;align-items:center;background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:10px}
    .wiki-thumb{width:56px;height:56px;object-fit:cover;border-radius:8px;border:1px solid var(--border);background:var(--panel-2)}
    .wiki-meta small{color:var(--muted)}
    @media (max-width:800px){.wiki-list{grid-template-columns:1fr}}
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <main class="app-main">
      <article class="wiki-article">
        <h1><?= htmlspecialchars($cat['name']) ?></h1>
        <?php if (!empty($cat['description'])): ?>
          <p><?= nl2br(htmlspecialchars($cat['description'])) ?></p>
        <?php else: ?>
          <p class="muted">Pages filed under this category.</p>
        <?php endif; ?>

        <?php if (isset($_SESSION['user_id'])): ?>
          <p style="margin:10px 0;">
            <a class="btn primary" href="<?= BASE_URL ?>/wiki/admin/?new=1&cat=<?= urlencode($cat['slug']) ?>">
              Create Page in “<?= htmlspecialchars($cat['name']) ?>”
            </a>
          </p>
        <?php endif; ?>

        <?php if (!$pages): ?>
          <p class="muted" style="margin-top:12px;">No pages in this category yet.</p>
        <?php else: ?>
          <div class="wiki-list">
            <?php foreach ($pages as $p): ?>
              <a class="wiki-item" href="<?= BASE_URL ?>/wiki/view.php?slug=<?= urlencode($p['slug']) ?>">
                <?php if (!empty($p['image_path'])): ?>
                  <img class="wiki-thumb" src="<?= $MEDIA_URL . '/' . rawurlencode($p['image_path']) ?>" alt="">
                <?php else: ?>
                  <div class="wiki-thumb" aria-hidden="true"></div>
                <?php endif; ?>
                <div class="wiki-meta">
                  <div><strong><?= htmlspecialchars($p['title']) ?></strong></div>
                  <small>
                    <?= $p['status'] === 'published' ? 'Published' : 'Draft' ?> •
                    Updated <?= htmlspecialchars($p['updated_at']) ?>
                  </small>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <p style="margin-top:16px;">
          <a class="btn" href="<?= BASE_URL ?>/wiki/">← All categories</a>
        </p>
      </article>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
  </div>
</body>
</html>
