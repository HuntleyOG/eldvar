<?php
declare(strict_types=1);

require __DIR__ . '/config/config.php';
require __DIR__ . '/config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$pdo = get_pdo();

/* ---------- PDO stricter defaults ---------- */
try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* ---------- Ensure required tables exist ---------- */
try {
  // Main tickets table (matches your schema)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS support_tickets (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NULL,
      email VARCHAR(190) NULL,
      subject VARCHAR(190) NOT NULL,
      message MEDIUMTEXT NOT NULL,
      category ENUM('account','bug','payment','appeal','other') NOT NULL DEFAULT 'other',
      status ENUM('open','closed') NOT NULL DEFAULT 'open',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX (user_id),
      INDEX (status),
      INDEX (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  // Replies table (visible to both sides)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS support_replies (
      id INT AUTO_INCREMENT PRIMARY KEY,
      ticket_id INT NOT NULL,
      user_id INT NULL,
      author_role VARCHAR(32) NOT NULL,
      message TEXT NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_support_replies_ticket (ticket_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {
  http_response_code(500);
  echo "<!doctype html><meta charset='utf-8'><title>Support error</title><pre>"
       . htmlspecialchars($e->getMessage()) . "</pre>";
  exit;
}

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

/* ---------- Helpers ---------- */
$asset  = fn(string $p) => rtrim(BASE_URL,'/') . '/' . ltrim($p,'/');
$meId   = $_SESSION['user_id'] ?? null;
$meName = $_SESSION['username'] ?? null;

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function clean(string $s): string { return trim($s); }

/* ---------- Routing ---------- */
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;

/* ---------- Notices ---------- */
$notice = '';
$error  = '';

/* =========================================================
   CREATE A NEW TICKET (from the form on index view)
   ========================================================= */
if ($viewId <= 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_ticket') {
  try {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
      throw new RuntimeException('Invalid request (CSRF). Please refresh and try again.');
    }

    // Simple rate limit: 1 ticket every 15 seconds per session
    $last = (int)($_SESSION['last_support_submit'] ?? 0);
    if (time() - $last < 15) {
      throw new RuntimeException('You’re submitting too fast. Please wait a moment and try again.');
    }

    $subject  = clean($_POST['subject'] ?? '');
    $email    = clean($_POST['email'] ?? '');
    $message  = trim((string)($_POST['message'] ?? ''));
    $category = $_POST['category'] ?? 'other';
    if (!in_array($category, ['account','bug','payment','appeal','other'], true)) { $category = 'other'; }

    if ($subject === '' || $message === '') {
      throw new RuntimeException('Subject and message are required.');
    }
    if (!$meId && $email === '') {
      throw new RuntimeException('Please provide an email so we can reply.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new RuntimeException('Please enter a valid email address.');
    }

    $stmt = $pdo->prepare("
      INSERT INTO support_tickets (user_id, email, subject, message, category)
      VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$meId, $email ?: null, $subject, $message, $category]);

    $_SESSION['last_support_submit'] = time();
    $notice = 'Thanks! Your ticket has been submitted. Our team will review it soon.';
    // Clear form fields on success
    $_POST = [];
  } catch (Throwable $e) {
    $error = APP_DEBUG ? $e->getMessage() : 'Could not submit your ticket. Please try again.';
  }
}

/* =========================================================
   THREAD REPLY (when viewing a specific ticket)
   ========================================================= */
if ($viewId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_reply') {
  try {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
      throw new RuntimeException('Invalid request (CSRF). Please refresh and try again.');
    }
    if (!$meId) {
      throw new RuntimeException('You must be logged in to reply to a ticket.');
    }

    // Ensure ticket exists and belongs to the user
    $t = $pdo->prepare("SELECT id, user_id, status FROM support_tickets WHERE id = ? LIMIT 1");
    $t->execute([$viewId]);
    $ticketRow = $t->fetch();
    if (!$ticketRow || (int)$ticketRow['user_id'] !== (int)$meId) {
      throw new RuntimeException('Ticket not found or you do not have access to it.');
    }

    // Optional: block replies to closed tickets (comment out if you want replies allowed)
    if (($ticketRow['status'] ?? 'open') !== 'open') {
      throw new RuntimeException('This ticket is closed and can’t be replied to.');
    }

    // Simple per-session reply rate limit: 1 reply every 8 seconds
    $lastR = (int)($_SESSION['last_support_reply'] ?? 0);
    if (time() - $lastR < 8) {
      throw new RuntimeException('You’re replying too fast. Please wait a moment and try again.');
    }

    $msg = trim((string)($_POST['message'] ?? ''));
    if ($msg === '') {
      throw new RuntimeException('Reply cannot be empty.');
    }

    $ins = $pdo->prepare("
      INSERT INTO support_replies (ticket_id, user_id, author_role, message)
      VALUES (?, ?, 'player', ?)
    ");
    $ins->execute([$viewId, $meId, $msg]);

    $pdo->prepare("UPDATE support_tickets SET updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$viewId]);

    $_SESSION['last_support_reply'] = time();
    $notice = 'Reply posted.';
  } catch (Throwable $e) {
    $error = APP_DEBUG ? $e->getMessage() : 'Could not post your reply.';
  }
}

/* ---------- Load my recent tickets (if logged in) ---------- */
$myTickets = [];
if ($meId && $viewId <= 0) {
  try {
    $st = $pdo->prepare("
      SELECT id, subject, category, status, created_at, updated_at
      FROM support_tickets
      WHERE user_id = ?
      ORDER BY updated_at DESC
      LIMIT 50
    ");
    $st->execute([$meId]);
    $myTickets = $st->fetchAll();
  } catch (Throwable $e) { $myTickets = []; }
}

/* ---------- Load thread detail (only for the owner) ---------- */
$ticket = null; $replies = []; $detailErr = '';

if ($viewId > 0) {
  try {
    $q = $pdo->prepare("SELECT * FROM support_tickets WHERE id = ? LIMIT 1");
    $q->execute([$viewId]);
    $ticket = $q->fetch();

    if (!$ticket) {
      $detailErr = 'Ticket not found.';
    } elseif (!$meId || (int)$ticket['user_id'] !== (int)$meId) {
      // For security, only logged-in owner can view the thread
      $detailErr = 'You do not have access to this ticket.';
      $ticket = null;
    }
  } catch (Throwable $e) {
    $detailErr = APP_DEBUG ? ('Ticket query failed: ' . $e->getMessage()) : 'Could not load ticket.';
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
    } catch (Throwable $e) {
      $replies = [];
      $detailErr = APP_DEBUG ? ('Replies query failed: ' . $e->getMessage()) : 'Could not load replies.';
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Support — Eldvar</title>
  <link rel="stylesheet" href="<?= $asset('public/css/style.css') ?>">
  <style>
    /* Scoped helpers */
    .support-wrap{display:grid;gap:16px}
    .card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:16px}
    .muted{color:var(--muted)}
    .grid-2{display:grid;gap:12px;grid-template-columns:2fr 1fr}
    @media (max-width: 960px){ .grid-2{grid-template-columns:1fr} }

    .form-row{display:flex;flex-direction:column;gap:6px}
    input[type="text"], input[type="email"], select, textarea{
      background:var(--panel-2); border:1px solid var(--border); border-radius:8px;
      padding:10px; color:var(--text); font:inherit;
    }
    textarea{min-height:180px}
    .btn-row{display:flex;gap:8px;flex-wrap:wrap}

    .tbl{width:100%;border-collapse:collapse}
    .tbl th,.tbl td{border:1px solid var(--border);padding:8px 10px;text-align:left}
    .tbl th{background:var(--panel-2);color:var(--accent-2)}
    .pill{display:inline-block;padding:2px 8px;border:1px solid var(--border);border-radius:999px;font-size:12px}
    .pill.open{color:#a0e0ff;border-color:rgba(160,224,255,.35)}
    .pill.closed{color:#9fe7a9;border-color:rgba(119,221,119,.35)}

    /* Thread view */
    .bubble{background:var(--panel-2);border:1px solid var(--border);border-radius:10px;padding:10px 12px}
    .meta{font-size:12px;color:var(--muted);margin-bottom:6px}
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="app-main">
      <div class="support-wrap">

        <!-- Hero -->
        <section class="card">
          <h1 class="pixel-title" style="margin:0">Support</h1>
          <?php if ($viewId <= 0): ?>
            <p class="muted" style="margin:.4rem 0 0">
              Stuck? Spotted a bug? Need help with your account? Send us a ticket and we’ll get back to you.
            </p>
          <?php else: ?>
            <p class="muted" style="margin:.4rem 0 0">
              View your ticket conversation and reply here.
            </p>
          <?php endif; ?>
          <?php if ($notice): ?>
            <div class="card" style="margin-top:10px;border-color:rgba(119,221,119,.35)">✅ <?= h($notice) ?></div>
          <?php endif; ?>
          <?php if ($error): ?>
            <div class="card" style="margin-top:10px;border-color:rgba(200,80,80,.45)">⚠ <?= h($error) ?></div>
          <?php endif; ?>
        </section>

        <?php if ($viewId > 0): ?>
          <!-- ===================== THREAD VIEW ===================== -->
          <?php if ($detailErr): ?>
            <section class="card">
              <p class="muted">⚠ <?= h($detailErr) ?></p>
              <p><a class="btn" href="<?= h(BASE_URL.'/support.php') ?>">&larr; Back to Support</a></p>
            </section>
          <?php elseif (!$ticket): ?>
            <section class="card"><p class="muted">Ticket not found.</p></section>
          <?php else: ?>
            <section class="card">
              <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <a class="btn" href="<?= h(BASE_URL.'/support.php') ?>">&larr; Back</a>
                <h2 style="margin:0"><?= h($ticket['subject']) ?></h2>
                <span class="pill <?= $ticket['status']==='open'?'open':'closed' ?>"><?= ucfirst(h($ticket['status'])) ?></span>
                <span class="pill"><?= ucfirst(h($ticket['category'])) ?></span>
              </div>

              <div class="muted" style="margin-top:8px">
                Ticket #<?= (int)$ticket['id'] ?> — Created <?= h($ticket['created_at']) ?> — Updated <?= h($ticket['updated_at']) ?>
              </div>

              <h3 style="margin-top:16px">Original Message</h3>
              <div class="bubble">
                <div class="meta">From you — <?= h($ticket['created_at']) ?></div>
                <div><?= nl2br(h($ticket['message'])) ?></div>
              </div>

              <?php if ($replies): ?>
                <h3 style="margin-top:16px">Conversation</h3>
                <div style="display:grid;gap:10px">
                  <?php foreach ($replies as $r): ?>
                    <div class="bubble">
                      <div class="meta">
                        <?= ($r['author_role'] ?? '') === 'player' ? 'You' : 'Staff' ?>
                        — <?= h($r['created_at'] ?? '') ?>
                      </div>
                      <div><?= nl2br(h($r['message'] ?? '')) ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php if (($ticket['status'] ?? 'open') === 'open'): ?>
                <h3 style="margin-top:16px">Add a Reply</h3>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="add_reply">
                  <div class="form-row">
                    <label>Your Message</label>
                    <textarea name="message" required placeholder="Write your reply… Include any updates or extra details."></textarea>
                  </div>
                  <div class="btn-row" style="margin-top:8px">
                    <button class="btn primary" type="submit">Send Reply</button>
                  </div>
                </form>
              <?php else: ?>
                <div class="card" style="margin-top:12px;border-color:rgba(160,224,255,.35)">
                  <p class="muted" style="margin:0">This ticket is <strong>closed</strong>. If you need more help, please open a new ticket.</p>
                </div>
              <?php endif; ?>
            </section>
          <?php endif; ?>

        <?php else: ?>
          <!-- ===================== INDEX VIEW (FORM + MY TICKETS) ===================== -->
          <div class="grid-2">
            <!-- Submit a ticket -->
            <section class="card">
              <h2 style="margin-top:0">Submit a Ticket</h2>
              <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="create_ticket">

                <?php if (!$meId): ?>
                  <div class="form-row">
                    <label>Email (so we can contact you)</label>
                    <input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required>
                  </div>
                <?php else: ?>
                  <p class="muted" style="margin-top:0">Logged in as <strong><?= h($meName ?? 'Player') ?></strong>. We’ll reply to your account email.</p>
                <?php endif; ?>

                <div class="form-row">
                  <label>Category</label>
                  <?php $cat = $_POST['category'] ?? 'other'; ?>
                  <select name="category">
                    <option value="account" <?= $cat==='account'?'selected':''; ?>>Account</option>
                    <option value="bug"     <?= $cat==='bug'?'selected':'';     ?>>Bug Report</option>
                    <option value="payment" <?= $cat==='payment'?'selected':''; ?>>Payment/Billing</option>
                    <option value="appeal"  <?= $cat==='appeal'?'selected':'';  ?>>Appeal</option>
                    <option value="other"   <?= $cat==='other'?'selected':'';   ?>>Other</option>
                  </select>
                </div>

                <div class="form-row">
                  <label>Subject</label>
                  <input type="text" name="subject" maxlength="190" value="<?= h($_POST['subject'] ?? '') ?>" required>
                </div>

                <div class="form-row">
                  <label>Message</label>
                  <textarea name="message" required placeholder="Tell us what’s going on… Include steps to reproduce, screenshots/links, order IDs, etc."><?= h($_POST['message'] ?? '') ?></textarea>
                </div>

                <div class="btn-row" style="margin-top:8px">
                  <button class="btn primary" type="submit">Submit Ticket</button>
                  <button class="btn" type="reset">Reset</button>
                </div>
              </form>
            </section>

            <!-- Quick help -->
            <section class="card">
              <h2 style="margin-top:0">Quick Answers</h2>
              <div class="faq-list" style="display:grid;gap:10px">
                <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;background:var(--panel-2);padding:10px">
                  <h4 style="margin:0 0 6px">Can I change my username?</h4>
                  <p class="muted">Yes—open a ticket under <em>Account</em> with the new name you’d like. First change is free.</p>
                </div>
                <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;background:var(--panel-2);padding:10px">
                  <h4 style="margin:0 0 6px">I found a bug!</h4>
                  <p class="muted">Choose <em>Bug Report</em>, include steps to reproduce, where it happened, and what you expected to happen.</p>
                </div>
                <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;background:var(--panel-2);padding:10px">
                  <h4 style="margin:0 0 6px">Payment issue?</h4>
                  <p class="muted">Pick <em>Payment/Billing</em> and include your transaction ID, date, and amount.</p>
                </div>
                <div class="faq-item" style="border:1px solid var(--border);border-radius:10px;background:var(--panel-2);padding:10px">
                  <h4 style="margin:0 0 6px">Appeals</h4>
                  <p class="muted">Choose <em>Appeal</em> and share any relevant context calmly and clearly so staff can review.</p>
                </div>
              </div>
            </section>
          </div>

          <!-- My tickets -->
          <section class="card">
            <h2 style="margin-top:0">My Recent Tickets</h2>
            <?php if (!$meId): ?>
              <p class="muted">Log in to see your tickets and reply to them.</p>
            <?php elseif (!$myTickets): ?>
              <p class="muted">You haven’t submitted any tickets yet.</p>
            <?php else: ?>
              <table class="tbl">
                <thead>
                <tr>
                  <th>ID</th>
                  <th>Subject</th>
                  <th>Category</th>
                  <th>Status</th>
                  <th>Created</th>
                  <th>Updated</th>
                  <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($myTickets as $t): ?>
                  <tr>
                    <td><?= (int)$t['id'] ?></td>
                    <td><?= h($t['subject']) ?></td>
                    <td><?= ucfirst(h($t['category'])) ?></td>
                    <td><span class="pill <?= $t['status']==='open' ? 'open':'closed' ?>"><?= ucfirst(h($t['status'])) ?></span></td>
                    <td><?= h($t['created_at']) ?></td>
                    <td><?= h($t['updated_at']) ?></td>
                    <td><a class="btn" href="<?= h(BASE_URL.'/support.php?view='.(int)$t['id']) ?>">Open</a></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </section>
        <?php endif; ?>

      </div>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>
</body>
</html>
