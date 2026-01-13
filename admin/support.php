<?php
declare(strict_types=1);

// /admin/support.php
// Hardened: global debug handler + defensive queries (no JOINs)

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_acp(); // admin/governor only

$DEBUG = APP_DEBUG || (isset($_GET['debug']) && $_GET['debug'] == '1');

/* ---------- Debug helpers: turn all notices into exceptions when debug=1 ---------- */
if ($DEBUG) {
  set_error_handler(function(int $severity, string $message, string $file = '', int $line = 0) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
  });
  set_exception_handler(function($e){
    http_response_code(500);
    echo "<!doctype html><meta charset='utf-8'><title>Admin · Support — Error</title>";
    echo "<pre style='background:#201f2b;color:#d6d6ff;padding:12px;border:1px dashed #6c6cff;border-radius:8px;white-space:pre-wrap'>";
    echo htmlspecialchars($e->getMessage()."\n\n".$e->getFile().':'.$e->getLine(), ENT_QUOTES, 'UTF-8');
    echo "</pre>";
    exit;
  });
  register_shutdown_function(function(){
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
      http_response_code(500);
      echo "<!doctype html><meta charset='utf-8'><title>Admin · Support — Fatal</title>";
      echo "<pre style='background:#201f2b;color:#ffd6d6;padding:12px;border:1px dashed #ff6c6c;border-radius:8px;white-space:pre-wrap'>";
      echo htmlspecialchars($err['message']."\n\n".$err['file'].':'.$err['line'], ENT_QUOTES, 'UTF-8');
      echo "</pre>";
    }
  });
}

/* ---------- PDO ---------- */
$pdo = get_pdo();
try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  if ($DEBUG) { throw $e; }
}

/* ---------- Utils ---------- */
$h     = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$asset = fn(string $p) => rtrim(BASE_URL,'/') . '/' . ltrim($p,'/');
$meId  = $_SESSION['user_id'] ?? null;
$meRole= current_user_role();

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

/* ---------- Flash ---------- */
$ok = ''; $err = '';

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
      throw new RuntimeException('Invalid CSRF. Refresh and try again.');
    }
    $action   = (string)($_POST['action'] ?? '');
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId <= 0) throw new RuntimeException('Invalid ticket.');

    // Ensure ticket exists
    $t = $pdo->prepare("SELECT id FROM support_tickets WHERE id = ? LIMIT 1");
    $t->execute([$ticketId]);
    if (!$t->fetch()) throw new RuntimeException('Ticket not found.');

    if ($action === 'add_reply') {
      $msg = trim((string)($_POST['message'] ?? ''));
      if ($msg === '') throw new RuntimeException('Reply cannot be empty.');
      $pdo->prepare("INSERT INTO support_replies (ticket_id, user_id, author_role, message) VALUES (?, ?, ?, ?)")
          ->execute([$ticketId, $meId, $meRole, $msg]);
      $pdo->prepare("UPDATE support_tickets SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")
          ->execute([$ticketId]);
      $ok = 'Reply posted.';
    } elseif ($action === 'add_note') {
      $note = trim((string)($_POST['note'] ?? ''));
      if ($note === '') throw new RuntimeException('Note cannot be empty.');
      $pdo->prepare("INSERT INTO support_notes (ticket_id, staff_id, note) VALUES (?, ?, ?)")
          ->execute([$ticketId, $meId, $note]);
      $pdo->prepare("UPDATE support_tickets SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")
          ->execute([$ticketId]);
      $ok = 'Internal note added.';
    } elseif ($action === 'set_status') {
      $status = (string)($_POST['status'] ?? 'open');
      if (!in_array($status, ['open','closed'], true)) $status = 'open';
      $pdo->prepare("UPDATE support_tickets SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
          ->execute([$status, $ticketId]);
      $ok = 'Status updated.';
    } else {
      throw new RuntimeException('Unknown action.');
    }
  } catch (Throwable $e) {
    $err = $DEBUG ? $e->getMessage() : 'Action failed.';
  }
}

