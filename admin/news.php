<?php
declare(strict_types=1);

// /admin/news.php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';   // for current_user_role / require_roles
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ---------- Access control: librarians, admins, governors ---------- */
if (!function_exists('require_roles')) {
  // Fallback if your auth.php hasn’t been updated (allowed: librarian/admin/governor/govenor)
  $role = strtolower(current_user_role());
  if (!in_array($role, ['librarian','admin','governor','govenor'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
  }
} else {
  require_roles(['librarian','admin','governor','govenor']);
}

$pdo = get_pdo();

/* Make PDO a bit stricter/predictable */
try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* ------------------------------------------------------------------
   Ensure schema (create/migrate)
-------------------------------------------------------------------*/
try {
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

  // Defensive, in case an older table exists without these columns.
  try { $pdo->exec("ALTER TABLE news ADD COLUMN type   ENUM('news','patch') NOT NULL DEFAULT 'news'"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE news ADD COLUMN status ENUM('draft','published') NOT NULL DEFAULT 'draft'"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE news ADD COLUMN image_path VARCHAR(255) NULL"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE news ADD COLUMN slug VARCHAR(190) NOT NULL UNIQUE"); } catch (Throwable $e) {}
} catch (Throwable $e) {
  http_response_code(500);
  echo "<!doctype html><meta charset='utf-8'><title>News Admin error</title><pre>"
     . htmlspecialchars($e->getMessage()) . "</pre>";
  exit;
}

/* ------------------------------------------------------------------
   CSRF
-------------------------------------------------------------------*/
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

/* ------------------------------------------------------------------
   Helpers
-------------------------------------------------------------------*/
function clean(string $s): string { return trim($s); }
function slugify(string $t): string {
  $s = strtolower($t);
  $s = preg_replace('/[^a-z0-9]+/i', '-', $s) ?? '';
  return trim($s, '-') ?: uniqid('post-');
}
function redirect_self(): never {
  header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? 'news.php', '?'));
  exit;
}

// Media dir for featured images: /news/media
$MEDIA_DIR = __DIR__ . '/../news/media';
$MEDIA_URL = rtrim(BASE_URL, '/') . '/news/media';
if (!is_dir($MEDIA_DIR)) { @mkdir($MEDIA_DIR, 0755, true); }

function handle_upload(?array $file, string $mediaDir): array {
  if (!$file || !isset($file['tmp_name']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
    return ['ok'=>true, 'path'=>null, 'error'=>null];
  }
  if ($file['error'] !== UPLOAD_ERR_OK) {
    return ['ok'=>false, 'path'=>null, 'error'=>'Upload failed (code '.$file['error'].').'];
  }
  if ($file['size'] > 5*1024*1024) {
    return ['ok'=>false, 'path'=>null, 'error'=>'File too large (max 5MB).'];
  }
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime  = $finfo->file($file['tmp_name']) ?: '';
  $extMap = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'];
  if (!isset($extMap[$mime])) {
    return ['ok'=>false, 'path'=>null, 'error'=>'Unsupported file type.'];
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

/* ------------------------------------------------------------------
   Mutations
-------------------------------------------------------------------*/
$notice = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf']) && hash_equals($csrf, $_POST['csrf'])) {
  $kind = $_POST['kind'] ?? '';
  try {
    if ($kind === 'create' || $kind === 'update' || $kind === 'delete') {
      if ($kind === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid post id.');
        // remove image
        $st = $pdo->prepare("SELECT image_path FROM news WHERE id = ?");
        $st->execute([$id]);
        $cur = $st->fetch();
        if ($cur && !empty($cur['image_path'])) { @unlink($MEDIA_DIR . '/' . $cur['image_path']); }
        $pdo->prepare("DELETE FROM news WHERE id = ?")->execute([$id]);
        $notice = 'Post deleted.';
        redirect_self();
      }

      // shared fields
      $title  = clean($_POST['title'] ?? '');
      $slug   = clean($_POST['slug'] ?? '');
      $type   = in_array($_POST['type'] ?? '', ['news','patch'], true) ? $_POST['type'] : 'news';
      $status = in_array($_POST['status'] ?? '', ['draft','published'], true) ? $_POST['status'] : 'draft';
      $body   = (string)($_POST['body'] ?? '');
      $removeImage = !empty($_POST['remove_image']);

      if ($title === '' || $body === '') throw new RuntimeException('Title and body are required.');
      if ($slug === '') $slug = slugify($title);

      $upload = handle_upload($_FILES['image'] ?? null, $MEDIA_DIR);
      if (!$upload['ok']) throw new RuntimeException($upload['error'] ?? 'Upload error.');
      $newImagePath = $upload['path']; // null if no new upload

      if ($kind === 'create') {
        $st = $pdo->prepare("
          INSERT INTO news (title, slug, body, type, status, image_path, author_id)
          VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $st->execute([$title, $slug, $body, $type, $status, $newImagePath, $_SESSION['user_id'] ?? null]);
        $notice = ucfirst($type) . ' post created.';
        redirect_self();
      }

      if ($kind === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid post id.');

        // current row (for image cleanup)
        $st = $pdo->prepare("SELECT image_path FROM news WHERE id = ?");
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

        $sql = "UPDATE news SET title=?, slug=?, body=?, type=?, status=? $imageSql WHERE id=?";
        $params = [$title, $slug, $body, $type, $status, ...$imageParams, $id];
        $pdo->prepare($sql)->execute($params);

        $notice = 'Post updated.';
        redirect_self();
      }
    }
  } catch (Throwable $e) {
    $error = APP_DEBUG ? $e->getMessage() : 'Operation failed.';
  }
}

/* ------------------------------------------------------------------
   Filters / search / pagination
-------------------------------------------------------------------*/
$q       = clean($_GET['q'] ?? '');
$f_type  = $_GET['type']   ?? ''; // '', news, patch
$f_stat  = $_GET['status'] ?? ''; // '', draft, published

$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$where   = [];
$params  = [];

if ($q !== '') {
  $where[] = "(n.title LIKE :q OR n.body LIKE :q OR n.slug LIKE :q)";
  $params[':q'] = "%$q%";
}
if (in_array($f_type, ['news','patch'], true)) {
  $where[] = "n.type = :t";
  $params[':t'] = $f_type;
}
if (in_array($f_stat, ['draft','published'], true)) {
  $where[] = "n.status = :s";
  $params[':s'] = $f_stat;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* Count */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM news n $whereSql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

/* Rows */
$sql = "
  SELECT n.id, n.title, n.slug, n.type, n.status, n.image_path, n.created_at, n.updated_at,
         u.username AS author
  FROM news n
  LEFT JOIN users u ON u.id = n.author_id
  $whereSql
  ORDER BY n.created_at DESC
  LIMIT " . (int)$offset . ", " . (int)$perPage;

$stmt = $pdo->prepare($sql);
foreach ($params as $k=>$v) { $stmt->bindValue($k, $v, PDO::PARAM_STR); }
$stmt->execute();
$rows = $stmt->fetchAll();

/* Edit? */
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit   = null;
if ($editId > 0) {
  $st = $pdo->prepare("SELECT * FROM news WHERE id = ? LIMIT 1");
  $st->execute([$editId]);
  $edit = $st->fetch();
}

/* ------------------------------------------------------------------
   Helpers for template
-------------------------------------------------------------------*/
$asset = fn(string $p) => rtrim(BASE_URL,'/') . '/' . ltrim($p,'/');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin — News & Patch Notes · Eldvar</title>
  <link rel="stylesheet" href="<?= $asset('public/css/style.css') ?>">
  <style>
    .acp-wrap{display:grid; gap:16px;}
    .card{background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:16px;}
    .muted{color:var(--muted);}

    .form-grid{display:grid; gap:12px; grid-template-columns: 2fr 1fr;}
    .form-row{display:flex; flex-direction:column; gap:6px;}
    input[type="text"], input[type="file"], select, textarea{
      background:var(--panel-2); border:1px solid var(--border); border-radius:8px; padding:10px; color:var(--text); font:inherit;
    }
    textarea{ min-height: 260px; }

    .btn-row{display:flex; gap:8px; flex-wrap:wrap;}

    .tbl{ width:100%; border-collapse:collapse; }
    .tbl th, .tbl td { border:1px solid var(--border); padding:8px 10px; text-align:left; }
    .tbl th { background:var(--panel-2); color:var(--accent-2); }

    .thumb{ width:56px; height:56px; object-fit:cover; border-radius:8px; border:1px solid var(--border); background:var(--panel-2); display:block; }

    details.acp-accordion summary{
      list-style:none; cursor:pointer; padding:10px 12px;
      border:1px solid var(--border); background:var(--panel-2);
      color:var(--text); border-radius:10px; font-weight:600;
    }
    details.acp-accordion[open] summary{ border-bottom-left-radius:0; border-bottom-right-radius:0; filter:brightness(1.04); }
    details.acp-accordion .accordion-body{
      border:1px solid var(--border); border-top:none; background:var(--panel);
      padding:14px; border-bottom-left-radius:10px; border-bottom-right-radius:10px; margin-top:-2px;
    }

    .filters{display:flex; gap:8px; align-items:center; flex-wrap:wrap;}
    .pagination{display:flex; gap:6px; align-items:center; justify-content:flex-end; flex-wrap:wrap;}
    .pagination a{border:1px solid var(--border); background:var(--panel-2); padding:6px 10px; border-radius:8px; text-decoration:none; color:var(--text);}
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <main class="app-main">
      <div class="acp-wrap">

        <!-- Top -->
        <section class="card">
          <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <h1 class="pixel-title" style="margin:0;">News & Patch Notes</h1>

            <form method="get" class="filters">
              <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search title, body, slug…">
              <select name="type">
                <option value="">All types</option>
                <option value="news"  <?= $f_type==='news'  ? 'selected':''; ?>>News</option>
                <option value="patch" <?= $f_type==='patch' ? 'selected':''; ?>>Patch Notes</option>
              </select>
              <select name="status">
                <option value="">Any status</option>
                <option value="draft"     <?= $f_stat==='draft'     ? 'selected':''; ?>>Draft</option>
                <option value="published" <?= $f_stat==='published' ? 'selected':''; ?>>Published</option>
              </select>
              <button class="btn" type="submit">Filter</button>
              <?php if ($q!=='' || $f_type!=='' || $f_stat!==''): ?>
                <a class="btn" href="<?= BASE_URL ?>/admin/news.php">Clear</a>
              <?php endif; ?>
            </form>
          </div>

          <?php if ($notice): ?><div class="card" style="margin-top:10px; border-color:rgba(119,221,119,.35)">✅ <?= htmlspecialchars($notice) ?></div><?php endif; ?>
          <?php if ($error):  ?><div class="card" style="margin-top:10px; border-color:rgba(200,80,80,.45)">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>
          <div class="muted" style="margin-top:8px;">Showing <?= count($rows) ?> of <?= (int)$total ?> posts.</div>
        </section>

        <!-- Create / Edit -->
        <?php $openForm = ($edit !== null) || ($error !== ''); ?>
        <section class="card">
          <details class="acp-accordion" <?= $openForm ? 'open' : '' ?>>
            <summary><?= $edit ? 'Edit Post' : 'Create Post' ?></summary>
            <div class="accordion-body">
              <form method="post" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="kind" value="<?= $edit ? 'update' : 'create' ?>">
                <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

                <div class="form-grid">
                  <div>
                    <div class="form-row">
                      <label>Title</label>
                      <input type="text" name="title" required value="<?= htmlspecialchars($edit['title'] ?? '') ?>">
                    </div>

                    <div class="form-row">
                      <label>Slug <span class="muted">(auto from title; can edit)</span></label>
                      <input type="text" name="slug" value="<?= htmlspecialchars($edit['slug'] ?? '') ?>">
                    </div>

                    <div class="form-row">
                      <label>Body (HTML allowed)</label>
                      <textarea name="body" required><?= htmlspecialchars($edit['body'] ?? '') ?></textarea>
                    </div>
                  </div>

                  <div>
                    <div class="form-row">
                      <label>Type</label>
                      <?php $tp = $edit['type'] ?? 'news'; ?>
                      <select name="type">
                        <option value="news"  <?= $tp==='news'  ? 'selected':''; ?>>News</option>
                        <option value="patch" <?= $tp==='patch' ? 'selected':''; ?>>Patch Notes</option>
                      </select>
                    </div>

                    <div class="form-row">
                      <label>Status</label>
                      <?php $st = $edit['status'] ?? 'draft'; ?>
                      <select name="status">
                        <option value="draft"     <?= $st==='draft'     ? 'selected':''; ?>>Draft</option>
                        <option value="published" <?= $st==='published' ? 'selected':''; ?>>Published</option>
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
                  </div>
                </div>

                <div class="btn-row" style="margin-top:10px;">
                  <button class="btn primary" type="submit"><?= $edit ? 'Save Changes' : 'Create Post' ?></button>
                  <?php if ($edit): ?>
                    <button class="btn" type="submit" name="kind" value="delete" onclick="return confirm('Delete this post?')">Delete</button>
                    <a class="btn" href="<?= BASE_URL ?>/admin/news.php">Cancel</a>
                    <a class="btn" href="<?= BASE_URL ?>/news_view.php?slug=<?= urlencode($edit['slug'] ?? '') ?>" target="_blank">View</a>
                  <?php else: ?>
                    <button class="btn" type="reset">Reset</button>
                  <?php endif; ?>
                </div>
              </form>
            </div>
          </details>
        </section>

        <!-- Table -->
        <section class="card">
          <details class="acp-accordion" open>
            <summary>Posts</summary>
            <div class="accordion-body">
              <?php if (!$rows): ?>
                <p class="muted">No posts found.</p>
              <?php else: ?>
                <table class="tbl">
                  <thead>
                    <tr>
                      <th>Image</th>
                      <th>Title</th>
                      <th>Type</th>
                      <th>Status</th>
                      <th>Author</th>
                      <th>Created</th>
                      <th>Updated</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $r): ?>
                      <tr>
                        <td style="width:64px;">
                          <?php if (!empty($r['image_path'])): ?>
                            <img class="thumb" src="<?= $MEDIA_URL . '/' . rawurlencode($r['image_path']) ?>" alt="">
                          <?php else: ?>
                            <span class="muted">—</span>
                          <?php endif; ?>
                        </td>
                        <td>
                          <strong><?= htmlspecialchars($r['title']) ?></strong><br>
                          <small class="muted"><code><?= htmlspecialchars($r['slug']) ?></code></small>
                        </td>
                        <td><?= htmlspecialchars(ucfirst($r['type'])) ?></td>
                        <td><?= htmlspecialchars(ucfirst($r['status'])) ?></td>
                        <td><?= htmlspecialchars($r['author'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($r['created_at']) ?></td>
                        <td><?= htmlspecialchars($r['updated_at']) ?></td>
                        <td style="white-space:nowrap;">
                          <a class="btn" href="<?= BASE_URL ?>/admin/news.php?edit=<?= (int)$r['id'] ?>">Edit</a>
                          <a class="btn" href="<?= BASE_URL ?>/news_view.php?slug=<?= urlencode($r['slug']) ?>" target="_blank">View</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>

                <div class="pagination" style="margin-top:10px;">
                  <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(['q'=>$q,'type'=>$f_type,'status'=>$f_stat,'page'=>$page-1]) ?>">Prev</a>
                  <?php endif; ?>
                  <span class="muted">Page <?= $page ?> / <?= $pages ?></span>
                  <?php if ($page < $pages): ?>
                    <a href="?<?= http_build_query(['q'=>$q,'type'=>$f_type,'status'=>$f_stat,'page'=>$page+1]) ?>">Next</a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </details>
        </section>

      </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
  </div>

  <script>
    // Auto-slug from title (only when slug not manually edited)
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
      slug.addEventListener('input', ()=>{ slug.dataset.fromTitle = '0'; });
    })();
  </script>
</body>
</html>
