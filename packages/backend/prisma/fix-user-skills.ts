import { PrismaClient } from '@prisma/client';

const prisma = new PrismaClient();

async function main() {
  console.log('🔧 Fixing users without skills...');

  // Get all users
  const users = await prisma.user.findMany({
    include: {
      skills: true,
    },
  });

  console.log(`Found ${users.length} users`);

  // Get all skills
  const skills = await prisma.skill.findMany();

  if (skills.length === 0) {
    console.log('❌ No skills found in database. Run seed first: pnpm run prisma:seed');
    return;
  }

  console.log(`Found ${skills.length} skills`);

  // Combat skill keys that should start at level 3
  const combatSkillKeys = ['attack', 'strength', 'defense', 'range', 'magic'];

  // Get XP required for level 3
  const level3Threshold = await prisma.xpThreshold.findUnique({
    where: { level: 3 },
  });
  const level3Xp = level3Threshold?.xpRequired || 0;

  let fixedCount = 0;

  for (const user of users) {
    if (user.skills.length === 0) {
      console.log(`Fixing user: ${user.username} (ID: ${user.id})`);

      await prisma.userSkill.createMany({
        data: skills.map((skill) => {
          const isCombatSkill = combatSkillKeys.includes(skill.skey.toLowerCase());
          return {
            userId: user.id,
            skillId: skill.id,
            level: isCombatSkill ? 3 : 1,
            xp: isCombatSkill ? level3Xp : 0,
          };
        }),
      });

      fixedCount++;
    } else if (user.skills.length < skills.length) {
      // User has some skills but not all
      const existingSkillIds = user.skills.map(s => s.skillId);
      const missingSkills = skills.filter(s => !existingSkillIds.includes(s.id));

      if (missingSkills.length > 0) {
        console.log(`Adding ${missingSkills.length} missing skills to user: ${user.username}`);

        await prisma.userSkill.createMany({
          data: missingSkills.map((skill) => {
            const isCombatSkill = combatSkillKeys.includes(skill.skey.toLowerCase());
            return {
              userId: user.id,
              skillId: skill.id,
              level: isCombatSkill ? 3 : 1,
              xp: isCombatSkill ? level3Xp : 0,
            };
          }),
        });

        fixedCount++;
      }
    }
  }

  console.log(`✅ Fixed ${fixedCount} users`);
}

main()
  .catch((e) => {
    console.error('❌ Fix failed:', e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