/* ---------- Routing & filters ---------- */
$viewId  = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$status  = isset($_GET['status']) && in_array($_GET['status'], ['open','closed','all'], true) ? $_GET['status'] : 'open';
$category= isset($_GET['category']) && in_array($_GET['category'], ['account','bug','payment','appeal','other','all'], true) ? $_GET['category'] : 'all';
$search  = trim((string)($_GET['q'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

/* ---------- Fetch list or detail ---------- */
$list = []; $total = 0; $ticket = null; $replies = []; $notes = []; $detailErr = '';

if ($viewId <= 0) {
  try {
    $where = []; $args = [];
    if ($status !== 'all')   { $where[] = "status = ?";   $args[] = $status; }
    if ($category !== 'all') { $where[] = "category = ?"; $args[] = $category; }
    if ($search !== '') {
      $where[] = "(subject LIKE ? OR message LIKE ? OR email LIKE ?)";
      $like = '%'.$search.'%';
      array_push($args, $like, $like, $like);
    }
    $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

    $c = $pdo->prepare("SELECT COUNT(*) FROM support_tickets {$whereSql}");
    $c->execute($args);
    $total = (int)$c->fetchColumn();

    $q = $pdo->prepare("
      SELECT id, subject, email, category, status, created_at, updated_at, user_id
      FROM support_tickets
      {$whereSql}
      ORDER BY (status='open') DESC, updated_at DESC
      LIMIT {$perPage} OFFSET {$offset}
    ");
    $q->execute($args);
    $list = $q->fetchAll();
  } catch (Throwable $e) {
    if ($DEBUG) throw $e;
    $err = 'Could not load tickets.';
  }
} else {
  // Detail
  try {
    $q = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ? LIMIT 1");
    $q->execute([$viewId]);
    $ticket = $q->fetch();
    if (!$ticket) { $detailErr = 'Ticket not found.'; }
  } catch (Throwable $e) {
    $detailErr = $DEBUG ? ('Ticket query failed: '.$e->getMessage()) : 'Could not load ticket.';
  }

  if ($ticket && !$detailErr) {
    try {
      $r = $pdo->prepare("
        SELECT id, ticket_id, user_id, author_role, message, created_at
        FROM support_replies
        WHERE ticket_id = ?
        ORDER BY created_at ASC, id ASC
      ");
      $r->execute([$viewId]);
      $replies = $r->fetchAll();

      // Resolve usernames for replies (no JOINs)
      $ids = array_values(array_unique(array_filter(array_map(fn($x)=> (int)($x['user_id'] ?? 0), $replies))));
      $nameById = [];
      if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $ur = $pdo->prepare("SELECT id, username FROM users WHERE id IN ($ph)");
        $ur->execute($ids);
        foreach ($ur->fetchAll() as $u) { $nameById[(int)$u['id']] = (string)$u['username']; }
      }
      foreach ($replies as &$R) {
        $uid = (int)($R['user_id'] ?? 0);
        $R['_username'] = $uid && isset($nameById[$uid]) ? $nameById[$uid] : null;
      }
      unset($R);
    } catch (Throwable $e) {
      $replies = [];
      if ($DEBUG) { $detailErr = 'Replies query failed: '.$e->getMessage(); }
    }

    try {
      $n = $pdo->prepare("
        SELECT id, ticket_id, staff_id, note, created_at
        FROM support_notes
        WHERE ticket_id = ?
        ORDER BY created_at ASC, id ASC
      ");
      $n->execute([$viewId]);
      $notes = $n->fetchAll();

      // Resolve usernames for notes (no JOINs)
      $ids = array_values(array_unique(array_filter(array_map(fn($x)=> (int)($x['staff_id'] ?? 0), $notes))));
      $nameById = [];
      if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $ur = $pdo->prepare("SELECT id, username FROM users WHERE id IN ($ph)");
        $ur->execute($ids);
        foreach ($ur->fetchAll() as $u) { $nameById[(int)$u['id']] = (string)$u['username']; }
      }
      foreach ($notes as &$N) {
        $sid = (int)($N['staff_id'] ?? 0);
        $N['_username'] = $sid && isset($nameById[$sid]) ? $nameById[$sid] : null;
      }
      unset($N);
    } catch (Throwable $e) {
      $notes = [];
      if ($DEBUG) { $detailErr = 'Notes query failed: '.$e->getMessage(); }
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin · Support — Eldvar</title>
  <link rel="stylesheet" href="<?= $asset('public/css/style.css') ?>">
  <style>
    .card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:16px}
    .muted{color:var(--muted)}
    .pill{display:inline-block;padding:2px 8px;border:1px solid var(--border);border-radius:999px;font-size:12px}
    .pill.open{color:#a0e0ff;border-color:rgba(160,224,255,.35)}
    .pill.closed{color:#9fe7a9;border-color:rgba(119,221,119,.35)}
    .tbl{width:100%;border-collapse:collapse}
    .tbl th,.tbl td{border:1px solid var(--border);padding:8px 10px;text-align:left}
    .tbl th{background:var(--panel-2);color:var(--accent-2)}
    .grid-2{display:grid;gap:16px;grid-template-columns:2fr 1fr}
    @media (max-width: 1100px){ .grid-2{grid-template-columns:1fr} }
    .form-row{display:flex;flex-direction:column;gap:6px}
    input[type="text"], textarea, select{background:var(--panel-2);border:1px solid var(--border);border-radius:8px;padding:10px;color:var(--text);font:inherit}
    textarea{min-height:140px}
    .btn-row{display:flex;gap:8px;flex-wrap:wrap}
    .flash-ok{border:1px solid rgba(119,221,119,.35);background:rgba(119,221,119,.08);border-radius:10px;padding:10px}
    .flash-err{border:1px solid rgba(220,80,80,.4);background:rgba(220,80,80,.08);border-radius:10px;padding:10px}
    .kvs{display:grid;grid-template-columns:120px 1fr;gap:8px}
    .kvs div{padding:6px 8px;border:1px solid var(--border);border-radius:8px;background:var(--panel-2)}
    .bubble{background:var(--panel-2);border:1px solid var(--border);border-radius:10px;padding:10px 12px}
    <?php if ($DEBUG): ?>
    .dbg{white-space:pre-wrap;background:#201f2b;border:1px dashed #6c6cff;color:#d6d6ff;padding:10px;border-radius:8px;margin-top:10px}
    <?php endif; ?>
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <main class="app-main">
      <section class="card">
        <h1 class="pixel-title" style="margin:0">Admin — Support</h1>
        <p class="muted" style="margin:.4rem 0 0">Review tickets, post staff replies, and add internal notes.</p>
        <?php if ($ok): ?><div class="flash-ok" style="margin-top:10px">✅ <?= $h($ok) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="flash-err" style="margin-top:10px">⚠ <?= $h($err) ?></div><?php endif; ?>
        <?php if ($DEBUG): ?><div class="dbg">Debug mode is ON (via <?= APP_DEBUG ? 'APP_DEBUG' : 'debug=1' ?>)</div><?php endif; ?>
      </section>

      <?php if ($viewId <= 0): ?>
        <!-- ===== LIST VIEW ===== -->
        <section class="card">
          <form method="get" class="btn-row" style="align-items:center">
            <input type="hidden" name="page" value="1">
            <label>Status
              <select name="status">
                <option value="open"   <?= $status==='open'?'selected':''; ?>>Open</option>
                <option value="closed" <?= $status==='closed'?'selected':''; ?>>Closed</option>
                <option value="all"    <?= $status==='all'?'selected':''; ?>>All</option>
              </select>
            </label>
            <label>Category
              <select name="category">
                <option value="all"     <?= $category==='all'?'selected':''; ?>>All</option>
                <option value="account" <?= $category==='account'?'selected':''; ?>>Account</option>
                <option value="bug"     <?= $category==='bug'?'selected':''; ?>>Bug</option>
                <option value="payment" <?= $category==='payment'?'selected':''; ?>>Payment</option>
                <option value="appeal"  <?= $category==='appeal'?'selected':''; ?>>Appeal</option>
                <option value="other"   <?= $category==='other'?'selected':''; ?>>Other</option>
              </select>
            </label>
            <input type="text" name="q" value="<?= $h($search) ?>" placeholder="Search subject/message/email…" style="min-width:260px">
            <button class="btn" type="submit">Filter</button>
            <a class="btn" href="<?= $h(BASE_URL.'/admin/support.php'.($DEBUG?'?debug=1':'')) ?>">Reset</a>
            <span class="muted" style="margin-left:auto"><?= (int)$total ?> result<?= $total===1?'':'s' ?></span>
          </form>

          <?php if (!$list): ?>
            <p class="muted" style="margin:0">No tickets found.</p>
          <?php else: ?>
            <div style="overflow:auto">
              <table class="tbl">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Subject</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>User</th>
                    <th>Email</th>
                    <th>Created</th>
                    <th>Updated</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($list as $row): ?>
                  <tr>
                    <td><?= (int)$row['id'] ?></td>
                    <td><?= $h($row['subject']) ?></td>
                    <td><?= ucfirst($h($row['category'])) ?></td>
                    <td><span class="pill <?= $row['status']==='open'?'open':'closed' ?>"><?= ucfirst($h($row['status'])) ?></span></td>
                    <td><?= $row['user_id'] ? ('#'.(int)$row['user_id']) : '<span class="muted">guest</span>' ?></td>
                    <td><?= $h($row['email'] ?? '') ?></td>
                    <td><?= $h($row['created_at']) ?></td>
                    <td><?= $h($row['updated_at']) ?></td>
                    <td><a class="btn" href="<?= $h(BASE_URL.'/admin/support.php?view='.$row['id'] . ($DEBUG ? '&debug=1' : '')) ?>">Open</a></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <?php
              $pages = max(1, (int)ceil($total / $perPage));
              if ($pages > 1):
                $qs = $_GET; unset($qs['page']);
                $base = BASE_URL.'/admin/support.php?'.http_build_query($qs);
            ?>
              <div class="btn-row" style="justify-content:flex-end;margin-top:12px">
                <?php if ($page > 1): ?>
                  <a class="btn" href="<?= $h($base.'&page='.($page-1)) ?>">&laquo; Prev</a>
                <?php endif; ?>
                <span class="muted">Page <?= $page ?> / <?= $pages ?></span>
                <?php if ($page < $pages): ?>
                  <a class="btn" href="<?= $h($base.'&page='.($page+1)) ?>">Next &raquo;</a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
        </section>

      <?php else: ?>
        <!-- ===== DETAIL VIEW ===== -->
        <?php if ($detailErr): ?>
          <section class="card">
            <p class="muted">⚠ <?= $h($detailErr) ?></p>
            <?php if ($DEBUG): ?><div class="dbg"><?= $h($detailErr) ?></div><?php endif; ?>
            <p><a class="btn" href="<?= $h(BASE_URL.'/admin/support.php'.($DEBUG?'?debug=1':'')) ?>">&larr; Back</a></p>
          </section>
        <?php elseif (!$ticket): ?>
          <section class="card"><p class="muted">Ticket not found.</p></section>
        <?php else: ?>
          <div class="grid-2">
            <section class="card">
              <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <a class="btn" href="<?= $h(BASE_URL.'/admin/support.php'.($DEBUG?'?debug=1':'')) ?>">&larr; Back</a>
                <h2 style="margin:0"><?= $h($ticket['subject']) ?></h2>
                <span class="pill <?= $ticket['status']==='open'?'open':'closed' ?>"><?= ucfirst($h($ticket['status'])) ?></span>
                <span class="pill"><?= ucfirst($h($ticket['category'])) ?></span>
              </div>

              <div class="kvs" style="margin-top:12px">
                <div class="muted">Ticket ID</div><div>#<?= (int)$ticket['id'] ?></div>
                <div class="muted">From</div><div><?= $ticket['user_id'] ? ('User #'.(int)$ticket['user_id']) : ($ticket['email'] ? $h($ticket['email']) : '<em>guest</em>') ?></div>
                <div class="muted">Created</div><div><?= $h($ticket['created_at']) ?></div>
                <div class="muted">Updated</div><div><?= $h($ticket['updated_at']) ?></div>
              </div>

              <h3 style="margin-top:16px">Original Message</h3>
              <div class="bubble"><?= nl2br($h($ticket['message'])) ?></div>

              <?php if ($replies): ?>
                <h3 style="margin-top:16px">Conversation</h3>
                <div style="display:grid;gap:10px">
                  <?php foreach ($replies as $r): ?>
                    <div class="bubble">
                      <div class="muted" style="font-size:12px;margin-bottom:6px">
                        By <?= $r['_username'] ? $h($r['_username']) : ($r['user_id'] ? ('User #'.(int)$r['user_id']) : 'Staff') ?>
                        — <strong><?= $h($r['author_role'] ?? 'staff') ?></strong> — <?= $h($r['created_at'] ?? '') ?>
                      </div>
                      <div><?= nl2br($h($r['message'] ?? '')) ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <h3 style="margin-top:16px">Post Staff Reply</h3>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
                <input type="hidden" name="action" value="add_reply">
                <div class="form-row">
                  <label>Message</label>
                  <textarea name="message" placeholder="Write a helpful, user-facing reply…"></textarea>
                </div>
                <div class="btn-row" style="margin-top:8px">
                  <button class="btn primary" type="submit">Send Reply</button>
                </div>
              </form>
            </section>

            <aside class="card">
              <h3 style="margin-top:0">Status</h3>
              <form method="post" class="btn-row" style="align-items:center">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
                <input type="hidden" name="action" value="set_status">
                <select name="status">
                  <option value="open"   <?= $ticket['status']==='open'?'selected':''; ?>>Open</option>
                  <option value="closed" <?= $ticket['status']==='closed'?'selected':''; ?>>Closed</option>
                </select>
                <button class="btn" type="submit">Update</button>
              </form>

              <h3 style="margin-top:16px">Internal Notes</h3>
              <?php if ($notes): ?>
                <div style="display:grid;gap:10px;margin-bottom:10px">
                  <?php foreach ($notes as $n): ?>
                    <div class="bubble">
                      <div class="muted" style="font-size:12px;margin-bottom:6px">
                        <?= $n['_username'] ? $h($n['_username']) : 'Staff' ?> — <?= $h($n['created_at'] ?? '') ?>
                      </div>
                      <div><?= nl2br($h($n['note'] ?? '')) ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="muted">No notes yet.</p>
              <?php endif; ?>

              <form method="post">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
                <input type="hidden" name="action" value="add_note">
                <div class="form-row">
                  <label>Add note (private)</label>
                  <textarea name="note" placeholder="Only staff can see this…"></textarea>
                </div>
                <div class="btn-row" style="margin-top:8px">
                  <button class="btn" type="submit">Add Note</button>
                </div>
              </form>
            </aside>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
  </div>
</body>
</html>
