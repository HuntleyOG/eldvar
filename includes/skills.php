<?php
// /includes/skills.php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/** Canonical skill map (stable IDs must match DB seed) */
function skill_map(): array {
  return [
    1 => ['key'=>'attack','name'=>'Attack'],
    2 => ['key'=>'strength','name'=>'Strength'],
    3 => ['key'=>'defense','name'=>'Defense'],
    4 => ['key'=>'health','name'=>'Health'],
    5 => ['key'=>'range','name'=>'Range'],
    6 => ['key'=>'magic','name'=>'Magic'],
    7 => ['key'=>'mining','name'=>'Mining'],
    8 => ['key'=>'crafting','name'=>'Crafting'],
    9 => ['key'=>'blacksmithing','name'=>'Blacksmithing'],
  ];
}
function skill_id_by_key(string $key): ?int {
  foreach (skill_map() as $id=>$m) if ($m['key']===$key) return $id;
  return null;
}

/** OSRS-like XP table */
function xp_table(int $maxLevel = 99): array {
  static $cache = [];
  if (isset($cache[$maxLevel])) return $cache[$maxLevel];
  $xp=[0]; $points=0;
  for ($lvl=1; $lvl<=$maxLevel; $lvl++){
    $points += floor($lvl + 300 * pow(2, $lvl/7));
    $xp[$lvl] = (int)floor($points/4);
  }
  return $cache[$maxLevel] = $xp;
}
function level_from_xp(int $xp, int $maxLevel = 99): int {
  $tab = xp_table($maxLevel); $lvl=1;
  for ($i=2; $i<=$maxLevel; $i++) {
    if ($xp >= $tab[$i]) $lvl=$i; else break;
  }
  return $lvl;
}
function xp_for_level(int $level, int $maxLevel = 99): int {
  $level = max(1, min($level, $maxLevel));
  return xp_table($maxLevel)[$level];
}

/** Ensure a user has rows for all skills */
function ensure_user_skills(PDO $pdo, int $userId): void {
  $ids = array_keys(skill_map());
  $values = implode(',', array_fill(0, count($ids), '(?,?,1,0,NOW())'));
  $args = [];
  foreach ($ids as $sid){ $args[]=$userId; $args[]=$sid; }
  $pdo->prepare("INSERT IGNORE INTO user_skills (user_id, skill_id, level, xp, updated_at) VALUES $values")->execute($args);
}

/** Get all skills keyed by skill key */
function get_user_skills(PDO $pdo, int $userId): array {
  $st = $pdo->prepare("SELECT s.id, s.skey, s.name, COALESCE(us.level,1) level, COALESCE(us.xp,0) xp
                       FROM skills s
                       LEFT JOIN user_skills us ON us.skill_id=s.id AND us.user_id=?
                       ORDER BY s.id");
  $st->execute([$userId]);
  $out=[];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r){
    $out[$r['skey']] = ['id'=>(int)$r['id'],'name'=>$r['name'],'level'=>(int)$r['level'],'xp'=>(int)$r['xp']];
  }
  return $out;
}

/** Add XP to one skill; returns old/new levels and new xp */
function add_skill_xp(PDO $pdo, int $userId, string $skillKey, int $xpGain, int $maxLevel = 99): array {
  $xpGain = max(0, $xpGain);
  $sid = skill_id_by_key($skillKey);
  if (!$sid) throw new RuntimeException("Unknown skill: $skillKey");

  $pdo->beginTransaction();
  try {
    $pdo->prepare("INSERT IGNORE INTO user_skills (user_id, skill_id, level, xp) VALUES (?,?,1,0)")
        ->execute([$userId,$sid]);
    $st = $pdo->prepare("SELECT level, xp FROM user_skills WHERE user_id=? AND skill_id=? FOR UPDATE");
    $st->execute([$userId,$sid]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['level'=>1,'xp'=>0];

    $oldLvl = (int)$row['level'];
    $xp = (int)$row['xp'] + $xpGain;
    $newLvl = level_from_xp($xp, $maxLevel);
    if ($newLvl > $maxLevel) $newLvl = $maxLevel;

    $pdo->prepare("UPDATE user_skills SET xp=?, level=? WHERE user_id=? AND skill_id=?")
        ->execute([$xp,$newLvl,$userId,$sid]);

    // track overall
    if (column_exists_safe($pdo,'users','total_xp')) {
      $pdo->prepare("UPDATE users SET total_xp = total_xp + ? WHERE id=?")->execute([$xpGain,$userId]);
    }

    $pdo->commit();
    return ['old_level'=>$oldLvl,'new_level'=>$newLvl,'gained'=>$xpGain,'new_xp'=>$xp,'skill_key'=>$skillKey];
  } catch (Throwable $e){
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

/** Derive combat stats from skill levels */
function derive_player_combat(array $skills): array {
  $atk = (int)($skills['attack']['level'] ?? 1);
  $str = (int)($skills['strength']['level'] ?? 1);
  $def = (int)($skills['defense']['level'] ?? 1);
  $rng = (int)($skills['range']['level'] ?? 1);
  $mag = (int)($skills['magic']['level'] ?? 1);
  $hp  = (int)($skills['health']['level'] ?? 1);

  $hpBase = 40;
  $hpMax  = $hpBase + ($hp * 10);

  return [
    'attack'       => 1 + (int)floor($atk * 1.2),
    'strength'     => 1 + (int)floor($str * 1.2),
    'defense'      => 1 + (int)floor($def * 1.2),
    'range'        => 1 + (int)floor($rng * 1.1),
    'magic'        => 1 + (int)floor($mag * 1.1),
    'hp_max'       => $hpMax,
    'melee_power'  => (int)floor(($atk + $str) * 0.9),
    'ranged_power' => (int)floor($rng * 1.0),
    'magic_power'  => (int)floor($mag * 1.0),
  ];
}

/** Split victory XP to combat style + health */
function grant_victory_xp(PDO $pdo, int $userId, string $chosenStyle, int $rewardXp): array {
  $chosen = in_array($chosenStyle,['melee','ranged','magic'],true) ? $chosenStyle : 'melee';
  $skill  = $chosen==='melee' ? 'attack' : ($chosen==='ranged' ? 'range' : 'magic');
  $results = [];
  $results[] = add_skill_xp($pdo, $userId, $skill, max(1,$rewardXp));
  $results[] = add_skill_xp($pdo, $userId, 'health', (int)floor(max(1,$rewardXp)/3));
  return $results;
}

/** Safe column existence (used above) */
function column_exists_safe(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $st->execute([$table,$column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable) { return false; }
}
