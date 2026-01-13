<?php
declare(strict_types=1);

// /wiki/admin/index.php
require __DIR__ . '/../../config/config.php';
require __DIR__ . '/../../config/db.php';
require __DIR__ . '/../../includes/auth.php'; // <-- added
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Require login + wiki-editor role
if (!is_logged_in() || !is_wiki_editor()) {
  http_response_code(403);
  echo 'Forbidden — wiki editing is restricted to Librarians and Admins.';
  exit;
}

$pdo = get_pdo();

/* -------- bootstrap tables (pages, categories, pivots) -------- */
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

/* -------- CSRF -------- */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

/* -------- helpers -------- */
function clean(string $s): string { return trim($s); }
function slugify(string $t): string {
  $s = strtolower($t);
  $s = preg_replace('/[^a-z0-9]+/i', '-', $s) ?? '';
  return trim($s, '-') ?: uniqid('page-');
}
function redirect_self(): never {
  header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?'));
  exit;
}
$MEDIA_DIR = __DIR__ . '/../media';
$MEDIA_URL = BASE_URL . '/wiki/media';
if (!is_dir($MEDIA_DIR)) { @mkdir($MEDIA_DIR, 0755, true); }

function handle_upload(?array $file, string $mediaDir): array {
  if (!$file || !isset($file['tmp_name']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
    return ['ok'=>true, 'path'=>null, 'error'=>null];
  }
  if ($file['error'] !== UPLOAD_ERR_OK) {
    return ['ok'=>false, 'path'=>null, 'error'=>'Upload failed (code '.$file['error'].').'];
  }
  if ($file['size'] > 4*1024*1024) {
    return ['ok'=>false,'path'=>null,'error'=>'File too large (max 4MB).'];
  }
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime  = $finfo->file($file['tmp_name']) ?: '';
  $extMap = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'];
  if (!isset($extMap[$mime])) {
    return ['ok'=>false,'path'=>null,'error'=>'Unsupported file type.'];
  }
  $ext = $extMap[$mime];
  $name = bin2hex(random_bytes(8)) . '.' . $ext;
  $dest = rtrim($mediaDir,'/') . '/' . $name;
  if (!move_uploaded_file($file['tmp_name'], $dest)) {
    return ['ok'=>false, 'path'=>null, 'error'=>'Could not move uploaded file.'];
  }
  @chmod($dest, 0644);
  return ['ok'=>true, 'path'=>$name, 'error'=>null];
}

/* -------- messages -------- */
$notice = '';
$error  = '';

/* =========================================================
   CATEGORY CREATE / DELETE
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
  $kind = $_POST['kind'] ?? '';
  try {
    if ($kind === 'cat-create') {
      $name = clean($_POST['name'] ?? '');
      $slug = clean($_POST['slug'] ?? '');
      $desc = clean($_POST['description'] ?? '');
      if ($name === '') throw new RuntimeException('Name is required.');
      if ($slug === '') $slug = slugify($name);

      $st = $pdo->prepare("INSERT INTO wiki_categories (slug, name, description) VALUES (?,?,?)");
      $st->execute([$slug, $name, $desc]);
      $notice = 'Category created.';
      redirect_self();

    } elseif ($kind === 'cat-delete') {
      $cid = (int)($_POST['category_id'] ?? 0);
      if ($cid <= 0) throw new RuntimeException('Invalid category id.');
      $pdo->prepare("DELETE FROM wiki_categories WHERE id = ?")->execute([$cid]);
      $notice = 'Category deleted.';
      redirect_self();
    }
  } catch (Throwable $e) {
    $error = APP_DEBUG ? $e->getMessage() : 'Operation failed.';
  }
}

/* =========================================================
   PAGE CREATE / UPDATE / DELETE
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
  $kind = $_POST['kind'] ?? ($kind ?? '');
  try {
    if ($kind === 'page-create' || $kind === 'page-update' || $kind === 'page-delete') {
      $action = $kind;

      if ($action === 'page-delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid page id.');
        // unlink image
        $st = $pdo->prepare("SELECT image_path FROM wiki_pages WHERE id = ?");
        $st->execute([$id]);
        $cur = $st->fetch();
        if ($cur && !empty($cur['image_path'])) { @unlink($MEDIA_DIR . '/' . $cur['image_path']); }
        $pdo->prepare("DELETE FROM wiki_pages WHERE id = ?")->execute([$id]);
        $notice = 'Page deleted.';
        redirect_self();
      }

      $title   = clean($_POST['title'] ?? '');
      $slug    = clean($_POST['slug'] ?? '');
      $status  = in_array($_POST['status'] ?? '', ['draft','published'], true) ? $_POST['status'] : 'draft';
      $content = $_POST['content'] ?? '';
      $cats    = array_map('intval', (array)($_POST['categories'] ?? []));

      if ($title === '' || $content === '') throw new RuntimeException('Title and content are required.');
      if ($slug === '') $slug = slugify($title);

      $removeImage = !empty($_POST['remove_image']);
      $upload = handle_upload($_FILES['image'] ?? null, $MEDIA_DIR);
      if (!$upload['ok']) throw new RuntimeException($upload['error'] ?? 'Upload error.');
      $newImagePath = $upload['path']; // null means no new upload

      if ($kind === 'page-create') {
        $st = $pdo->prepare("INSERT INTO wiki_pages (slug, title, content, image_path, status, author_id) VALUES (?,?,?,?,?,?)");
        $st->execute([$slug, $title, $content, $newImagePath, $status, $_SESSION['user_id'] ?? null]);
        $pageId = (int)$pdo->lastInsertId();

        // set categories
        if ($cats) {
          $ins = $pdo->prepare("INSERT INTO wiki_page_categories (page_id, category_id) VALUES (?,?)");
          foreach ($cats as $cid) { $ins->execute([$pageId, $cid]); }
        }
        $notice = 'Page created.';
        redirect_self();
      }

      if ($kind === 'page-update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid page id.');

        // current row (for image cleanup)
        $st = $pdo->prepare("SELECT image_path FROM wiki_pages WHERE id = ?");
        $st->execute([$id]);
        $cur = $st->fetch();

        $imageSql = ''; $imageParams = [];
        if ($removeImage) {
          if ($cur && !empty($cur['image_path'])) @unlink($MEDIA_DIR . '/' . $cur['image_path']);
          $imageSql = ', image_path = NULL';
        } elseif (!empty($newImagePath)) {
          if ($cur && !empty($cur['image_path'])) @unlink($MEDIA_DIR . '/' . $cur['image_path']);
          $imageSql = ', image_path = ?'; $imageParams[] = $newImagePath;
        }

        $sql = "UPDATE wiki_pages SET title=?, slug=?, content=?, status=? $imageSql WHERE id=?";
        $params = [$title, $slug, $content, $status, ...$imageParams, $id];
        $pdo->prepare($sql)->execute($params);

        // reset categories
        $pdo->prepare("DELETE FROM wiki_page_categories WHERE page_id = ?")->execute([$id]);
        if ($cats) {
          $ins = $pdo->prepare("INSERT INTO wiki_page_categories (page_id, category_id) VALUES (?,?)");
          foreach ($cats as $cid) { $ins->execute([$id, $cid]); }
        }
        $notice = 'Page updated.';
        redirect_self();
      }
    }
  } catch (Throwable $e) {
    $error = APP_DEBUG ? $e->getMessage() : 'Operation failed.';
  }
}

/* =========================================================
   LOAD lists for UI
   ========================================================= */

// for editor (all categories list)
$allCats = $pdo->query("SELECT id, name, slug FROM wiki_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// are we editing a page?
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit   = null;
$selectedCatIds = [];

if ($editId > 0) {
  $st = $pdo->prepare("SELECT * FROM wiki_pages WHERE id = ? LIMIT 1");
  $st->execute([$editId]);
  $edit = $st->fetch();

  if ($edit) {
    $selectedCatIds = $pdo->query("SELECT category_id FROM wiki_page_categories WHERE page_id = " . (int)$edit['id'])
                          ->fetchAll(PDO::FETCH_COLUMN);
  }
} else {
  // NEW page → if ?cat=<slug> is provided, pre-select it
  $prefillSlug = trim((string)($_GET['cat'] ?? ''));
  if ($prefillSlug !== '') {
    $st = $pdo->prepare("SELECT id FROM wiki_categories WHERE slug = ? LIMIT 1");
    $st->execute([$prefillSlug]);
    $cid = (int)($st->fetchColumn() ?: 0);
    if ($cid > 0) $selectedCatIds = [$cid];
  }
}

// auto-open create form if coming from category CTA
$openCreate = !$edit && (isset($_GET['new']) || isset($_GET['cat']));

// recent pages table
$pages = $pdo->query("
  SELECT id, title, slug, status, updated_at, image_path
  FROM wiki_pages
  ORDER BY updated_at DESC
  LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// categories table
$cats = $pdo->query("
  SELECT id, name, slug, description, created_at
  FROM wiki_categories
  ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$asset = fn(string $p) => rtrim(BASE_URL,'/').'/'.ltrim($p,'/');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Wiki Admin — Eldvar</title>
  <link rel="stylesheet" href="<?= $asset('public/css/style.css') ?>">
  <style>
    .admin-wrap{display:grid;gap:16px}
    .card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:16px}
    .muted{color:var(--muted)}
    .form-grid{display:grid;gap:12px;grid-template-columns:2fr 1fr}
    .form-row{display:flex;flex-direction:column;gap:6px}
    input[type="text"],input[type="file"],select,textarea{background:var(--panel-2);border:1px solid var(--border);border-radius:8px;padding:10px;color:var(--text);font:inherit}
    textarea{min-height:240px}
    .btn-row{display:flex;gap:8px;flex-wrap:wrap}
    table.tbl{width:100%;border-collapse:collapse}
    table.tbl th,table.tbl td{border:1px solid var(--border);padding:8px 10px;text-align:left}
    table.tbl th{background:var(--panel-2);color:var(--accent-2)}
    .thumb{max-height:40px;border-radius:6px;display:block}
    @media (max-width:960px){.form-grid{grid-template-columns:1fr}}
    details.accordion summary{list-style:none;cursor:pointer;padding:10px 12px;border:1px solid var(--border);background:var(--panel-2);border-radius:10px;font-weight:600}
    details.accordion[open] summary{border-bottom-left-radius:0;border-bottom-right-radius:0}
    details.accordion .body{border:1px solid var(--border);border-top:none;background:var(--panel);padding:14px;border-bottom-left-radius:10px;border-bottom-right-radius:10px;margin-top:-2px}
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/../../includes/header.php'; ?>
    <main class="app-main">
      <div class="admin-wrap">

        <?php if ($notice): ?><div class="card" style="border-color:rgba(119,221,119,.35)">✅ <?= htmlspecialchars($notice) ?></div><?php endif; ?>
        <?php if ($error):  ?><div class="card" style="border-color:rgba(200,80,80,.45)">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>

        <!-- Create / Edit Page -->
        <section class="card">
          <details class="accordion" <?= ($edit || $openCreate) ? 'open' : '' ?>>
            <summary><?= $edit ? 'Edit Page' : 'Create Page' ?></summary>
            <div class="body">
              <form method="post" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="kind" value="<?= $edit ? 'page-update' : 'page-create' ?>">
                <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

                <div class="form-grid">
                  <div class="form-col">
                    <div class="form-row">
                      <label>Title</label>
                      <input type="text" name="title" required value="<?= htmlspecialchars($edit['title'] ?? '') ?>">
                    </div>
                    <div class="form-row">
                      <label>Slug <span class="muted">(auto from title; can edit)</span></label>
                      <input type="text" name="slug" value="<?= htmlspecialchars($edit['slug'] ?? '') ?>">
                    </div>
                    <div class="form-row">
                      <label>Content</label>
                      <textarea name="content" required><?= htmlspecialchars($edit['content'] ?? '') ?></textarea>
                    </div>
                  </div>

                  <div class="form-col">
                    <div class="form-row">
                      <label>Status</label>
                      <?php $st = $edit['status'] ?? 'draft'; ?>
                      <select name="status">
                        <option value="draft"     <?= $st==='draft'?'selected':''; ?>>Draft</option>
                        <option value="published" <?= $st==='published'?'selected':''; ?>>Published</option>
                      </select>
                    </div>

                    <div class="form-row">
                      <label>Featured Image</label>
                      <input type="file" name="image" accept=".png,.jpg,.jpeg,.gif,.webp,image/png,image/jpeg,image/gif,image/webp">
                      <?php if (!empty($edit['image_path'])): ?>
                        <div class="muted" style="margin-top:6px">
                          Current:
                          <a href="<?= $MEDIA_URL . '/' . rawurlencode($edit['image_path']) ?>" target="_blank" rel="noopener">
                            <code><?= htmlspecialchars($edit['image_path']) ?></code>
                          </a>
                          <label style="margin-left:10px;">
                            <input type="checkbox" name="remove_image" value="1"> Remove image
                          </label>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div class="form-row">
                      <label>Categories <span class="muted">(multi-select)</span></label>
                      <select name="categories[]" multiple size="6">
                        <?php
                          $sel = array_map('intval', $selectedCatIds);
                          foreach ($allCats as $c) {
                            $isSel = in_array((int)$c['id'], $sel, true) ? 'selected' : '';
                            echo '<option value="'.(int)$c['id'].'" '.$isSel.'>'.htmlspecialchars($c['name']).'</option>';
                          }
                        ?>
                      </select>
                    </div>
                  </div>
                </div>

                <div class="btn-row" style="margin-top:10px">
                  <button class="btn primary" type="submit"><?= $edit ? 'Save Changes' : 'Create Page' ?></button>
                  <?php if ($edit): ?>
                    <button class="btn" type="submit" name="kind" value="page-delete" onclick="return confirm('Delete this page?')">Delete</button>
                    <a class="btn" href="<?= BASE_URL ?>/wiki/admin/">Cancel</a>
                    <a class="btn" href="<?= BASE_URL ?>/wiki/view.php?slug=<?= urlencode($edit['slug']) ?>" target="_blank">View</a>
                  <?php else: ?>
                    <button class="btn" type="reset">Reset</button>
                  <?php endif; ?>
                </div>
              </form>
            </div>
          </details>
        </section>

        <!-- Recent Pages -->
        <section class="card">
          <details class="accordion" open>
            <summary>Recent Pages</summary>
            <div class="body">
              <?php if (!$pages): ?>
                <p class="muted">No pages yet.</p>
              <?php else: ?>
                <table class="tbl">
                  <thead>
                    <tr>
                      <th>Image</th>
                      <th>Title</th>
                      <th>Slug</th>
                      <th>Status</th>
                      <th>Updated</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($pages as $p): ?>
                      <tr>
                        <td style="width:64px;text-align:center">
                          <?php if (!empty($p['image_path'])): ?>
                            <img class="thumb" src="<?= $MEDIA_URL . '/' . rawurlencode($p['image_path']) ?>" alt="">
                          <?php else: ?>
                            <span class="muted">—</span>
                          <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($p['title']) ?></td>
                        <td><code><?= htmlspecialchars($p['slug']) ?></code></td>
                        <td><?= $p['status']==='published' ? 'Published' : 'Draft' ?></td>
                        <td><?= htmlspecialchars($p['updated_at']) ?></td>
                        <td>
                          <a class="btn" href="<?= BASE_URL ?>/wiki/admin/?edit=<?= (int)$p['id'] ?>">Edit</a>
                          <a class="btn" href="<?= BASE_URL ?>/wiki/view.php?slug=<?= urlencode($p['slug']) ?>" target="_blank">View</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </details>
        </section>

        <!-- Manage Categories -->
        <section class="card">
          <details class="accordion">
            <summary>Manage Categories</summary>
            <div class="body">
              <form method="post" style="margin-bottom:12px">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="kind" value="cat-create">
                <div class="form-grid" style="grid-template-columns:2fr 1fr">
                  <div class="form-row">
                    <label>Name</label>
                    <input type="text" name="name" placeholder="e.g., Items" required>
                  </div>
                  <div class="form-row">
                    <label>Slug <span class="muted">(optional)</span></label>
                    <input type="text" name="slug" placeholder="items">
                  </div>
                  <div class="form-row" style="grid-column: 1 / -1;">
                    <label>Description <span class="muted">(optional)</span></label>
                    <textarea name="description" rows="3" placeholder="Short description of this category…"></textarea>
                  </div>
                </div>
                <div class="btn-row" style="margin-top:8px">
                  <button class="btn primary" type="submit">Create Category</button>
                  <button class="btn" type="reset">Reset</button>
                </div>
              </form>

              <?php if (!$cats): ?>
                <p class="muted">No categories yet.</p>
              <?php else: ?>
                <table class="tbl">
                  <thead>
                    <tr>
                      <th>Name</th>
                      <th>Slug</th>
                      <th>Description</th>
                      <th>Created</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($cats as $c): ?>
                      <tr>
                        <td><?= htmlspecialchars($c['name']) ?></td>
                        <td><code><?= htmlspecialchars($c['slug']) ?></code></td>
                        <td><?= htmlspecialchars($c['description'] ?? '') ?></td>
                        <td><?= htmlspecialchars($c['created_at']) ?></td>
                        <td style="white-space:nowrap">
                          <a class="btn" href="<?= BASE_URL ?>/wiki/category.php?slug=<?= urlencode($c['slug']) ?>" target="_blank">Open</a>
                          <form method="post" style="display:inline" onsubmit="return confirm('Delete this category? Pages will remain, but lose this category tag.');">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="kind" value="cat-delete">
                            <input type="hidden" name="category_id" value="<?= (int)$c['id'] ?>">
                            <button class="btn" type="submit">Delete</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </details>
        </section>

      </div>
    </main>

    <?php include __DIR__ . '/../../includes/footer.php'; ?>
  </div>

  <script>
    // auto-slug from title for page form
    (function(){
      const title = document.querySelector('input[name="title"]');
      const slug  = document.querySelector('input[name="slug"]');
      if (!title || !slug) return;
      const make = s => (s||'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
      title.addEventListener('input', ()=>{
        if (!slug.value || slug.dataset.fromTitle === '1') {
          slug.value = make(title.value); slug.dataset.fromTitle = '1';
        }
      });
    })();
  </script>
</body>
</html>
