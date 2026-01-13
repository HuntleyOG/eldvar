<?php
declare(strict_types=1);

// Public page — don't force auth redirect from shared includes
define('PUBLIC_PAGE', true);

require __DIR__ . '/config/config.php'; // BASE_URL
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$is_logged_in = isset($_SESSION['user_id']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>How to Play — Eldvar</title>
  <meta name="description" content="Learn how to play Eldvar — combat basics, progression, world map, towers, and tips." />
  <link rel="stylesheet" href="<?= BASE_URL ?>/public/css/style.css">
</head>
<body class="with-sidebar">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <div id="backdrop" class="backdrop"></div>

  <div class="app-shell">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="app-main">
      <!-- Intro / CTA -->
      <section class="hero">
        <div class="hero-card">
          <h1 class="pixel-title">How to Play</h1>
          <p class="lead">
            New to Eldvar? This quick guide covers the essentials: adventuring, battling monsters,
            unlocking towers, and traveling the world. You’ll be swinging steel (or hurling sparks)
            in no time.
          </p>
          <div class="hero-actions">
            <?php if ($is_logged_in): ?>
              <a class="btn primary" href="<?= BASE_URL ?>/dashboard.php">Enter the World</a>
              <a class="btn" href="<?= BASE_URL ?>/battle.php">Start a Battle</a>
              <a class="btn" href="<?= BASE_URL ?>/town.php">Visit Town</a>
            <?php else: ?>
              <a class="btn primary" href="<?= BASE_URL ?>/register.php">Create Character</a>
              <a class="btn" href="<?= BASE_URL ?>/login.php">Login</a>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <!-- Guide Content -->
      <article class="wiki-article">
        <div class="wiki-toc">
          <h3>Table of Contents</h3>
          <ul>
            <li><a href="#overview">1. Overview</a></li>
            <li><a href="#controls">2. Basic Controls</a></li>
            <li><a href="#combat">3. Combat & Turns</a></li>
            <li><a href="#progression">4. Progression & Floors</a></li>
            <li><a href="#world">5. World Map & Areas</a></li>
            <li><a href="#rewards">6. Rewards & Economy</a></li>
            <li><a href="#town">7. Town, Tavern & Chat</a></li>
            <li><a href="#safety">8. Safety & Tips</a></li>
            <li><a href="#faq">9. FAQ</a></li>
          </ul>
        </div>

        <h1 id="overview">1. Overview</h1>
        <p>
          Eldvar is a turn-based, text-forward MMO where you explore regions, battle monsters, and
          climb towers floor by floor. As you win fights, you earn <strong>XP</strong> and <strong>gold</strong>,
          unlock new floors, and discover new <em>areas</em> with unique towers and enemies.
        </p>

        <h2 id="controls">2. Basic Controls</h2>
        <ul>
          <li><strong>Sidebar:</strong> Navigate between <em>Dashboard</em>, <em>Battle</em>, <em>Town</em>, <em>Tavern</em>, <em>World</em> and more.</li>
          <li><strong>Buttons:</strong> Actions like <em>Fight</em>, <em>Attack</em>, <em>Descend</em> appear contextually.</li>
          <li><strong>Tooltips:</strong> Hover or check muted hints under fields for quick help.</li>
        </ul>

        <h2 id="combat">3. Combat & Turns</h2>
        <p>
          Battles are turn-based. Choose an action each round:
        </p>
        <ul>
          <li><strong>⚔️ Melee</strong> — dependable damage based on <em>Attack</em> and <em>Strength</em>.</li>
          <li><strong>🏹 Ranged</strong> — lighter but reliable; scales with <em>Range</em>.</li>
          <li><strong>✨ Magic</strong> — arcane strikes; scales with <em>Magic</em>.</li>
        </ul>
        <p>
          You and the monster trade blows until one drops to 0 HP. Win to earn rewards; lose and try again.
          Some encounters apply <em>Void Pressure</em>, which can affect hit chance and damage multipliers at higher floors.
        </p>

        <h2 id="progression">4. Progression & Floors</h2>
        <p>
          Each tower is divided into <strong>floors</strong>. Defeat a set number of enemies on your current floor to
          unlock the <em>Descend</em> action. Floors get tougher but pay better. Previously completed floors can be revisited.
        </p>
        <ul>
          <li><strong>Wins to Descend:</strong> Shown in the battle UI as <em>Progress: X / Y</em>.</li>
          <li><strong>Deepest Floor:</strong> Your personal best; used to unlock quick travel within a tower.</li>
        </ul>

        <h2 id="world">5. World Map & Areas</h2>
        <p>
          Eldvar’s world features distinct <strong>areas</strong>, each with their own towers and atmosphere. Early areas include:
        </p>
        <ul>
          <li><strong>Mystic Harshlands</strong> — arid mesas, rare elementals, high risk/high reward.</li>
          <li><strong>Yulon Forest</strong> — lush woodlands with balanced early-mid towers.</li>
          <li><strong>Reichal</strong> — ancient ruins and technical foes with trickier defenses.</li>
          <li><strong>Undar</strong> — subterranean caverns, hardy beasts, and ore-rich rewards.</li>
          <li><strong>Frostbound Tundra</strong> — brutal cold, resilient enemies, premium loot.</li>
        </ul>
        <p>
          Use the <em>World</em> or <em>Maps</em> screen to select an area and then a tower. Some areas or towers may
          require certain levels or prior completions.
        </p>

        <h2 id="rewards">6. Rewards & Economy</h2>
        <p>
          Victories reward <strong>XP</strong> (to level up and improve stats) and <strong>gold</strong> (to spend in town as features unlock).
          Higher floors and harsher areas increase rewards. Some towers offer unique drops or currencies (coming soon).
        </p>

        <h2 id="town">7. Town, Tavern & Chat</h2>
        <p>
          Between battles, head to <strong>Town</strong> for utilities and NPCs (as systems unlock). The
          <strong>Tavern</strong> is a bulletin board to post quick notes, trade offers, or party requests.
          Global <strong>Chat</strong> lets you talk to other players in real time.
        </p>

        <h2 id="safety">8. Safety & Tips</h2>
        <ul>
          <li>Take it slow on new floors; learn enemy patterns and damage ranges.</li>
          <li>Use your best combat style against specific mob defenses (e.g., high DEF vs high EVA).</li>
          <li>If you’re struggling, revisit lower floors for steady XP/gold.</li>
          <li>Keep an eye on Void Pressure at higher floors — it can impact accuracy and damage.</li>
        </ul>

        <h2 id="faq">9. FAQ</h2>
        <h3>Is Eldvar pay-to-win?</h3>
        <p>No. Progress comes from play, not payment.</p>

        <h3>What happens if I lose a fight?</h3>
        <p>You’ll end the battle and can try again immediately. No permanent penalties.</p>

        <h3>How do I unlock new areas?</h3>
        <p>By meeting prerequisites (level, prior floors, or quest flags when implemented). Check the World/Maps screen.</p>

        <h3>Where do I see my stats?</h3>
        <p>Open <em>Battle</em> to see combat-relevant stats, and <em>Dashboard</em> for account-level info. More detailed sheets are coming.</p>

        <div class="alert info" style="margin-top:14px;">
          Want to dive in now?
          <?php if ($is_logged_in): ?>
            <a href="<?= BASE_URL ?>/battle.php">Start a battle</a> or <a href="<?= BASE_URL ?>/maps.php">open the World Map</a>.
          <?php else: ?>
            <a href="<?= BASE_URL ?>/register.php">Create your character</a> or <a href="<?= BASE_URL ?>/login.php">log in</a> to begin.
          <?php endif; ?>
        </div>
      </article>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
  </div>
</body>
</html>
