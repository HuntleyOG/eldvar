<?php
declare(strict_types=1);

require __DIR__ . '/config/config.php';
require __DIR__ . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* --- Require login --- */
if (!isset($_SESSION['user_id'])) {
  header('Location: ' . BASE_URL . '/login.php');
  exit;
}

/* ---------- Helpers ---------- */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function json_out($data, int $code = 200): never {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function table_exists(PDO $pdo, string $name): bool {
  try {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $st->execute([$name]); return (bool)$st->fetchColumn();
  } catch (Throwable) { return false; }
}
function column_exists(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$table, $column]); return (bool)$st->fetchColumn();
  } catch (Throwable) { return false; }
}

/* CSRF (scoped to chat) */
function chat_csrf_token(): string {
  if (!empty($_SESSION['chat_csrf'])) return $_SESSION['chat_csrf'];
  try { $_SESSION['chat_csrf'] = bin2hex(random_bytes(32)); }
  catch (Throwable) { $_SESSION['chat_csrf'] = sha1(uniqid('', true)); }
  return $_SESSION['chat_csrf'];
}
function chat_csrf_check(?string $t): bool {
  return !empty($t) && !empty($_SESSION['chat_csrf']) && hash_equals($_SESSION['chat_csrf'], $t);
}

/* ---------- DB ---------- */
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/* Ensure chat_messages table exists (safe idempotent) */
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS chat_messages (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      body TEXT NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (created_at),
      INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
} catch (Throwable $e) {
  // If this fails, page will still render with a warning and no posting
}

/* Detect capabilities */
$have_msgs  = table_exists($pdo, 'chat_messages');
$have_users = table_exists($pdo, 'users');
$has_un     = $have_users && column_exists($pdo, 'users', 'username');
$has_dn     = $have_users && column_exists($pdo, 'users', 'display_name');

/* ---------- AJAX endpoints ---------- */
$action = $_GET['action'] ?? '';
if ($action === 'history' || $action === 'poll') {
  if (!$have_msgs) json_out(['ok'=>false, 'error'=>'Chat table not available'], 200);

  $sinceId = isset($_GET['since']) && is_numeric($_GET['since']) ? (int)$_GET['since'] : 0;
  $limit   = 100;

  try {
    if ($sinceId > 0) {
      // Newer than sinceId
      if ($have_users) {
        $sql = "SELECT m.id, m.user_id, m.body, m.created_at,
                       " . ($has_dn ? "u.display_name" : "NULL") . " AS display_name,
                       " . ($has_un ? "u.username"     : "NULL") . " AS username
                FROM chat_messages m
                LEFT JOIN users u ON u.id = m.user_id
                WHERE m.id > ?
                ORDER BY m.id ASC
                LIMIT {$limit}";
        $st = $pdo->prepare($sql); $st->execute([$sinceId]);
      } else {
        $st = $pdo->prepare("SELECT id, user_id, body, created_at FROM chat_messages WHERE id > ? ORDER BY id ASC LIMIT {$limit}");
        $st->execute([$sinceId]);
      }
    } else {
      // Last N
      if ($have_users) {
        $sql = "SELECT * FROM (
                  SELECT m.id, m.user_id, m.body, m.created_at,
                         " . ($has_dn ? "u.display_name" : "NULL") . " AS display_name,
                         " . ($has_un ? "u.username"     : "NULL") . " AS username
                  FROM chat_messages m
                  LEFT JOIN users u ON u.id = m.user_id
                  ORDER BY m.id DESC
                  LIMIT {$limit}
                ) t ORDER BY t.id ASC";
        $st = $pdo->query($sql);
      } else {
        $st = $pdo->query("SELECT * FROM (SELECT id, user_id, body, created_at FROM chat_messages ORDER BY id DESC LIMIT {$limit}) t ORDER BY t.id ASC");
      }
    }
    $rows = $st ? $st->fetchAll() : [];
    $out = [];
    foreach ($rows as $r) {
      $name = $r['display_name'] ?? null;
      if (!$name || $name === '') $name = $r['username'] ?? ('User#'.$r['user_id']);
      $out[] = [
        'id'   => (int)$r['id'],
        'user' => (string)$name,
        'body' => (string)$r['body'],
        'ts'   => (string)$r['created_at'],
      ];
    }
    json_out(['ok'=>true, 'messages'=>$out]);
  } catch (Throwable $e) {
    json_out(['ok'=>false, 'error'=>'Load failed'], 200);
  }
}

