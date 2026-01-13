<?php
declare(strict_types=1);
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php'; // <-- added
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$pdo = get_pdo();

/* Make PDO a bit stricter/predictable */
try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* ------------------------------------------------------------------
   Ensure schema bits exist (no-ops if already present)
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
      infobox_json JSON NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  try { $pdo->exec("ALTER TABLE wiki_pages ADD COLUMN infobox_json JSON NULL"); } catch (Throwable $e) {}

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
  echo "<!doctype html><meta charset='utf-8'><title>Wiki error</title><pre>"
     . htmlspecialchars($e->getMessage()) . "</pre>";
  exit;
}

/* ------------------------------------------------------------------
   Load page (published for public, include drafts only for wiki editors)
-------------------------------------------------------------------*/
$slug = trim((string)($_GET['slug'] ?? ''));
$page = null;

if ($slug !== '') {
  $allowDrafts = is_wiki_editor(); // <-- changed
  if ($allowDrafts) {
    $stmt = $pdo->prepare('SELECT * FROM wiki_pages WHERE LOWER(slug) = LOWER(?) LIMIT 1');
    $stmt->execute([$slug]);
  } else {
    $stmt = $pdo->prepare('SELECT * FROM wiki_pages WHERE LOWER(slug) = LOWER(?) AND status = "published" LIMIT 1');
    $stmt->execute([$slug]);
  }
  $page = $stmt->fetch();
}

/* ------------------------------------------------------------------
   Helpers
-------------------------------------------------------------------*/
$asset = function(string $path): string {
  return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
};

function render_content(?string $html): string {
  if ($html === null) return '';
  if ($html === strip_tags($html)) {
    return nl2br(htmlspecialchars($html));
  }
  return $html;
}

/* ------------------------------------------------------------------
   Infobox (JSON) & categories for this page
-------------------------------------------------------------------*/
$ibox = [];
if (!empty($page['infobox_json'] ?? null)) {
  $decoded = json_decode((string)$page['infobox_json'], true);
  if (is_array($decoded)) { $ibox = $decoded; }
}
$INFOBOX_FIELDS = [
  'category'     => 'Category',
  'numeric_id'   => 'Numeric ID',
  'stackable'    => 'Stackable',
  'rarity'       => 'Rarity',
  'weight'       => 'Weight',
  'refined_into' => 'Refined Into',
];

// categories (chips)
$pageCats = [];
if ($page) {
  try {
    $stmt = $pdo->prepare("
      SELECT c.id, c.name, c.slug
      FROM wiki_page_categories pc
      JOIN wiki_categories c ON c.id = pc.category_id
      WHERE pc.page_id = ?
      ORDER BY c.name ASC
    ");
    $stmt->execute([(int)$page['id']]);
    $pageCats = $stmt->fetchAll();
  } catch (Throwable $e) { $pageCats = []; }
}

/* Media base for images uploaded via Admin */
$MEDIA_URL = $asset('wiki/media');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= $page ? htmlspecialchars($page['title']) . ' — Eldvar Wiki' : 'Page not found — Eldvar Wiki' ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="<?= htmlspecialchars($asset('public/css/style.css')) ?>">
  <style>
    .wiki-article { position: relative; }

    .infobox {
      float: right;
      width: 320px;
      background: var(--panel-2);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 12px;
      margin: 0 0 20px 20px;
    }
    .infobox-header {
      background: linear-gradient(180deg, #252117, #1e1b14);
      border: 1px solid rgba(255,200,120,0.15);
      border-radius: 8px;
      padding: 10px 12px;
      text-align: center;
      font-weight: 700;
      margin-bottom: 10px;
    }
    .infobox img {
      max-width: 64px;
      max-height: 64px;
      display: block;
      margin: 8px auto 10px;
      image-rendering: pixelated;
      border-radius: 6px;
      border: 1px solid var(--border);
      background: var(--panel);
    }
    .infobox table { width: 100%; border-collapse: collapse; }
    .infobox td {
      border-bottom: 1px solid var(--border);
      padding: 6px 6px;
      vertical-align: top;
      font-size: 14px;
    }
    .infobox td:first-child {
      width: 40%;
      color: var(--muted);
      font-weight: 600;
    }

    .cat-chips { display:flex; flex-wrap:wrap; gap:6px; margin:8px 0 12px; }
    .chip {
      display:inline-block; padding:4px 8px; border:1px solid var(--border);
      border-radius:999px; background:var(--panel-2); color:var(--text);
      text-decoration:none; font-size:12px;
    }
    .chip:hover { filter: brightness(1.06); }

    .wiki-cover {
      display:block; max-width: 100%; height:auto;
      border:1px solid var(--border); border-radius:10px; margin: 8px 0 14px;
      background: var(--panel-2);
    }

    @media (max-width: 980px) {
      .infobox { float: none; width: 100%; margin: 0 0 16px 0; }
    }
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <main class="app-main">
      <article class="wiki-article">

        <?php if ($page): ?>

          <!-- Right-side infobox -->
          <aside class="infobox">
            <div class="infobox-header"><?= htmlspecialchars($page['title']) ?></div>

            <?php if (!empty($page['image_path'])): ?>
              <img
                src="<?= $MEDIA_URL . '/' . rawurlencode($page['image_path']) ?>"
                alt="<?= htmlspecialchars($page['title']) ?>">
            <?php endif; ?>

            <table>
              <tbody>
              <?php foreach ($INFOBOX_FIELDS as $key => $label): ?>
                <?php if (!empty($ibox[$key])): ?>
                  <tr>
                    <td><?= htmlspecialchars($label) ?></td>
                    <td><?= htmlspecialchars((string)$ibox[$key]) ?></td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
              </tbody>
            </table>
          </aside>

          <h1 class="pixel-title" style="margin-top:0;">
            <?= htmlspecialchars($page['title']) ?>
            <?php if (($page['status'] ?? '') === 'draft'): ?>
              <span class="badge" style="margin-left:8px;">Draft preview</span>
            <?php endif; ?>
          </h1>

          <!-- Category chips (if any) -->
          <?php if ($pageCats): ?>
            <div class="cat-chips">
              <?php foreach ($pageCats as $c): ?>
                <a class="chip" href="<?= $asset('wiki/category.php?slug=' . urlencode($c['slug'])) ?>">
                  <?= htmlspecialchars($c['name']) ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="muted" style="font-size:12px;margin-bottom:14px;">
            Slug: <code><?= htmlspecialchars($page['slug']) ?></code> •
            Updated: <?= htmlspecialchars($page['updated_at']) ?> •
            Status: <?= htmlspecialchars($page['status']) ?>
            <?php if (is_wiki_editor()): ?>
              • <a class="btn" href="<?= $asset('wiki/admin/index.php?edit=' . (int)$page['id']) ?>">Edit</a>
            <?php endif; ?>
          </div>

          <div><?= render_content($page['content']) ?></div>

        <?php else: ?>
          <h1>Not found</h1>
          <p class="muted">That page is not published or does not exist.</p>
          <p>
            <a class="btn" href="<?= $asset('wiki/') ?>">Back to Wiki</a>
            <?php if (is_wiki_editor()): ?>
              <a class="btn" href="<?= $asset('wiki/admin/') ?>">Open Wiki Admin</a>
            <?php endif; ?>
          </p>
        <?php endif; ?>

      </article>
    </main>
    <?php include __DIR__ . '/../includes/footer.php'; ?>
  </div>
</body>
</html>
