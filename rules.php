<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Rules — Eldvar Realms SMP</title>
    <meta name="description" content="Official rules and guidelines for the Eldvar Realms SMP Minecraft server and Discord community.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            image-rendering: pixelated;
            image-rendering: -moz-crisp-edges;
            image-rendering: crisp-edges;
        }

        :root {
            --bg: #0f0f1e;
            --panel: #1a1a2e;
            --border: #16213e;
            --accent: #0f3460;
            --primary: #e94560;
            --secondary: #533483;
            --success: #2eb872;
            --warning: #f39c12;
            --danger: #e74c3c;
            --text: #e0e0e0;
            --muted: #7a7a8c;
        }

        body {
            font-family: 'Courier New', Monaco, Consolas, monospace;
            -webkit-font-smoothing: none;
            -moz-osx-font-smoothing: grayscale;
            background: var(--bg);
            color: var(--text);
            line-height: 1.7;
            min-height: 100vh;
        }

        .wrapper {
            max-width: 960px;
            margin: 0 auto;
            padding: 24px 16px 64px;
        }

        /* ── Header ── */
        .page-header {
            text-align: center;
            padding: 48px 0 32px;
        }

        .page-header h1 {
            font-family: 'Press Start 2P', monospace;
            font-size: 22px;
            color: var(--primary);
            margin-bottom: 12px;
            letter-spacing: 2px;
            text-shadow: 3px 3px 0 rgba(0,0,0,0.6);
        }

        .page-header .tagline {
            font-family: 'Press Start 2P', monospace;
            font-size: 10px;
            color: var(--muted);
            letter-spacing: 1px;
        }

        /* ── Section Titles ── */
        .section-title {
            font-family: 'Press Start 2P', monospace;
            font-size: 14px;
            color: var(--warning);
            text-align: center;
            margin: 48px 0 8px;
            letter-spacing: 1px;
            text-shadow: 2px 2px 0 rgba(0,0,0,0.5);
        }

        .section-subtitle {
            text-align: center;
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 24px;
        }

        /* ── Rule Cards ── */
        .rule-card {
            background: var(--panel);
            border: 4px solid #000;
            box-shadow: 6px 6px 0 rgba(0,0,0,0.6);
            padding: 20px 24px;
            margin-bottom: 20px;
            transition: transform 0.1s;
        }

        .rule-card:hover {
            transform: translate(-1px, -1px);
            box-shadow: 7px 7px 0 rgba(0,0,0,0.6);
        }

        .rule-header {
            display: flex;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 10px;
        }

        .rule-number {
            font-family: 'Press Start 2P', monospace;
            font-size: 11px;
            color: var(--primary);
            white-space: nowrap;
            flex-shrink: 0;
        }

        .rule-title {
            font-family: 'Press Start 2P', monospace;
            font-size: 11px;
            color: var(--text);
            line-height: 1.6;
        }

        .rule-body {
            color: var(--muted);
            font-size: 14px;
            padding-left: 0;
            line-height: 1.8;
        }

        .rule-body ul {
            list-style: none;
            padding-left: 8px;
            margin-top: 8px;
        }

        .rule-body ul li {
            position: relative;
            padding-left: 20px;
            margin-bottom: 4px;
        }

        .rule-body ul li::before {
            content: '>';
            position: absolute;
            left: 0;
            color: var(--primary);
            font-weight: bold;
        }

        .rule-body .note {
            margin-top: 10px;
            padding: 10px 14px;
            background: rgba(233, 69, 96, 0.08);
            border-left: 4px solid var(--primary);
            font-size: 13px;
            color: var(--text);
        }

        .rule-body .allowed {
            border-left-color: var(--success);
            background: rgba(46, 184, 114, 0.08);
        }

        /* ── Mods Table ── */
        .mods-section {
            background: var(--panel);
            border: 4px solid #000;
            box-shadow: 6px 6px 0 rgba(0,0,0,0.6);
            padding: 24px;
            margin-bottom: 20px;
        }

        .mods-section h3 {
            font-family: 'Press Start 2P', monospace;
            font-size: 11px;
            margin-bottom: 16px;
            line-height: 1.6;
        }

        .mods-section h3.allowed-heading {
            color: var(--success);
        }

        .mods-section h3.banned-heading {
            color: var(--danger);
        }

        .mods-section .mods-note {
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 14px;
            font-style: italic;
        }

        .mod-list {
            list-style: none;
            padding: 0;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 24px;
        }

        .mod-list li {
            color: var(--text);
            font-size: 13px;
            padding: 4px 0 4px 20px;
            position: relative;
        }

        .mod-list li::before {
            position: absolute;
            left: 0;
            font-weight: bold;
        }

        .mod-list.allowed-list li::before {
            content: '+';
            color: var(--success);
        }

        .mod-list.banned-list li::before {
            content: 'x';
            color: var(--danger);
        }

        .mod-list li span {
            color: var(--muted);
            font-size: 12px;
        }

        /* ── Extra Info ── */
        .info-bar {
            background: var(--panel);
            border: 4px solid #000;
            box-shadow: 6px 6px 0 rgba(0,0,0,0.6);
            padding: 20px 24px;
            margin-top: 48px;
        }

        .info-bar h3 {
            font-family: 'Press Start 2P', monospace;
            font-size: 11px;
            color: var(--secondary);
            margin-bottom: 12px;
        }

        .info-bar ul {
            list-style: none;
            padding: 0;
        }

        .info-bar ul li {
            padding: 4px 0 4px 20px;
            position: relative;
            color: var(--muted);
            font-size: 14px;
        }

        .info-bar ul li::before {
            content: '*';
            position: absolute;
            left: 0;
            color: var(--warning);
            font-weight: bold;
        }

        /* ── Footer ── */
        .page-footer {
            text-align: center;
            padding: 48px 0 16px;
            color: var(--muted);
            font-size: 12px;
        }

        .page-footer a {
            color: var(--primary);
            text-decoration: none;
        }

        .page-footer a:hover {
            text-decoration: underline;
        }

        /* ── Responsive ── */
        @media (max-width: 640px) {
            .page-header h1 { font-size: 16px; }
            .section-title { font-size: 12px; }
            .rule-title, .rule-number { font-size: 9px; }
            .mod-list { grid-template-columns: 1fr; }
            .rule-card { padding: 16px; }
        }
    </style>
