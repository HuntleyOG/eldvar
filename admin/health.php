<?php
declare(strict_types=1);

// /admin/health.php
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ---------- ACL: Admins + Governors only ---------- */
$pdo = get_pdo();
$meId = $_SESSION['user_id'] ?? null;
if (!$meId) { header('Location: ' . BASE_URL . '/login.php'); exit; }

$st = $pdo->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
$st->execute([$meId]);
$meRole = strtolower((string)($st->fetchColumn() ?: 'player'));
if (!in_array($meRole, ['admin','governor','govenor'], true)) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}

/* ---------- Helpers ---------- */
function boolBadge(bool $ok, string $t_ok='OK', string $t_bad='Fail'): string {
  $cls = $ok ? 'ok' : 'bad';
  $txt = $ok ? $t_ok : $t_bad;
  return "<span class=\"pill $cls\">$txt</span>";
}
function ini_bytes(string $val): int {
  $val = trim($val);
  $last = strtolower($val[strlen($val)-1] ?? '');
  $n = (int)$val;
  return match ($last) {
    'g' => $n * 1024 * 1024 * 1024,
    'm' => $n * 1024 * 1024,
    'k' => $n * 1024,
    default => (int)$val,
  };
}
function size_h(int $b): string {
  if ($b >= 1073741824) return number_format($b/1073741824,2).' GB';
  if ($b >= 1048576)    return number_format($b/1048576,2).' MB';
  if ($b >= 1024)       return number_format($b/1024,2).' KB';
  return $b.' B';
}

/* ---------- Probes ---------- */
$probes = [
  'php' => [
    'version' => PHP_VERSION,
    'sapi'    => PHP_SAPI,
    'os'      => PHP_OS_FAMILY . ' ' . PHP_OS,
    'extensions' => [
      'pdo'        => extension_loaded('PDO'),
      'pdo_mysql'  => extension_loaded('pdo_mysql'),
      'mbstring'   => extension_loaded('mbstring'),
      'json'       => extension_loaded('json'),
      'openssl'    => extension_loaded('openssl'),
      'gd_or_imagick' => (extension_loaded('gd') || extension_loaded('imagick')),
      'curl'       => extension_loaded('curl'),
      'fileinfo'   => extension_loaded('fileinfo'),
      'zip'        => extension_loaded('zip'),
      'intl'       => extension_loaded('intl'),
      'opcache'    => function_exists('opcache_get_status'),
    ],
    'ini' => [
      'memory_limit'       => ini_get('memory_limit'),
      'post_max_size'      => ini_get('post_max_size'),
      'upload_max_filesize'=> ini_get('upload_max_filesize'),
      'max_execution_time' => ini_get('max_execution_time'),
    ],
  ],
  'env' => [
    'https'      => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443),
    'app_debug'  => defined('APP_DEBUG') ? APP_DEBUG : null,
    'base_url'   => defined('BASE_URL') ? BASE_URL : '',
    'server'     => ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
    'host'       => ($_SERVER['HTTP_HOST'] ?? 'cli'),
    'time'       => date('Y-m-d H:i:s T'),
  ],
];

/* Database */
$db_ok = false; $db_err = '';
try {
  $pdo->query('SELECT 1')->fetchColumn();
  $db_ok = true;
} catch (Throwable $e) { $db_err = $e->getMessage(); }

