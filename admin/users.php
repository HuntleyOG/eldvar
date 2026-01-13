<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/* ---------- Access Control (admin + governor/govenor) ---------- */
$actorId = $_SESSION['user_id'] ?? null;
if (!$actorId) { header('Location: ' . BASE_URL . '/login.php'); exit; }

$stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$actorId]);
$actor = $stmt->fetch();                   // ✅ single row
$actorRole = strtolower((string)($actor['role'] ?? 'player'));
if (!in_array($actorRole, ['admin','governor','govenor'], true)) {
  http_response_code(403); echo 'Forbidden'; exit;
}

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

/* ---------- Helpers ---------- */
function s(?string $v): string { return trim((string)$v); }
function role_options(): array { return ['player','supporter','helper','moderator','admin','governor']; }
function is_top_role(string $r): bool { return in_array(strtolower($r), ['governor','govenor'], true); }
function can_edit_role(string $actorRole, string $targetRole): bool {
  $a = strtolower($actorRole); $t = strtolower($targetRole);
  if (in_array($t, ['governor','govenor'], true) && $a !== 'governor') return false;
  return in_array($a, ['admin','governor'], true);
}
function redirect_self(): void {
  $url = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: 'users.php';
  header('Location: ' . $url);
  exit;
}

/* ---------- (Optional) ensure email column exists ---------- */
try {
  $probe = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'");
  if ($probe && !$probe->fetch()) {
    $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(190) NULL");
  }
} catch (Throwable $e) { /* ignore */ }

/* ---------- Mutations ---------- */
$notice = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    $error = 'Invalid CSRF token.';
  } else {
    $action = $_POST['action'] ?? '';

    try {
      if ($action === 'create') {
        $username = s($_POST['username'] ?? '');
        $email    = s($_POST['email'] ?? '');
        $display  = s($_POST['display_name'] ?? '');
        $role     = s($_POST['role'] ?? 'player');
        $level    = (int)($_POST['level'] ?? 1);
        $verified = !empty($_POST['verified']) ? 1 : 0;
        $status   = s($_POST['status_text'] ?? '');
        $bio      = s($_POST['bio'] ?? '');
        $avatar   = s($_POST['avatar_url'] ?? '');
        $banner   = s($_POST['banner_url'] ?? '');
        $passRaw  = (string)($_POST['password'] ?? '');

        if ($username === '' || $passRaw === '') throw new RuntimeException('Username and password are required.');
        if (!in_array($role, role_options(), true)) $role = 'player';

        $passHash = password_hash($passRaw, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
          INSERT INTO users (username,email,display_name,role,level,verified,status_text,bio,avatar_url,banner_url,password,created_at)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
        ");
        $stmt->execute([
          $username,
          $email ?: null,
          $display !== '' ? $display : $username,
          $role,
          $level,
          $verified,
          $status ?: null,
          $bio ?: null,
          $avatar ?: null,
          $banner ?: null,
          $passHash
        ]);

        $notice = 'User created.'; redirect_self();

      } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid user id.');

        /* load current target (for role rules) */
        $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $target = $stmt->fetch();                 // ✅ single row
        if (!$target) throw new RuntimeException('User not found.');

        $username = s($_POST['username'] ?? '');
        if ($username === '') throw new RuntimeException('Username is required.');

        $email    = s($_POST['email'] ?? '');
        $display  = s($_POST['display_name'] ?? '');
        $role     = s($_POST['role'] ?? (string)$target['role']);
        $level    = (int)($_POST['level'] ?? 1);
        $verified = !empty($_POST['verified']) ? 1 : 0;
        $status   = s($_POST['status_text'] ?? '');
        $bio      = s($_POST['bio'] ?? '');
        $avatar   = s($_POST['avatar_url'] ?? '');
        $banner   = s($_POST['banner_url'] ?? '');
        $passRaw  = (string)($_POST['password'] ?? '');

        /* role guardrails */
        if (!can_edit_role($actorRole, (string)$target['role'])) {
          $role = (string)$target['role'];      // not allowed to change
        }
        if ($id === (int)$actorId && is_top_role((string)$target['role']) && strtolower($role) !== strtolower((string)$target['role'])) {
          $c = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE LOWER(role) IN ('governor','govenor') AND id <> ".(int)$actorId)->fetchColumn();
          if ($c === 0) throw new RuntimeException('You are the only governor. Assign another governor first.');
        }
        if (!in_array($role, role_options(), true)) $role = (string)$target['role'];

        if ($passRaw !== '') {
          $passHash = password_hash($passRaw, PASSWORD_DEFAULT);
          $stmt = $pdo->prepare("
            UPDATE users
            SET username=?, email=?, display_name=?, role=?, level=?, verified=?, status_text=?, bio=?, avatar_url=?, banner_url=?, password=?
            WHERE id=?
          ");
          $stmt->execute([$username, $email ?: null, $display !== '' ? $display : $username, $role, $level, $verified, $status ?: null, $bio ?: null, $avatar ?: null, $banner ?: null, $passHash, $id]);
        } else {
          $stmt = $pdo->prepare("
            UPDATE users
            SET username=?, email=?, display_name=?, role=?, level=?, verified=?, status_text=?, bio=?, avatar_url=?, banner_url=?
            WHERE id=?
          ");
          $stmt->execute([$username, $email ?: null, $display !== '' ? $display : $username, $role, $level, $verified, $status ?: null, $bio ?: null, $avatar ?: null, $banner ?: null, $id]);
        }

        $notice = 'User updated.'; redirect_self();

      } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid user id.');
        if ($id === (int)$actorId) throw new RuntimeException('You cannot delete your own account.');

        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $targetRole = (string)($stmt->fetchColumn() ?: 'player');

        if (!can_edit_role($actorRole, $targetRole)) throw new RuntimeException('You cannot delete that account.');
        if (is_top_role($targetRole)) {
          $gov = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE LOWER(role) IN ('governor','govenor')")->fetchColumn();
          if ($gov <= 1) throw new RuntimeException('You cannot delete the last governor.');
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);

        $notice = 'User deleted.'; redirect_self();
      }
    } catch (Throwable $e) {
      $error = APP_DEBUG ? $e->getMessage() : 'Operation failed.';
    }
  }
}