</head>
<body>

<div class="wrapper">

    <!-- ═══════════ HEADER ═══════════ -->
    <header class="page-header">
        <h1>Eldvar Realms SMP</h1>
        <p class="tagline">Official Server Rules</p>
    </header>

    <!-- ═══════════ SERVER GUIDELINES ═══════════ -->
    <h2 class="section-title">Server Guidelines</h2>
    <p class="section-subtitle">These rules serve as guidelines. Staff may interpret and enforce each rule at their discretion.<br>Players must not undermine the spirit of fair play.</p>

    <?php
    $serverRules = [
        [
            'title' => 'No Spamming or Flooding Chat',
            'body'  => 'Keep the in-game chat readable for everyone. Repeating messages, sending walls of text, or otherwise cluttering chat is prohibited. This also covers excessive whining, begging, or complaining.',
        ],
        [
            'title' => 'No Advertising External Links or Servers',
            'body'  => 'Only links directly related to Eldvar Realms are permitted — such as our website, store, or Discord. Sharing links to other servers, communities, or unrelated websites is not allowed.',
        ],
        [
            'title' => 'No Disrespect or Discrimination',
            'body'  => 'Treat every player and staff member with basic respect. Harassment, targeted insults, and any form of discrimination will be met with appropriate punishment.',
        ],
        [
            'title' => 'Keep Swearing to a Minimum',
            'body'  => 'Eldvar Realms welcomes players of all ages. Excessive or aggressive use of profanity in any in-game chat channel is not acceptable. Keep language reasonable so everyone can enjoy the community.',
        ],
        [
            'title' => 'No Excessive NSFW Content',
            'body'  => 'Because our community is open to all age groups, sharing or discussing graphic or inappropriate content is not tolerated. Keep things clean — violations will be punished at staff discretion.',
        ],
        [
            'title' => 'No Racist Remarks or Content',
            'body'  => 'Racism of any kind is strictly prohibited. Every player deserves to be treated equally. Racist content will be removed immediately and offenders will be punished by staff.',
        ],
        [
            'title' => 'No DDoS Threats',
            'body'  => 'Threatening any community member — players or staff — with DDoS attacks or any form of network disruption is forbidden. Anyone caught making such threats will be banned.',
        ],
        [
            'title' => 'No TP Trapping',
            'body'  => 'Deceiving a player into teleporting to you with the intent to kill them is not allowed. This includes luring someone into a confined space, a trap, or a fatal fall via teleport.',
            'note'  => 'Inviting a player to teleport for a consensual fight is fine, as long as there is adequate space for both players to engage.',
            'noteType' => 'allowed',
        ],
        [
            'title' => 'No Ban or Mute Evasion',
            'body'  => 'If you have been banned or muted — even temporarily — do not attempt to circumvent it with an alternate account or any other method. If you believe your punishment was unjust, open a ticket on our Discord. Evasion may result in an extended ban duration.',
        ],
        [
            'title' => 'No Hacked Clients or Illegal Modifications',
            'body'  => 'Fair play is non-negotiable. Using any client modification that gives you an unfair advantage will result in a ban. Refer to the Allowed / Disallowed Modifications list below for specifics.',
            'note'  => 'Lunar Client and Feather Client are allowed.',
            'noteType' => 'allowed',
        ],
        [
            'title' => 'No Duplication, Glitching, or Exploiting — Report Bugs Instead',
            'body'  => 'If you discover a bug or duplication glitch, report it to staff immediately. Abusing any exploit will result in an immediate ban. Exploiting includes but is not limited to:',
            'list'  => [
                'Killing or harming a player who has PvP disabled',
                'Using game mechanics to attack players inside safe zones',
                'Unclaiming an existing claim to kill its owner',
            ],
        ],
        [
            'title' => 'No Chargebacks on Store Purchases',
            'body'  => 'All purchases on the Eldvar Realms store are final, as stated in our Terms of Service. Filing a chargeback will result in an immediate and permanent blacklist across all Eldvar Realms services, with no option to appeal.',
        ],
        [
            'title' => 'No Scamming',
            'body'  => 'Scamming of any kind is forbidden. This includes renaming items to mislead buyers on the Auction House, lying about the terms of a trade, or any other deceptive exchange of goods.',
        ],
        [
            'title' => 'No Real-Money Trading Outside Official Channels',
            'body'  => 'In-game trading for real-world value may only be conducted through our official server currency. Staff are available to middleman these transactions. Trades through third-party payment services (PayPal, Venmo, etc.) are strictly prohibited.',
        ],
        [
            'title' => 'No Chat Disruption or Drama-Baiting',
            'body'  => 'We strive to keep chat a positive space for everyone. Deliberately stirring up arguments, provoking other players, or creating unnecessary drama will not be tolerated.',
        ],
        [
            'title' => 'Alt Account Limit',
            'body'  => 'You may have up to 3 accounts online simultaneously for AFK purposes. Additional restrictions apply:',
            'list'  => [
                'Maximum of 2 accounts for a Key All per player',
                'Only 1 account may claim Tutorial Rewards per player',
                'Only 1 account may link to Discord per player',
                'Only 1 account is permitted in the AFK Tower / Lounge areas',
            ],
        ],
        [
            'title' => 'No Toxicity',
            'body'  => 'Toxic behaviour directed at players, staff, or the community at large is not tolerated. We want Eldvar Realms to be an enjoyable experience for everyone. Toxic conduct will be punished accordingly.',
        ],
        [
            'title' => 'No Printer — Schematica Only',
            'body'  => 'The "Printer" feature is not allowed on this server. You may use Schematica or Litematica in manual placement mode. If evidence of printer usage is found, you will be banned.',
        ],
        [
            'title' => 'No Auto-Clickers or Automated Interaction',
            'body'  => 'Auto-clickers, macros, scripts, or any tool that automates gameplay actions are not permitted. This includes the use of F3 + T to gain an unfair advantage. Offenders will be punished by staff.',
        ],
        [
            'title' => 'No X-Ray',
            'body'  => 'X-Ray resource packs, mods, seed finders, or any method used to locate ores, structures, or rare items (such as diamonds or ancient debris) that are normally hidden are banned. If caught, your items will be wiped and you will be banned.',
        ],
        [
            'title' => 'Griefing and Stealing',
            'body'  => 'Every claim is entitled to a 15-block buffer zone. No other player may claim, build, or steal within 15 blocks of someone else\'s claim unless given explicit permission. Players granted /trust on a claim assume full risk — the claim owner is responsible for managing access.',
            'list'  => [
                'Griefing or stealing will result in a temporary ban',
                'Unauthorized claiming within the buffer will result in a warning and removal of the offending claim',
                'Filling another player\'s Chunk Collector counts as griefing',
            ],
        ],
    ];

    foreach ($serverRules as $i => $rule): ?>
        <div class="rule-card">
            <div class="rule-header">
                <span class="rule-number">#<?= $i + 1 ?></span>
                <span class="rule-title"><?= htmlspecialchars($rule['title']) ?></span>
            </div>
            <div class="rule-body">
                <p><?= htmlspecialchars($rule['body']) ?></p>
                <?php if (!empty($rule['list'])): ?>
                    <ul>
                        <?php foreach ($rule['list'] as $item): ?>
                            <li><?= htmlspecialchars($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if (!empty($rule['note'])): ?>
                    <div class="note <?= ($rule['noteType'] ?? '') === 'allowed' ? 'allowed' : '' ?>">
                        <?= htmlspecialchars($rule['note']) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- ═══════════ ALLOWED / DISALLOWED MODS ═══════════ -->
    <h2 class="section-title">Modifications</h2>
    <p class="section-subtitle">Allowed and disallowed client modifications for Eldvar Realms.</p>

    <div class="mods-section">
        <h3 class="allowed-heading">Allowed Mods — Use at Your Own Risk</h3>
        <p class="mods-note">These mods are permitted as long as they are not abused or used to gain an unfair advantage.</p>
        <ul class="mod-list allowed-list">
            <li>OptiFine <span>— Performance &amp; zoom</span></li>
            <li>MiniMap (No Entities) <span>— Terrain only</span></li>
            <li>Litematica <span>— Manual placement only</span></li>
            <li>Replay Mod <span>— Recording &amp; playback</span></li>
            <li>Lunar Client</li>
            <li>Badlion Client</li>
            <li>Feather Client <span>— Subject to change</span></li>
            <li>Forge / Fabric / OptiFabric</li>
            <li>Sodium <span>— Performance</span></li>
            <li>Iris Shaders <span>— Shader support</span></li>
            <li>Better Hurt Cam <span>— Visual tweak</span></li>
            <li>ToggleSprint <span>— QoL</span></li>
            <li>Armor &amp; Inventory HUD</li>
            <li>AppleSkin <span>— Saturation display</span></li>
        </ul>
    </div>

    <div class="mods-section">
        <h3 class="banned-heading">Disallowed Mods — Zero Tolerance</h3>
        <p class="mods-note">Using any of the following will result in an immediate ban. No exceptions.</p>
        <ul class="mod-list banned-list">
            <li>Baritone <span>— Automated movement</span></li>
            <li>Freecam <span>— Unauthorized camera</span></li>
            <li>Tweakeroo <span>— Includes macros</span></li>
            <li>Autotool <span>— Auto tool-switching</span></li>
            <li>Wurst Client <span>— Hacked client</span></li>
            <li>Ghost Clients <span>— Stealth cheats</span></li>
            <li>Meteor Client <span>— Hacked client</span></li>
            <li>Macros of Any Kind <span>— Scripts, keybinds</span></li>
            <li>Invmove <span>— Move in inventory</span></li>
        </ul>
    </div>

    <!-- ═══════════ DISCORD GUIDELINES ═══════════ -->
    <h2 class="section-title">Discord Guidelines</h2>
    <p class="section-subtitle">Rules that apply to the official Eldvar Realms Discord server.</p>

    <?php
    $discordRules = [
        [
            'title' => 'No Spamming or Flooding',
            'body'  => 'Keep every channel clean and easy to read. Do not send repetitive messages, walls of text, or otherwise clutter any channel.',
        ],
        [
            'title' => 'No External Advertising',
            'body'  => 'Only official Eldvar Realms links (website, store, Discord) may be shared. Promoting other servers, communities, or unrelated websites is prohibited.',
        ],
        [
            'title' => 'No NSFW Content',
            'body'  => 'Eldvar Realms maintains a PG-13 standard. Any inappropriate, graphic, or sexually explicit content will be removed immediately, and offenders will be punished.',
        ],
        [
            'title' => 'Keep Swearing to a Minimum',
            'body'  => 'Our Discord is open to all ages. Please keep profanity at a reasonable level so the community remains welcoming for everyone.',
        ],
        [
            'title' => 'No Chat Disruption or Drama',
            'body'  => 'Maintain a positive and constructive atmosphere in every channel. Deliberately provoking arguments or stirring up drama will result in punishment.',
        ],
        [
            'title' => 'No DDoS Threats',
            'body'  => 'Threatening any community member with DDoS attacks or any form of network disruption is strictly forbidden and will lead to an immediate ban.',
        ],
        [
            'title' => 'No Inappropriate Nicknames',
            'body'  => 'Your Discord display name must be appropriate for all ages. If staff ask you to change an inappropriate name and you refuse, you will be punished.',
        ],
        [
            'title' => 'No DM Advertising',
            'body'  => 'Do not send unsolicited direct messages promoting other Discord servers, websites, or NSFW content. Only official Eldvar Realms links are permitted. Violations will result in a ban.',
        ],
        [
            'title' => 'Keep All Messages PG-13',
            'body'  => 'Our community is designed to be appropriate for every age group. Racist language, excessive swearing, and any inappropriate content will be removed and the offender will face disciplinary action.',
        ],
        [
            'title' => 'Do Not Ping Owners or Admins',
            'body'  => 'If you need help, the best approach is to open a ticket in our support channel for the fastest response. For private matters, send a direct message instead of pinging.',
        ],
    ];

    foreach ($discordRules as $i => $rule): ?>
        <div class="rule-card">
            <div class="rule-header">
                <span class="rule-number">#<?= $i + 1 ?></span>
                <span class="rule-title"><?= htmlspecialchars($rule['title']) ?></span>
            </div>
            <div class="rule-body">
                <p><?= htmlspecialchars($rule['body']) ?></p>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- ═══════════ ADDITIONAL INFO ═══════════ -->
    <div class="info-bar">
        <h3>Additional Information</h3>
        <ul>
            <li>Alt Limit: 5 accounts per IP address</li>
            <li>English only in main chat</li>
            <li>Staff Applications: Currently closed — stay tuned for future openings</li>
        </ul>
    </div>

    <footer class="page-footer">
        <p>&copy; <?= date('Y') ?> Eldvar Realms. All rights reserved.</p>
    </footer>

</div>

</body>
</html>
