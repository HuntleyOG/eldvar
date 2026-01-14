import { PrismaClient } from '@prisma/client';

const prisma = new PrismaClient();

// OSRS-style XP table calculation
function generateXpTable(maxLevel: number = 99): { level: number; xpRequired: number }[] {
  const xpTable: { level: number; xpRequired: number }[] = [{ level: 1, xpRequired: 0 }];
  let points = 0;

  for (let level = 1; level <= maxLevel; level++) {
    points += Math.floor(level + 300 * Math.pow(2, level / 7));
    const xpRequired = Math.floor(points / 4);
    xpTable.push({ level, xpRequired });
  }

  return xpTable;
}

async function main() {
  console.log('🌱 Seeding database...');

  // 1. Create Skills
  console.log('Creating skills...');
  const skills = [
    { skey: 'attack', name: 'Attack' },
    { skey: 'strength', name: 'Strength' },
    { skey: 'defense', name: 'Defense' },
    { skey: 'health', name: 'Health' },
    { skey: 'range', name: 'Range' },
    { skey: 'magic', name: 'Magic' },
    { skey: 'pathfinding', name: 'Pathfinding' },
    { skey: 'mining', name: 'Mining' },
    { skey: 'smithing', name: 'Smithing' },
    { skey: 'crafting', name: 'Crafting' },
  ];

  for (const skill of skills) {
    await prisma.skill.upsert({
      where: { skey: skill.skey },
      update: {},
      create: skill,
    });
  }
  console.log(`✅ Created ${skills.length} skills`);

  // 2. Create XP Thresholds
  console.log('Creating XP thresholds...');
  const xpTable = generateXpTable(99);
  for (const entry of xpTable) {
    await prisma.xpThreshold.upsert({
      where: { level: entry.level },
      update: { xpRequired: entry.xpRequired },
      create: entry,
    });
  }
  console.log(`✅ Created ${xpTable.length} XP thresholds`);

  // 3. Create Game Settings
  console.log('Creating game settings...');
  const gameSettings = [
    { key: 'wins_required_per_floor', value: '3' },
    { key: 'void_step_per_floor', value: '3' },
    { key: 'void_cap_percent', value: '60' },
    { key: 'player_acc_pen_divisor', value: '5.0' },
    { key: 'player_dmg_min_multiplier', value: '0.70' },
    { key: 'player_dmg_divisor', value: '200.0' },
    { key: 'mob_dmg_divisor', value: '200.0' },
    { key: 'reward_xp_per_floor_pct', value: '5.0' },
    { key: 'reward_gold_per_floor_pct', value: '4.0' },
  ];

  for (const setting of gameSettings) {
    await prisma.gameSetting.upsert({
      where: { key: setting.key },
      update: { value: setting.value },
      create: setting,
    });
  }
  console.log(`✅ Created ${gameSettings.length} game settings`);

  // 4. Create World Areas
  console.log('Creating world areas...');
  const areas = [
    {
      slug: 'mystic-harshlands',
      name: 'Mystic Harshlands',
      shortBlurb: 'A desolate wasteland where magic runs wild',
    },
    {
      slug: 'yulon-forest',
      name: 'Yulon Forest',
      shortBlurb: 'Ancient woods teeming with mysterious creatures',
    },
    {
      slug: 'reichal',
      name: 'Reichal',
      shortBlurb: 'The once-great kingdom now in ruins',
    },
    {
      slug: 'undar',
      name: 'Undar',
      shortBlurb: 'Underground caverns filled with treasures and dangers',
    },
    {
      slug: 'frostbound-tundra',
      name: 'Frostbound Tundra',
      shortBlurb: 'Frozen wastes where only the strong survive',
    },
  ];

  for (const area of areas) {
    await prisma.worldArea.upsert({
      where: { slug: area.slug },
      update: {},
      create: area,
    });
  }
  console.log(`✅ Created ${areas.length} world areas`);

  // 5. Create Sample Mobs
  console.log('Creating sample mobs...');
  const mobs = [
    {
      name: 'Goblin Scout',
      level: 1,
      hp: 30,
      attack: 5,
      defense: 3,
      magic: 1,
      range: 2,
      rewardXp: 50,
      rewardGold: 10,
      minFloor: 1,
      maxFloor: 5,
    },
    {
      name: 'Dark Wolf',
      level: 3,
      hp: 50,
      attack: 8,
      defense: 5,
      magic: 2,
      range: 3,
      rewardXp: 75,
      rewardGold: 15,
      minFloor: 3,
      maxFloor: 10,
    },
    {
      name: 'Shadow Mage',
      level: 5,
      hp: 70,
      attack: 6,
      defense: 4,
      magic: 12,
      range: 4,
      rewardXp: 100,
      rewardGold: 25,
      minFloor: 5,
      maxFloor: 15,
    },
    {
      name: 'Frost Giant',
      level: 10,
      hp: 150,
      attack: 20,
      defense: 15,
      magic: 5,
      range: 8,
      rewardXp: 250,
      rewardGold: 50,
      minFloor: 10,
      maxFloor: 25,
    },
    {
      name: 'Void Wraith',
      level: 20,
      hp: 300,
      attack: 40,
      defense: 30,
      magic: 45,
      range: 35,
      rewardXp: 500,
      rewardGold: 100,
      minFloor: 20,
      maxFloor: 999,
    },
  ];

  for (const mob of mobs) {
    await prisma.mob.create({ data: mob });
  }
  console.log(`✅ Created ${mobs.length} sample mobs`);

  console.log('🎉 Seeding completed!');
}

main()
  .catch((e) => {
    console.error('❌ Seeding failed:', e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
