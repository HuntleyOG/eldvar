<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$pdo = get_pdo();
try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* ---------- access control (admins + governors) ---------- */
$actorId = $_SESSION['user_id'] ?? null;
if (!$actorId) {
  header('Location: ' . BASE_URL . '/login.php');
  exit;
}
$st = $pdo->prepare('SELECT id, role, username FROM users WHERE id = ? LIMIT 1');
$st->execute([$actorId]);
$actor = $st->fetch();                 // <-- fetch ONE row (not fetchAll)
$actorRole = strtolower((string)($actor['role'] ?? 'player'));
if (!in_array($actorRole, ['admin','governor','govenor'], true)) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}

/* ---------- csrf ---------- */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

/* ---------- helpers ---------- */
function s(?string $v): string { return trim((string)$v); }
function redirect_self(): void {
  $url = strtok($_SERVER['REQUEST_URI'] ?? 'chat_reports.php', '?');
  header('Location: ' . $url);
  exit;
}

/* ---------- mutations ---------- */
$notice = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    $error = 'Invalid CSRF token.';
  } else {
    $action = $_POST['action'] ?? '';
    try {
      if ($action === 'resolve' || $action === 'invalid') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid report id.');
        $newStatus = $action === 'resolve' ? 'resolved' : 'invalid';

        $q = $pdo->prepare("UPDATE chat_message_reports
                              SET status = ?, handled_by = ?, handled_at = NOW()
                            WHERE id = ?");
        $q->execute([$newStatus, $actorId, $id]);
        $notice = 'Report updated.';
      } elseif ($action === 'delete_message') {
        $mid = (int)($_POST['message_id'] ?? 0);
        if ($mid <= 0) throw new RuntimeException('Invalid message id.');
        $pdo->prepare('DELETE FROM chat_messages WHERE id = ?')->execute([$mid]);
        $notice = 'Message deleted.';
      }
    } catch (Throwable $e) {
      $error = APP_DEBUG ? $e->getMessage() : 'Operation failed.';
    }
  }
  // PRG
  redirect_self();
}