/* ---------- Handle POST (new message) ---------- */
$post_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $body  = trim((string)($_POST['body'] ?? ''));
  $token = (string)($_POST['csrf'] ?? '');

  if (!$have_msgs) {
    $post_error = 'Chat is not available right now.';
  } elseif (!chat_csrf_check($token)) {
    $post_error = 'Invalid session token.';
  } elseif ($body === '') {
    $post_error = 'Type a message first.';
  } elseif (mb_strlen($body) > 500) {
    $post_error = 'Message too long (max 500 chars).';
  } else {
    // simple rate limit: 2s between posts per session
    $now = time();
    $last = (int)($_SESSION['chat_last_post'] ?? 0);
    if ($now - $last < 2) {
      $post_error = 'You are sending messages too quickly.';
    } else {
      try {
        $stmt = $pdo->prepare("INSERT INTO chat_messages (user_id, body) VALUES (?, ?)");
        $stmt->execute([(int)$_SESSION['user_id'], $body]);
        $_SESSION['chat_last_post'] = $now;

        // If this was a regular form submit (non-AJAX), redirect to avoid resubmits
        if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
          header('Location: ' . BASE_URL . '/chatroom.php');
          exit;
        } else {
          json_out(['ok'=>true]);
        }
      } catch (Throwable $e) {
        $post_error = 'Failed to send.';
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
          json_out(['ok'=>false, 'error'=>$post_error], 200);
        }
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Chat Room — Eldvar</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  <style>
    .chat-wrap { max-width: 960px; margin: 0 auto; }
    .presence { color: var(--muted); font-size: 13px; margin: 6px 2px 10px; }
    .sys { color: var(--muted); font-style: italic; }
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="app-main">
      <section class="chat-wrap">
        <div class="hero-card" style="margin-bottom:14px;">
          <h1 class="pixel-title" style="margin:0;">Town Chat</h1>
          <p class="lead" style="margin:.35rem 0 0;">Gather by the fountain. Share tips, form parties, and spin tall tales.</p>
          <div class="presence" id="presence">Connected. Fetching recent messages…</div>
        </div>

        <div class="messenger">
          <div class="chat-log" id="chat-log" aria-live="polite" aria-busy="false">
            <!-- messages will be injected here -->
          </div>

          <?php if (!$have_msgs): ?>
            <p class="sys">Chat storage is not available yet. Create table <code>chat_messages</code> or check DB permissions.</p>
          <?php endif; ?>

          <div class="chat-input">
            <input id="chat-input" type="text" maxlength="500" placeholder="Say something to the square…" <?= $have_msgs ? '' : 'disabled' ?>>
            <button id="chat-send" class="btn primary" <?= $have_msgs ? '' : 'disabled' ?>>Send</button>
          </div>
          <?php if ($post_error): ?>
            <p class="sys" style="margin:6px 2px 0;"><?= h($post_error) ?></p>
          <?php endif; ?>
          <input type="hidden" id="csrf" value="<?= h(chat_csrf_token()) ?>">
        </div>
      </section>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>

  <!-- No floating widget here to avoid duplicate chat UI -->
  <script>
  (function(){
    const logEl   = document.getElementById('chat-log');
    const inputEl = document.getElementById('chat-input');
    const sendEl  = document.getElementById('chat-send');
    const csrfEl  = document.getElementById('csrf');
    const presenceEl = document.getElementById('presence');

    let lastId = 0;
    let busy   = false;

    function esc(s){
      const d = document.createElement('div');
      d.textContent = s; return d.innerHTML;
    }

    function addMsg(m){
      // Try to group consecutive messages by same user
      const row = document.createElement('div');
      row.className = 'msg-row other'; // all messages render as "other" theme
      row.innerHTML = `
        <div class="avatar" title="${esc(m.user)}">${esc(m.user).slice(0,1).toUpperCase()}</div>
        <div class="bubble-col">
          <div class="name">${esc(m.user)} <span class="meta" style="margin-left:6px;">${esc(m.ts)}</span></div>
          <div class="bubble">${esc(m.body)}</div>
        </div>
      `;
      logEl.appendChild(row);
    }

    function scrollBottom(){
      logEl.scrollTop = logEl.scrollHeight;
    }

    async function loadHistory(){
      try{
        const r = await fetch('<?= BASE_URL ?>/chatroom.php?action=history', {cache:'no-store'});
        const j = await r.json();
        if (!j.ok) { presenceEl.textContent = 'Unable to load chat.'; return; }
        presenceEl.textContent = 'Connected.';
        j.messages.forEach(m => { addMsg(m); lastId = m.id; });
        scrollBottom();
      }catch(e){
        presenceEl.textContent = 'Unable to load chat.';
      }
    }

    async function poll(){
      if (busy) return;
      busy = true;
      try{
        const r = await fetch('<?= BASE_URL ?>/chatroom.php?action=poll&since=' + encodeURIComponent(lastId), {cache:'no-store'});
        const j = await r.json();
        if (j.ok && j.messages && j.messages.length){
          j.messages.forEach(m => { addMsg(m); lastId = m.id; });
          scrollBottom();
        }
      }catch(e){ /* ignore */ }
      busy = false;
    }

    async function send(){
      if (!inputEl || !sendEl) return;
      const body = inputEl.value.trim();
      if (!body) return;
      sendEl.disabled = true;

      try{
        const fd = new FormData();
        fd.append('body', body);
        fd.append('csrf', csrfEl.value);
        const r = await fetch('<?= BASE_URL ?>/chatroom.php', {
          method: 'POST',
          headers: {'X-Requested-With':'fetch'},
          body: fd
        });
        const j = await r.json().catch(()=>({ok:false}));
        if (j.ok){
          inputEl.value = '';
          // Force a quick poll so the sender sees their line immediately
          poll();
        }else{
          alert(j.error || 'Failed to send.');
        }
      }catch(e){
        alert('Failed to send.');
      }finally{
        sendEl.disabled = false;
        inputEl.focus();
      }
    }

    if (inputEl){
      inputEl.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' && !ev.shiftKey){
          ev.preventDefault();
          send();
        }
      });
    }
    if (sendEl){
      sendEl.addEventListener('click', (ev) => {
        ev.preventDefault();
        send();
      });
    }

    // Boot
    loadHistory().then(()=>{
      setInterval(poll, 4000);
    });

  })();
  </script>
</body>
</html>
