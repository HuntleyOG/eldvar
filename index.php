<?php
declare(strict_types=1);
require __DIR__ . '/config/config.php'; // defines BASE_URL
if (session_status() === PHP_SESSION_NONE) { session_start(); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Eldvar — A Pixel-Fantasy Text MMO</title>
  <meta name="description" content="Eldvar — a minimalist, pixel-art-flavored text MMO. Train skills, defend Eldralis, and push back the void." />
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
  <style>
    /* Landing-only flair (scoped) */
    .landing-hero{
      position: relative; overflow: hidden; border-radius: 16px;
      border:1px solid var(--border);
      background:
        radial-gradient(1200px 600px at 6% -10%, rgba(119,221,119,.10), transparent 60%),
        radial-gradient(800px 400px at 100% 0%, rgba(160,224,255,.10), transparent 60%),
        linear-gradient(180deg, #151822, #11131a);
      box-shadow: 0 10px 40px rgba(0,0,0,.45);
      display:grid; grid-template-columns: 1.1fr .9fr;
    }
    @media (max-width: 980px){ .landing-hero{ grid-template-columns: 1fr; } }

    .hero-left{ padding: 28px; }
    .hero-right{
      min-height: 260px;
      background:
        linear-gradient(180deg, rgba(12,16,22,.5), rgba(12,16,22,.2)),
        url('<?= BASE_URL ?>/public/images/auth/eldvar-art.jpg') center/cover no-repeat;
      border-left: 1px solid var(--border);
    }
    @media (max-width: 980px){ .hero-right{ display:none; } }

    .feature-strip{
      display:grid; gap:12px; grid-template-columns: repeat(4, minmax(0,1fr));
      margin-top: 16px;
    }
    @media (max-width: 1100px){ .feature-strip{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width: 680px){ .feature-strip{ grid-template-columns: 1fr; } }

    .feature{
      display:flex; gap:10px; align-items:flex-start;
      border:1px solid var(--border); border-radius:12px; background:var(--panel);
      padding:12px;
    }
    .glyph{
      width:28px; height:28px; border-radius:6px;
      display:inline-flex; align-items:center; justify-content:center;
      background: var(--panel-2); border:1px solid var(--border); font-weight:700;
    }

    .world-grid{ display:grid; gap:12px; grid-template-columns: repeat(3, minmax(0,1fr)); }
    @media (max-width: 1100px){ .world-grid{ grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width: 700px){ .world-grid{ grid-template-columns: 1fr; } }
    .world-card{
      border:1px solid var(--border); border-radius:12px; background:var(--panel);
      padding:14px;
    }
    .faq-grid{ display:grid; gap:12px; grid-template-columns: repeat(2, minmax(0,1fr)); }
    @media (max-width: 900px){ .faq-grid{ grid-template-columns: 1fr; } }
    .q{ font-weight:700; }
    .muted a{ color: var(--accent-2); text-decoration: none; }
    .muted a:hover{ text-decoration: underline; }
  </style>
</head>
<body>
  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="app-main">
      <!-- Launcher-style hero -->
      <section class="landing-hero">
        <div class="hero-left">
          <h1 class="pixel-title" style="margin:0 0 6px;">Welcome to Eldvar</h1>
          <p class="lead" style="margin:0 0 14px;">
            Beneath the boughs of the Elder Tree, a violet shard awakened the void.
            Train your skills, climb ancient towers, and push back the darkness.
          </p>
          <div class="hero-actions">
            <?php if (!isset($_SESSION['user_id'])): ?>
              <a class="btn primary" href="<?= BASE_URL ?>/register.php">Create Character</a>
              <a class="btn" href="<?= BASE_URL ?>/login.php">Login</a>
            <?php else: ?>
              <a class="btn primary" href="<?= BASE_URL ?>/dashboard.php">Enter Eldvar</a>
              <a class="btn" href="<?= BASE_URL ?>/logout.php">Logout</a>
            <?php endif; ?>
            <a class="btn" href="<?= BASE_URL ?>/how-to-play.php">Learn the Basics</a>
          </div>

          <!-- Feature strip -->
          <div class="feature-strip">
            <div class="feature">
              <div class="glyph">⚔️</div>
              <div>
                <strong>Turn-based Combat</strong>
                <div class="muted">Melee, ranged, magic—pick your style and outsmart the mobs.</div>
              </div>
            </div>
            <div class="feature">
              <div class="glyph">🗼</div>
              <div>
                <strong>Towers & Floors</strong>
                <div class="muted">Beat required floors, manage void pressure, earn scaled rewards.</div>
              </div>
            </div>
            <div class="feature">
              <div class="glyph">🌲</div>
              <div>
                <strong>Expanding World</strong>
                <div class="muted">Mystic Harshlands, Yulon Forest, Reichal, Undar, Frostbound Tundra.</div>
              </div>
            </div>
            <div class="feature">
              <div class="glyph">🛡️</div>
              <div>
                <strong>Play Your Way</strong>
                <div class="muted">Chill progression with pixel-flavored vibes and community goals.</div>
              </div>
            </div>
          </div>
        </div>
        <div class="hero-right"></div>
      </section>

      <!-- World teaser -->
      <section class="card" style="margin-top:16px;">
        <h2 class="card-title">Explore the World</h2>
        <p class="muted" style="margin-top:0">Each region has unique towers, mob families, and reward curves.</p>
        <div class="world-grid">
          <div class="world-card">
            <strong>Mystic Harshlands</strong>
            <p class="muted">Dust seas, arcane storms, relic caravans.</p>
            <a class="btn" href="<?= BASE_URL ?>/world.php?area=harshlands">Enter</a>
          </div>
          <div class="world-card">
            <strong>Yulon Forest</strong>
            <p class="muted">Ancient groves & whispering sprites.</p>
            <a class="btn" href="<?= BASE_URL ?>/world.php?area=yulon">Enter</a>
          </div>
          <div class="world-card">
            <strong>Reichal</strong>
            <p class="muted">Forgotten wards, rune-scarred ruins.</p>
            <a class="btn" href="<?= BASE_URL ?>/world.php?area=reichal">Enter</a>
          </div>
          <div class="world-card">
            <strong>Undar</strong>
            <p class="muted">Subterranean echoes of the void.</p>
            <a class="btn" href="<?= BASE_URL ?>/world.php?area=undar">Enter</a>
          </div>
          <div class="world-card">
            <strong>Frostbound Tundra</strong>
            <p class="muted">Howling winds, frost giants, aurora nights.</p>
            <a class="btn" href="<?= BASE_URL ?>/world.php?area=frostbound">Enter</a>
          </div>
        </div>
      </section>

      <!-- Learn more / FAQ -->
      <section class="card" style="margin-top:16px;">
        <h2 class="card-title">New to Eldvar?</h2>
        <div class="faq-grid">
          <div>
            <p class="q">How do battles work?</p>
            <p class="muted">Pick an action each turn. Accuracy & damage scale with your stats and regional void pressure.</p>
          </div>
          <div>
            <p class="q">How do I descend floors?</p>
            <p class="muted">Beat the required number of enemies on your current floor, then descend to face tougher foes.</p>
          </div>
          <div>
            <p class="q">What’s the World Map?</p>
            <p class="muted">A growing set of areas. Each area has its own towers and unique enemy pools.</p>
          </div>
          <div>
            <p class="q">Where can I see patch notes?</p>
            <p class="muted">Check <a href="<?= BASE_URL ?>/news.php">News</a> for balance changes and new content drops.</p>
          </div>
        </div>
        <div class="hero-actions" style="margin-top:12px;">
          <?php if (!isset($_SESSION['user_id'])): ?>
            <a class="btn primary" href="<?= BASE_URL ?>/register.php">Create Character</a>
            <a class="btn" href="<?= BASE_URL ?>/login.php">Login</a>
          <?php else: ?>
            <a class="btn primary" href="<?= BASE_URL ?>/dashboard.php">Enter Eldvar</a>
            <a class="btn" href="<?= BASE_URL ?>/logout.php">Logout</a>
          <?php endif; ?>
        </div>
      </section>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>
</body>
</html>