/* ---------- filters + pagination ---------- */
$status = s($_GET['status'] ?? 'open');   // open | resolved | invalid | all
$q      = s($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$pp     = 50;
$off    = ($page - 1) * $pp;

$where  = [];
$params = [];

if ($status !== '' && $status !== 'all') {
  $where[] = 'r.status = :status';
  $params[':status'] = $status;
}
if ($q !== '') {
  // search against message body, reason, author username, reporter display/username, channel
  $where[] = "(m.body LIKE :q OR r.reason LIKE :q OR m.username LIKE :q OR au.username LIKE :q OR ru.username LIKE :q OR m.channel LIKE :q)";
  $params[':q'] = '%'.$q.'%';
}

$wsql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* --- total count --- */
$cnt = $pdo->prepare("
  SELECT COUNT(*)
  FROM chat_message_reports r
  LEFT JOIN chat_messages m    ON m.id = r.message_id
  LEFT JOIN users au           ON au.id = m.user_id
  LEFT JOIN users ru           ON ru.id = r.reporter_user_id
  $wsql
");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $pp));

/* --- rows --- */
$sql = "
SELECT
  r.id           AS report_id,
  r.message_id,
  r.reporter_user_id,
  r.reason,
  r.status,
  r.created_at   AS reported_at,
  r.handled_by,
  r.handled_at,

  m.user_id      AS msg_user_id,
  m.username     AS msg_username,
  m.body         AS msg_body,
  m.channel      AS msg_channel,
  m.created_at   AS msg_time,

  au.username    AS author_username,
  ru.username    AS reporter_username,
  hu.username    AS handler_username
FROM chat_message_reports r
LEFT JOIN chat_messages m ON m.id = r.message_id
LEFT JOIN users au ON au.id = m.user_id              -- author
LEFT JOIN users ru ON ru.id = r.reporter_user_id     -- reporter
LEFT JOIN users hu ON hu.id = r.handled_by           -- handler
$wsql
ORDER BY r.id DESC
LIMIT $off, $pp
";
$st = $pdo->prepare($sql);
foreach ($params as $k => $v) { $st->bindValue($k, $v, PDO::PARAM_STR); }
$st->execute();
$rows = $st->fetchAll();

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin — Chat Reports · Eldvar</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  <style>
    .acp-wrap{display:grid;gap:16px}
    .card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:16px}
    .searchbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    table.tbl{width:100%;border-collapse:collapse}
    table.tbl th, table.tbl td{border:1px solid var(--border);padding:8px 10px;text-align:left;vertical-align:top}
    table.tbl th{background:var(--panel-2);color:var(--accent-2)}
    .pill{display:inline-block;border:1px solid var(--border);border-radius:999px;padding:2px 8px;font-size:12px;color:var(--muted)}
    .status-open{color:#ffd27d;border-color:rgba(255,210,125,.35)}
    .status-resolved{color:#9fe7a9;border-color:rgba(119,221,119,.35)}
    .status-invalid{color:#f7a6a6;border-color:rgba(255,120,120,.35)}
    .mono{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace}
    .msg{white-space:pre-wrap}
    .btn-row{display:flex;gap:6px;flex-wrap:wrap}
    .pagination{display:flex;gap:6px;justify-content:flex-end;align-items:center}
    .pagination a{border:1px solid var(--border);background:var(--panel-2);padding:6px 10px;border-radius:8px;text-decoration:none;color:var(--text)}
    .muted{color:var(--muted)}
  </style>
</head>
<body class="with-sidebar">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div id="backdrop" class="backdrop"></div>

<div class="app-shell">
  <?php include __DIR__ . '/../includes/header.php'; ?>

  <main class="app-main">
    <div class="acp-wrap">

      <section class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
          <h1 class="pixel-title" style="margin:0">Chat Reports</h1>

          <form class="searchbar" method="get">
            <select name="status">
              <?php
              foreach (['open'=>'Open','resolved'=>'Resolved','invalid'=>'Invalid','all'=>'All'] as $val=>$label) {
                $sel = ($status === $val) ? 'selected' : '';
                echo "<option value=\"".htmlspecialchars($val)."\" $sel>".htmlspecialchars($label)."</option>";
              }
              ?>
            </select>
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search reason, body, usernames, channel…">
            <button class="btn" type="submit">Filter</button>
            <?php if ($q !== '' || $status !== 'open'): ?>
              <a class="btn" href="<?= BASE_URL ?>/admin/chat_reports.php">Clear</a>
            <?php endif; ?>
          </form>
        </div>

        <?php if ($notice): ?><div class="notice" style="margin-top:10px;"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error" style="margin-top:10px;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="muted" style="margin-top:8px">
          Showing <strong><?= count($rows) ?></strong> of <strong><?= (int)$total ?></strong> report(s).
        </div>
      </section>

      <section class="card">
        <?php if (!$rows): ?>
          <p class="muted">No reports match your filters.</p>
        <?php else: ?>
          <table class="tbl">
            <thead>
              <tr>
                <th>ID</th>
                <th>Status</th>
                <th>Reason</th>
                <th>Message</th>
                <th>Reporter</th>
                <th>Author</th>
                <th>Channel</th>
                <th>Times</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="mono">#<?= (int)$r['report_id'] ?></td>
                <td>
                  <?php
                    $st = strtolower((string)$r['status']);
                    $cls = $st === 'resolved' ? 'status-resolved' : ($st === 'invalid' ? 'status-invalid' : 'status-open');
                  ?>
                  <span class="pill <?= $cls ?>"><?= htmlspecialchars($st ?: 'open') ?></span>
                  <?php if (!empty($r['handler_username'])): ?>
                    <div class="muted" style="font-size:12px">by <?= htmlspecialchars($r['handler_username']) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= nl2br(htmlspecialchars((string)$r['reason'])) ?></td>
                <td>
                  <?php if (!$r['message_id']): ?>
                    <span class="muted">[no message id]</span>
                  <?php else: ?>
                    <div class="mono muted">#<?= (int)$r['message_id'] ?></div>
                    <div class="msg"><?= nl2br(htmlspecialchars((string)$r['msg_body'])) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?= htmlspecialchars($r['reporter_username'] ?? '—') ?><br>
                  <span class="muted mono">uid: <?= (int)$r['reporter_user_id'] ?></span>
                </td>
                <td>
                  <?= htmlspecialchars($r['author_username'] ?? ($r['msg_username'] ?? '—')) ?><br>
                  <span class="muted mono">uid: <?= (int)($r['msg_user_id'] ?? 0) ?></span>
                </td>
                <td><?= htmlspecialchars($r['msg_channel'] ?? 'global') ?></td>
                <td class="muted" style="font-size:12px">
                  <div>Reported: <?= htmlspecialchars($r['reported_at']) ?></div>
                  <?php if (!empty($r['msg_time'])): ?>
                    <div>Message: <?= htmlspecialchars($r['msg_time']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($r['handled_at'])): ?>
                    <div>Handled: <?= htmlspecialchars($r['handled_at']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="btn-row">
                    <?php if ($st !== 'resolved'): ?>
                      <form method="post">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['report_id'] ?>">
                        <button class="btn" name="action" value="resolve">Resolve</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($st !== 'invalid'): ?>
                      <form method="post" onsubmit="return confirm('Mark as invalid?');">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$r['report_id'] ?>">
                        <button class="btn" name="action" value="invalid">Invalid</button>
                      </form>
                    <?php endif; ?>

                    <?php if (!empty($r['message_id'])): ?>
                      <form method="post" onsubmit="return confirm('Delete this message?');">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="message_id" value="<?= (int)$r['message_id'] ?>">
                        <button class="btn" name="action" value="delete_message">Delete Msg</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <div class="pagination" style="margin-top:12px">
            <?php if ($page > 1): ?>
              <a href="?status=<?= urlencode($status) ?>&q=<?= urlencode($q) ?>&page=<?= $page-1 ?>">Prev</a>
            <?php endif; ?>
            <span class="muted">Page <?= $page ?> / <?= $pages ?></span>
            <?php if ($page < $pages): ?>
              <a href="?status=<?= urlencode($status) ?>&q=<?= urlencode($q) ?>&page=<?= $page+1 ?>">Next</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>

    </div>
  </main>

  <?php include __DIR__ . '/../includes/footer.php'; ?>
</div>
</body>
</html>