/* Key tables & counts (skip if DB down) */
$tables = [
  'users'        => 'SELECT COUNT(*) FROM users',
  'wiki_pages'   => 'SELECT COUNT(*) FROM wiki_pages',
  'chat_messages'=> 'SELECT COUNT(*) FROM chat_messages',
];
$counts = [];
if ($db_ok) {
  foreach ($tables as $t => $sql) {
    try {
      $counts[$t] = (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
      $counts[$t] = null; // missing table
    }
  }
}

/* File system checks */
$base   = dirname(__DIR__);
$mediaD = $base . '/wiki/media';
$paths  = [
  'Root dir'     => ['path' => $base,   'want_write' => false],
  '/wiki/media'  => ['path' => $mediaD, 'want_write' => true],
];
$fs = [];
foreach ($paths as $label => $p) {
  $path = $p['path'];
  $exists = is_dir($path);
  $isw = $exists ? is_writable($path) : false;
  $fs[$label] = [
    'path'   => $path,
    'exists' => $exists,
    'write'  => $isw,
    'want'   => $p['want_write'],
  ];
}

/* Disk space (best effort) */
$disk = [
  'free'  => @disk_free_space($base),
  'total' => @disk_total_space($base),
];

/* Session cookies – best-effort hints */
$cookie = session_get_cookie_params();
$sessionHints = [
  'cookie_secure'   => !empty($cookie['secure']),
  'cookie_httponly' => !empty($cookie['httponly']),
  'cookie_samesite' => ($cookie['samesite'] ?? 'Lax'),
];

/* Opcache brief */
$opcache = ['enabled'=>false,'jit'=>false,'memory'=>null];
if (function_exists('opcache_get_status')) {
  $st = @opcache_get_status(false);
  if (is_array($st)) {
    $opcache['enabled'] = (bool)($st['opcache_enabled'] ?? false);
    $opcache['jit']     = !empty($st['jit'] && $st['jit']['enabled']);
    if (isset($st['memory_usage'])) {
      $opcache['memory'] = [
        'used' => $st['memory_usage']['used_memory'] ?? null,
        'free' => $st['memory_usage']['free_memory'] ?? null,
        'wasted' => $st['memory_usage']['wasted_memory'] ?? null,
      ];
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin — Health · Eldvar</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  <style>
    .health-wrap{display:grid;gap:16px}
    .card-pad{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:16px}
    .kv{display:grid;grid-template-columns:220px 1fr;gap:8px 12px}
    .pill{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid var(--border);font-size:12px}
    .pill.ok{color:#9fe7a9;border-color:rgba(119,221,119,.35);background:rgba(119,221,119,.08)}
    .pill.bad{color:#f9a6a6;border-color:rgba(255,80,80,.35);background:rgba(255,80,80,.08)}
    .muted{color:var(--muted)}
    code{background:var(--panel-2);border:1px solid var(--border);padding:2px 6px;border-radius:6px}
    .grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
    @media (max-width:1000px){.grid-2{grid-template-columns:1fr}}
    table.hl{width:100%;border-collapse:collapse}
    table.hl th, table.hl td{border:1px solid var(--border);padding:8px 10px;text-align:left}
    table.hl th{background:var(--panel-2);color:var(--accent-2)}
  </style>
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <main class="app-main">
      <div class="health-wrap">

        <section class="card-pad">
          <h1 class="pixel-title" style="margin:0;">System Health</h1>
          <p class="muted">Quick diagnostics for Eldvar. This page is read-only.</p>
        </section>

        <section class="grid-2">
          <!-- Runtime -->
          <div class="card-pad">
            <h2 class="card-title">Runtime</h2>
            <div class="kv">
              <div>PHP Version</div><div><?= htmlspecialchars($probes['php']['version']) ?></div>
              <div>Server API</div><div><?= htmlspecialchars($probes['php']['sapi']) ?></div>
              <div>OS</div><div><?= htmlspecialchars($probes['php']['os']) ?></div>
              <div>HTTPS</div><div><?= boolBadge($probes['env']['https']) ?></div>
              <div>APP_DEBUG</div><div><?= isset($probes['env']['app_debug']) ? boolBadge(!$probes['env']['app_debug'], $probes['env']['app_debug']?'ON':'OFF', $probes['env']['app_debug']?'ON':'OFF') . ' <span class="muted">(prefer OFF in production)</span>' : '<span class="muted">unknown</span>' ?></div>
              <div>Base URL</div><div><code><?= htmlspecialchars($probes['env']['base_url']) ?></code></div>
              <div>Server</div><div><?= htmlspecialchars($probes['env']['server']) ?></div>
              <div>Host</div><div><?= htmlspecialchars($probes['env']['host']) ?></div>
              <div>Time</div><div><?= htmlspecialchars($probes['env']['time']) ?></div>
            </div>
          </div>

          <!-- PHP Extensions -->
          <div class="card-pad">
            <h2 class="card-title">PHP Extensions</h2>
            <div class="kv">
              <?php foreach ($probes['php']['extensions'] as $name => $ok): ?>
                <div><?= htmlspecialchars($name) ?></div><div><?= boolBadge((bool)$ok) ?></div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>

        <section class="grid-2">
          <!-- PHP INI -->
          <div class="card-pad">
            <h2 class="card-title">PHP Limits</h2>
            <table class="hl">
              <thead><tr><th>Setting</th><th>Value</th><th>Bytes</th></tr></thead>
              <tbody>
                <?php foreach ($probes['php']['ini'] as $k=>$v): ?>
                  <tr>
                    <td><?= htmlspecialchars($k) ?></td>
                    <td><code><?= htmlspecialchars((string)$v) ?></code></td>
                    <td><?= size_h(ini_bytes((string)$v)) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Opcache -->
          <div class="card-pad">
            <h2 class="card-title">OPcache</h2>
            <div class="kv">
              <div>Enabled</div><div><?= boolBadge($opcache['enabled']) ?></div>
              <div>JIT</div><div><?= boolBadge((bool)$opcache['jit']) ?></div>
              <?php if ($opcache['memory']): ?>
                <div>Memory (used)</div><div><?= size_h((int)$opcache['memory']['used']) ?></div>
                <div>Memory (free)</div><div><?= size_h((int)$opcache['memory']['free']) ?></div>
                <div>Memory (wasted)</div><div><?= size_h((int)$opcache['memory']['wasted']) ?></div>
              <?php else: ?>
                <div class="muted">Details</div><div class="muted">Not available</div>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <section class="grid-2">
          <!-- Database -->
          <div class="card-pad">
            <h2 class="card-title">Database</h2>
            <div class="kv">
              <div>Status</div><div><?= $db_ok ? boolBadge(true,'Connected') : boolBadge(false,'','Error') ?></div>
              <?php if (!$db_ok): ?>
                <div>Error</div><div class="muted"><code><?= htmlspecialchars($db_err) ?></code></div>
              <?php endif; ?>
            </div>
            <h3 style="margin-top:12px;">Tables</h3>
            <table class="hl">
              <thead><tr><th>Table</th><th>Count</th><th>Exists</th></tr></thead>
              <tbody>
                <?php foreach ($tables as $t => $_sql): 
                  $cnt = $counts[$t] ?? null; ?>
                  <tr>
                    <td><?= htmlspecialchars($t) ?></td>
                    <td><?= is_int($cnt) ? number_format($cnt) : '<span class="muted">n/a</span>' ?></td>
                    <td><?= boolBadge(is_int($cnt)) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- File system -->
          <div class="card-pad">
            <h2 class="card-title">File System</h2>
            <table class="hl">
              <thead><tr><th>Path</th><th>Exists</th><th>Writable</th><th>Required</th></tr></thead>
              <tbody>
                <?php foreach ($fs as $label => $info): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($label) ?></strong><br><code><?= htmlspecialchars($info['path']) ?></code></td>
                    <td><?= boolBadge($info['exists']) ?></td>
                    <td><?= boolBadge($info['write']) ?></td>
                    <td><?= $info['want'] ? '<span class="pill ok">Yes</span>' : '<span class="pill">No</span>' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <div class="kv" style="margin-top:12px;">
              <div>Disk Free</div><div><?= is_numeric($disk['free']) ? size_h((int)$disk['free']) : '<span class="muted">n/a</span>' ?></div>
              <div>Disk Total</div><div><?= is_numeric($disk['total']) ? size_h((int)$disk['total']) : '<span class="muted">n/a</span>' ?></div>
            </div>
          </div>
        </section>

        <!-- Session hints -->
        <section class="card-pad">
          <h2 class="card-title">Session / Cookies</h2>
          <div class="kv">
            <div>cookie_secure</div><div><?= boolBadge($sessionHints['cookie_secure']) ?> <span class="muted">(set when site runs over HTTPS)</span></div>
            <div>cookie_httponly</div><div><?= boolBadge($sessionHints['cookie_httponly']) ?></div>
            <div>cookie_samesite</div><div><code><?= htmlspecialchars((string)$sessionHints['cookie_samesite']) ?></code></div>
          </div>
        </section>

        <!-- Tips -->
        <section class="card-pad">
          <h2 class="card-title">Recommendations</h2>
          <ul class="muted" style="margin:6px 0 0 18px;">
            <li>Ensure <code>/wiki/media</code> is writable for wiki image uploads.</li>
            <li>Keep <code>APP_DEBUG</code> <strong>OFF</strong> in production.</li>
            <li>Use HTTPS so session cookies can be marked <code>Secure</code>.</li>
            <li>Enable OPcache for better PHP performance.</li>
            <li>Back up your database regularly (users / wiki_pages / chat_messages).</li>
          </ul>
        </section>

      </div>
    </main>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
  </div>
</body>
</html>