/* ---------- Search + Pagination ---------- */
$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$search  = s($_GET['q'] ?? '');
$where   = '';
$params  = [];

if ($search !== '') {
  $where = "WHERE username LIKE :q OR display_name LIKE :q OR email LIKE :q";
  $params[':q'] = "%$search%";
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users $where");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$sql = "SELECT id, username, display_name, email, role, level, verified, created_at
        FROM users
        $where
        ORDER BY id DESC
        LIMIT :offset, :perPage";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v, PDO::PARAM_STR); }
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();                 // ✅ list rows

/* ---------- If editing ---------- */
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editUser = null;
if ($editId > 0) {
  $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
  $stmt->execute([$editId]);
  $editUser = $stmt->fetch();               // ✅ single row
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin — Users · Eldvar</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  <style>
    .wrap{display:grid;gap:16px}
    .card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:16px}
    .grid2{display:grid;gap:12px;grid-template-columns:repeat(2,minmax(0,1fr))}
    .row{display:flex;flex-direction:column;gap:6px}
    .row input,.row select,.row textarea{background:var(--panel-2);border:1px solid var(--border);border-radius:8px;padding:10px;color:var(--text)}
    .btns{display:flex;gap:8px;flex-wrap:wrap}
    table.users{width:100%;border-collapse:collapse}
    table.users th,table.users td{border:1px solid var(--border);padding:8px 10px;text-align:left}
    table.users th{background:var(--panel-2);color:var(--accent-2)}
    .pagination{display:flex;gap:6px;justify-content:flex-end;margin-top:10px}
    .pagination a{border:1px solid var(--border);background:var(--panel-2);padding:6px 10px;border-radius:8px;text-decoration:none;color:var(--text)}
    @media (max-width:900px){.grid2{grid-template-columns:1fr}}
  </style>
</head>
<body class="with-sidebar">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div id="backdrop" class="backdrop"></div>

<div class="app-shell">
  <?php include __DIR__ . '/../includes/header.php'; ?>

  <main class="app-main">
    <div class="wrap">

      <!-- Top bar: search + status -->
      <section class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
          <h1 class="pixel-title" style="margin:0">Users</h1>
          <form method="get" style="display:flex;gap:8px;align-items:center">
            <input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search username, display, email…">
            <button class="btn" type="submit">Search</button>
            <?php if ($search !== ''): ?>
              <a class="btn" href="<?= BASE_URL ?>/admin/users.php">Clear</a>
            <?php endif; ?>
          </form>
        </div>
        <?php if ($notice): ?><div class="notice" style="margin-top:8px"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error" style="margin-top:8px"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <div class="muted" style="margin-top:8px">Showing <?= count($users) ?> of <?= $total ?> users.</div>
      </section>

      <!-- Create / Edit -->
      <section class="card">
        <details <?= ($editUser || $error || $notice) ? 'open' : '' ?>>
          <summary style="cursor:pointer"> <?= $editUser ? 'Edit User' : 'Create User' ?> </summary>
          <div style="margin-top:12px">
            <form method="post" autocomplete="off">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <?php if ($editUser): ?><input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>"><?php endif; ?>

              <div class="grid2">
                <div class="row">
                  <label>Username</label>
                  <input name="username" required value="<?= htmlspecialchars($editUser['username'] ?? '') ?>">
                </div>
                <div class="row">
                  <label>Email <span class="muted">(optional)</span></label>
                  <input type="email" name="email" value="<?= htmlspecialchars($editUser['email'] ?? '') ?>">
                </div>
                <div class="row">
                  <label>Display Name</label>
                  <input name="display_name" value="<?= htmlspecialchars($editUser['display_name'] ?? '') ?>">
                </div>
                <div class="row">
                  <label>Role</label>
                  <select name="role">
                    <?php
                    $curRole = strtolower((string)($editUser['role'] ?? 'player'));
                    foreach (role_options() as $opt) {
                      $sel = ($curRole === strtolower($opt)) ? 'selected' : '';
                      echo '<option value="'.htmlspecialchars($opt).'" '.$sel.'>'.htmlspecialchars(ucfirst($opt)).'</option>';
                    }
                    ?>
                  </select>
                </div>
                <div class="row">
                  <label>Level</label>
                  <input type="number" name="level" min="1" value="<?= (int)($editUser['level'] ?? 1) ?>">
                </div>
                <div class="row">
                  <label>Verified</label>
                  <label style="display:flex;gap:8px;align-items:center">
                    <input type="checkbox" name="verified" value="1" <?= !empty($editUser['verified']) ? 'checked' : '' ?>>
                    <span class="muted">Show checkmark</span>
                  </label>
                </div>
                <div class="row">
                  <label>Status Text (short tagline)</label>
                  <input name="status_text" maxlength="140" value="<?= htmlspecialchars($editUser['status_text'] ?? '') ?>">
                </div>
                <div class="row">
                  <label>Avatar URL</label>
                  <input name="avatar_url" value="<?= htmlspecialchars($editUser['avatar_url'] ?? '') ?>">
                </div>
                <div class="row">
                  <label>Banner URL</label>
                  <input name="banner_url" value="<?= htmlspecialchars($editUser['banner_url'] ?? '') ?>">
                </div>
                <div class="row" style="grid-column:1/-1">
                  <label>Bio</label>
                  <textarea name="bio" rows="4"><?= htmlspecialchars($editUser['bio'] ?? '') ?></textarea>
                </div>
                <div class="row" style="grid-column:1/-1">
                  <label><?= $editUser ? 'New Password (leave blank to keep current)' : 'Password' ?></label>
                  <input type="password" name="password" <?= $editUser ? '' : 'required' ?>>
                </div>
              </div>

              <div class="btns" style="margin-top:10px">
                <?php if ($editUser): ?>
                  <button class="btn primary" type="submit" name="action" value="update">Save Changes</button>
                  <a class="btn" href="<?= BASE_URL ?>/admin/users.php">Cancel</a>
                  <button class="btn" type="submit" name="action" value="delete" onclick="return confirm('Delete this user?')">Delete</button>
                <?php else: ?>
                  <button class="btn primary" type="submit" name="action" value="create">Create User</button>
                  <button class="btn" type="reset">Reset</button>
                <?php endif; ?>
              </div>
            </form>
          </div>
        </details>
      </section>

      <!-- Users table -->
      <section class="card">
        <details open>
          <summary style="cursor:pointer">Users (<?= (int)$total ?>)</summary>
          <div style="margin-top:12px">
            <?php if (!$users): ?>
              <p class="muted">No users found.</p>
            <?php else: ?>
              <table class="users">
                <thead>
                <tr>
                  <th>ID</th>
                  <th>Username</th>
                  <th>Display</th>
                  <th>Email</th>
                  <th>Role</th>
                  <th>Lvl</th>
                  <th>Ver.</th>
                  <th>Joined</th>
                  <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                  <tr>
                    <td><?= (int)$u['id'] ?></td>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><?= htmlspecialchars($u['display_name'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                    <td><?= htmlspecialchars($u['role']) ?></td>
                    <td><?= (int)$u['level'] ?></td>
                    <td><?= !empty($u['verified']) ? '✔' : '—' ?></td>
                    <td><?= htmlspecialchars(date('Y-m-d', strtotime((string)$u['created_at']))) ?></td>
                    <td><a class="btn" href="<?= BASE_URL ?>/admin/users.php?edit=<?= (int)$u['id'] ?>">Edit</a></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>

              <div class="pagination">
                <?php if ($page > 1): ?>
                  <a href="?q=<?= urlencode($search) ?>&page=<?= $page-1 ?>">Prev</a>
                <?php endif; ?>
                <span class="muted">Page <?= $page ?> / <?= $pages ?></span>
                <?php if ($page < $pages): ?>
                  <a href="?q=<?= urlencode($search) ?>&page=<?= $page+1 ?>">Next</a>
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
</body>
</html>
