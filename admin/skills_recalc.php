<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/skills.php';

session_start();

/* Gate: require login; optionally require admin role if you have roles */
if (!isset($_SESSION['user_id'])) { header('Location: '.BASE_URL.'/login.php'); exit; }
// If you have $_SESSION['role']==='admin', check it here.

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/* CSRF */
if (empty($_SESSION['admin_csrf'])) { $_SESSION['admin_csrf'] = bin2hex(random_bytes(32)); }
$csrf_ok = function(string $t): bool { return !empty($t) && hash_equals($_SESSION['admin_csrf'], $t); };

$action = $_POST['action'] ?? '';
$msgs = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrf_ok($_POST['csrf'] ?? '')) {
  try {
    if ($action === 'backfill') {
      // Ensure all users have all skill rows
      $uids = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);
      foreach ($uids as $uid) ensure_user_skills($pdo, (int)$uid);
      $msgs[] = "Backfilled user_skills for ".count($uids)." users.";
    } elseif ($action === 'recalc') {
      $pdo->beginTransaction();
      $st = $pdo->query("SELECT user_id, skill_id, xp, level FROM user_skills");
      $rows = $st->fetchAll();
      $updated = 0;
      foreach ($rows as $r) {
        $xp = (int)$r['xp'];
        $newLevel = level_from_xp($xp, 99);
        if ($newLevel != (int)$r['level']) {
          $upd = $pdo->prepare("UPDATE user_skills SET level=? WHERE user_id=? AND skill_id=?");
          $upd->execute([$newLevel, (int)$r['user_id'], (int)$r['skill_id']]);
          $updated++;
        }
      }
      $pdo->commit();
      $msgs[] = "Recalculated levels from XP. Rows updated: $updated.";
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errors[] = $e->getMessage();
  }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Skills Admin — Eldvar</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  <style>
    .admin-card{max-width:780px;margin:20px auto}
  </style>
</head>
<body>
  <div class="app-shell">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <main class="app-main">
      <section class="card admin-card">
        <h1 class="card-title">Skills Admin</h1>
        <p class="muted">Backfill missing user skills and recalculate levels from XP.</p>

        <?php if ($errors): ?><div class="alert error"><?php foreach ($errors as $e) echo '<div>'.htmlspecialchars($e).'</div>'; ?></div><?php endif; ?>
        <?php if ($msgs): ?><div class="alert success"><?php foreach ($msgs as $m) echo '<div>'.htmlspecialchars($m).'</div>'; ?></div><?php endif; ?>

        <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
          <button class="btn" name="action" value="backfill" type="submit">Backfill user_skills</button>
          <button class="btn primary" name="action" value="recalc" type="submit">Recalculate levels from XP</button>
        </form>

        <div class="card" style="margin-top:12px;">
          <h3 class="card-title">Preview (first 25 rows)</h3>
          <div class="muted" style="font-size:.9rem;overflow:auto;max-height:300px;">
            <table class="table">
              <thead><tr><th>User</th><th>Skill</th><th>Level</th><th>XP</th><th>XP→Level</th></tr></thead>
              <tbody>
              <?php
                $q = $pdo->query("SELECT us.user_id, u.username, s.skey, s.name, us.level, us.xp
                                   FROM user_skills us
                                   JOIN users u ON u.id=us.user_id
                                   JOIN skills s ON s.id=us.skill_id
                                   ORDER BY us.user_id, us.skill_id LIMIT 25");
                foreach ($q->fetchAll() as $r):
                  $calc = level_from_xp((int)$r['xp'], 99);
              ?>
                <tr>
                  <td><?= (int)$r['user_id'] ?> (<?= htmlspecialchars($r['username']) ?>)</td>
                  <td><?= htmlspecialchars($r['name']) ?> (<?= htmlspecialchars($r['skey']) ?>)</td>
                  <td><?= (int)$r['level'] ?></td>
                  <td><?= number_format((int)$r['xp']) ?></td>
                  <td><?= $calc ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </main>
    <?php include __DIR__ . '/../includes/footer.php'; ?>
  </div>
</body>
</html>
