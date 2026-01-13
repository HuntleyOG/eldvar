<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php'; // <-- added
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$pdo = get_pdo();

/* Be explicit about PDO behavior */
try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* ignore */ }

/* ------------------------------------------------------------------
   Ensure tables exist (no-ops if already there)
-------------------------------------------------------------------*/
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS wiki_pages (
      id INT AUTO_INCREMENT PRIMARY KEY,
      slug VARCHAR(150) NOT NULL UNIQUE,
      title VARCHAR(150) NOT NULL,
      content MEDIUMTEXT NOT NULL,
      image_path VARCHAR(255) NULL,
      status ENUM('draft','published') NOT NULL DEFAULT 'draft',
      author_id INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS wiki_categories (
      id INT AUTO_INCREMENT PRIMARY KEY,
      slug VARCHAR(150) NOT NULL UNIQUE,
      name VARCHAR(150) NOT NULL,
      description TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS wiki_page_categories (
      page_id INT NOT NULL,
      category_id INT NOT NULL,
      PRIMARY KEY (page_id, category_id),
      FOREIGN KEY (page_id) REFERENCES wiki_pages(id) ON DELETE CASCADE,
      FOREIGN KEY (category_id) REFERENCES wiki_categories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {
  http_response_code(500);
  echo "<!doctype html><meta charset='utf-8'><title>Wiki error</title><pre>Setup failed: "
       . htmlspecialchars($e->getMessage()) . "</pre>";
  exit;
}

/* ------------------------------------------------------------------
   Query params & visibility
-------------------------------------------------------------------*/
$q          = trim((string)($_GET['q'] ?? ''));
$showDrafts = is_wiki_editor(); // <-- only wiki editors see drafts

/* ------------------------------------------------------------------
   Fetch categories with counts
-------------------------------------------------------------------*/
$cats = [];
try {
  $countFilter = $showDrafts ? '' : "AND p.status = 'published'";
  $cats = $pdo->query("
    SELECT c.id, c.name, c.slug, COALESCE(c.description,'') AS description,
           (
             SELECT COUNT(*)
             FROM wiki_page_categories pc
             JOIN wiki_pages p ON p.id = pc.page_id
             WHERE pc.category_id = c.id
             $countFilter
           ) AS page_count
    FROM wiki_categories c
    ORDER BY c.name ASC
  ")->fetchAll();
} catch (Throwable $e) {
  $cats = [];
}

/* ------------------------------------------------------------------
   Fetch recent pages (with optional search)
-------------------------------------------------------------------*/
$MEDIA_URL = BASE_URL . '/wiki/media';

$params = [];
$where  = [];
if (!$showDrafts) {
  $where[] = "p.status = 'published'";
}
if ($q !== '') {
  $where[] = "(p.title LIKE :q OR p.slug LIKE :q)";
  $params[':q'] = '%' . $q . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
  SELECT p.id, p.title, p.slug, p.status, p.updated_at, p.image_path
  FROM wiki_pages p
  $whereSql
  ORDER BY p.updated_at DESC
  LIMIT 100
";
$pages = [];
try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $pages = $stmt->fetchAll();
} catch (Throwable $e) {
  $pages = [];
}

/* ------------------------------------------------------------------
   Helpers
-------------------------------------------------------------------*/
$asset = fn(string $path) => rtrim(BASE_URL,'/') . '/' . ltrim($path,'/');

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Eldvar Wiki — Main Page</title>
  <meta name="description" content="The official Eldvar Wiki — learn about gameplay, skills, lore, and items.">
  <link rel="stylesheet" href="<?= $asset('public/css/style.css') ?>">
  <style>
    .cat-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
    .cat-card{display:flex;flex-direction:column;gap:6px;background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:12px}
    .cat-card .muted{font-size:12px}
    .wiki-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:12px}
    .wiki-item{display:flex;gap:12px;align-items:center;background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:10px;text-decoration:none;color:var(--text)}
    .wiki-thumb{width:56px;height:56px;object-fit:cover;border-radius:8px;border:1px solid var(--border);background:var(--panel-2)}
    .wiki-meta small{color:var(--muted)}
    @media (max-width:1100px){.cat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media (max-width:800px){.cat-grid,.wiki-list{grid-template-columns:1fr}}
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <main class="app-main">
      <article class="wiki-article">
        <h1>Eldvar Wiki</h1>
        <p class="muted">
          Browse by category or search pages.
          <?php if ($showDrafts): ?>
            You’re a wiki editor; drafts are visible here.
          <?php endif; ?>
        </p>

        <!-- Search / actions -->
        <form method="get" class="wiki-search" style="display:flex; gap:8px; margin: 10px 0 16px;">
          <input
            type="text"
            name="q"
            value="<?= htmlspecialchars($q) ?>"
            placeholder="Search wiki by title or slug…"
            style="flex:1; background:var(--panel-2); color:var(--text); border:1px solid var(--border); border-radius:8px; padding:10px 12px;"
          />
          <button class="btn" type="submit">Search</button>
          <?php if ($q !== ''): ?>
            <a class="btn" href="<?= BASE_URL ?>/wiki/">Clear</a>
          <?php endif; ?>
          <?php if (is_wiki_editor()): ?>
            <a class="btn primary" href="<?= BASE_URL ?>/wiki/admin/">Create Page</a>
          <?php endif; ?>
        </form>

        <!-- Categories -->
        <h2>Categories</h2>
        <?php if (!$cats): ?>
          <p class="muted">No categories yet.</p>
        <?php else: ?>
          <div class="cat-grid">
            <?php foreach ($cats as $c): ?>
              <article class="cat-card">
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:center">
                  <h3 style="margin:0"><?= htmlspecialchars($c['name']) ?></h3>
                  <a class="btn" href="<?= BASE_URL ?>/wiki/category.php?slug=<?= urlencode($c['slug']) ?>">Open</a>
                </div>
                <?php if (!empty($c['description'])): ?>
                  <div class="muted"><?= nl2br(htmlspecialchars($c['description'])) ?></div>
                <?php else: ?>
                  <div class="muted">No description.</div>
                <?php endif; ?>
                <div class="muted">Pages: <?= (int)$c['page_count'] ?></div>
                <?php if (is_wiki_editor()): ?>
                  <div>
                    <a class="btn" href="<?= BASE_URL ?>/wiki/admin/?new=1&cat=<?= urlencode($c['slug']) ?>">Create Page in “<?= htmlspecialchars($c['name']) ?>”</a>
                  </div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Recent pages -->
        <h2 style="margin-top:18px;">Recent Pages</h2>
        <?php if (!$pages): ?>
          <p class="muted">No pages found<?= $q ? " for “" . htmlspecialchars($q) . "”" : '' ?>.</p>
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

      </article>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
  </div>
</body>
</html>
