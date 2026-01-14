import { Injectable, NotFoundException, BadRequestException } from '@nestjs/common';
import { PrismaService } from '@/common/prisma/prisma.service';
import { CombatStyle, BattleStatus, Actor } from '@prisma/client';
import { StartBattleDto, TakeTurnDto } from './dto/combat.dto';

@Injectable()
export class CombatService {
  constructor(private prisma: PrismaService) {}

  async startBattle(userId: number, dto: StartBattleDto) {
    // Check if user already has an ongoing battle
    const existingBattle = await this.prisma.battle.findFirst({
      where: {
        userId,
        status: BattleStatus.ONGOING,
      },
    });

    if (existingBattle) {
      throw new BadRequestException('You are already in a battle');
    }

    // Get the mob
    const mob = await this.prisma.mob.findUnique({
      where: { id: dto.mobId },
    });

    if (!mob) {
      throw new NotFoundException('Mob not found');
    }

    // Get user stats
    const user = await this.prisma.user.findUnique({
      where: { id: userId },
      select: {
        username: true,
        level: true,
      },
    });

    if (!user) {
      throw new NotFoundException('User not found');
    }

    // Get user's Health skill to calculate max HP
    const healthSkill = await this.prisma.userSkill.findFirst({
      where: {
        userId,
        skill: { skey: 'health' },
      },
      include: { skill: true },
    });

    const maxHp = this.calculateMaxHp(healthSkill?.level || 1);

    // Create the battle
    const battle = await this.prisma.battle.create({
      data: {
        userId,
        mobId: dto.mobId,
        charName: user.username,
        charHpCurrent: maxHp,
        charHpMax: maxHp,
        mobName: mob.name,
        mobHpCurrent: mob.hp,
        mobHpMax: mob.hp,
        rewardXp: mob.rewardXp,
        rewardGold: mob.rewardGold,
        floor: dto.floor || 1,
        voidIntensity: dto.voidIntensity || 0,
        combatStyle: CombatStyle.ATTACK, // Default
        status: BattleStatus.ONGOING,
        // Travel context (if this is a travel battle)
        travelDestination: dto.travelDestination,
        travelProgress: dto.travelProgress,
        travelDistance: dto.travelDistance,
        travelStartLocation: dto.travelStartLocation,
      },
      include: {
        mob: true,
        turns: true,
      },
    });

    return {
      battle: this.serializeBattle(battle),
      message: `You encountered a ${mob.name}!`,
    };
  }

  async takeTurn(userId: number, battleId: string, dto: TakeTurnDto) {
    const battle = await this.prisma.battle.findUnique({
      where: { id: BigInt(battleId) },
      include: {
        mob: true,
        turns: {
          orderBy: { turnNo: 'desc' },
          take: 1,
        },
      },
    });

    if (!battle) {
      throw new NotFoundException('Battle not found');
    }

    if (battle.userId !== userId) {
      throw new BadRequestException('This is not your battle');
    }

    if (battle.status !== BattleStatus.ONGOING) {
      throw new BadRequestException('This battle has already ended');
    }

    const turnNo = (battle.turns[0]?.turnNo || 0) + 1;

    // Get user's combat skills
    const userSkills = await this.getUserCombatSkills(userId);

    // Calculate player damage
    const playerDamage = await this.calculatePlayerDamage(
      userSkills,
      dto.combatStyle,
      battle.mob,
    );

    // Apply damage to mob
    let mobHpAfter = Math.max(0, battle.mobHpCurrent - playerDamage);

    // Create player turn
    await this.prisma.battleTurn.create({
      data: {
        battleId: battle.id,
        turnNo,
        actor: Actor.PLAYER,
        action: dto.combatStyle,
        damage: playerDamage,
        charHpAfter: battle.charHpCurrent,
        mobHpAfter,
        logText: `You dealt ${playerDamage} damage using ${dto.combatStyle}!`,
      },
    });

    // Update battle mob HP
    await this.prisma.battle.update({
      where: { id: battle.id },
      data: {
        mobHpCurrent: mobHpAfter,
        combatStyle: dto.combatStyle,
      },
    });

    // Check if mob is dead
    if (mobHpAfter <= 0) {
      return await this.endBattle(battle.id, BattleStatus.WON, userId);
    }

    // Mob's turn
    const mobDamage = await this.calculateMobDamage(battle.mob, userSkills.defense);
    let charHpAfter = Math.max(0, battle.charHpCurrent - mobDamage);

    // Create mob turn
    await this.prisma.battleTurn.create({
      data: {
        battleId: battle.id,
        turnNo: turnNo + 1,
        actor: Actor.MOB,
        action: 'ATTACK',
        damage: mobDamage,
        charHpAfter,
        mobHpAfter,
        logText: `${battle.mobName} dealt ${mobDamage} damage to you!`,
      },
    });

    // Update battle char HP
    await this.prisma.battle.update({
      where: { id: battle.id },
      data: { charHpCurrent: charHpAfter },
    });

    // Check if player is dead
    if (charHpAfter <= 0) {
      return await this.endBattle(battle.id, BattleStatus.LOST, userId);
    }

    // Return updated battle state
    const updatedBattle = await this.getBattle(battleId);
    return {
      battle: updatedBattle,
      message: undefined,
    };
  }

  async flee(userId: number, battleId: string) {
    const battle = await this.prisma.battle.findUnique({
      where: { id: BigInt(battleId) },
    });

    if (!battle) {
      throw new NotFoundException('Battle not found');
    }

    if (battle.userId !== userId) {
      throw new BadRequestException('This is not your battle');
    }

    if (battle.status !== BattleStatus.ONGOING) {
      throw new BadRequestException('This battle has already ended');
    }

    return await this.endBattle(battle.id, BattleStatus.FLED, userId);
  }

  async getBattle(battleId: string) {
    const battle = await this.prisma.battle.findUnique({
      where: { id: BigInt(battleId) },
      include: {
        mob: true,
        turns: {
          orderBy: { turnNo: 'asc' },
        },
      },
    });

    if (!battle) {
      throw new NotFoundException('Battle not found');
    }

    return this.serializeBattle(battle);
  }

  async getCurrentBattle(userId: number) {
    const battle = await this.prisma.battle.findFirst({
      where: {
        userId,
        status: BattleStatus.ONGOING,
      },
      include: {
        mob: true,
        turns: {
          orderBy: { turnNo: 'asc' },
        },
      },
    });

    return battle ? this.serializeBattle(battle) : null;
  }

  // Helper methods

  private async endBattle(battleId: bigint, status: BattleStatus, userId: number) {
    const battle = await this.prisma.battle.update({
      where: { id: battleId },
      data: { status },
      include: {
        mob: true,
        turns: {
          orderBy: { turnNo: 'asc' },
        },
      },
    });

    // If won, award XP and gold
    if (status === BattleStatus.WON) {
      await this.awardRewards(userId, battle);
    }

    // If lost during travel, return user to their starting location
    if (status === BattleStatus.LOST && battle.travelStartLocation) {
      await this.prisma.user.update({
        where: { id: userId },
        data: {
          currentAreaCode: battle.travelStartLocation,
        },
      });
    }

    return {
      battle: this.serializeBattle(battle),
      message: this.getBattleEndMessage(status, battle),
    };
  }

  private async awardRewards(userId: number, battle: any) {
    // Award gold
    await this.prisma.user.update({
      where: { id: userId },
      data: {
        gold: { increment: battle.rewardGold },
      },
    });

    // Award XP to the combat skill that was used
    const combatStyle = battle.combatStyle;
    const skillKey = combatStyle.toLowerCase();

    const userSkill = await this.prisma.userSkill.findFirst({
      where: {
        userId,
        skill: { skey: skillKey },
      },
      include: { skill: true },
    });

    if (userSkill) {
      const newXp = userSkill.xp + battle.rewardXp;
      const newLevel = await this.calculateLevel(newXp);

      await this.prisma.userSkill.update({
        where: {
          userId_skillId: {
            userId,
            skillId: userSkill.skillId,
          },
        },
        data: {
          xp: newXp,
          level: newLevel,
        },
      });
    }

    // Also award Health XP (smaller amount)
    const healthSkill = await this.prisma.userSkill.findFirst({
      where: {
        userId,
        skill: { skey: 'health' },
      },
      include: { skill: true },
    });

    if (healthSkill) {
      const healthXp = Math.floor(battle.rewardXp * 0.33); // 33% to health
      const newXp = healthSkill.xp + healthXp;
      const newLevel = await this.calculateLevel(newXp);

      await this.prisma.userSkill.update({
        where: {
          userId_skillId: {
            userId,
            skillId: healthSkill.skillId,
          },
        },
        data: {
          xp: newXp,
          level: newLevel,
        },
      });
    }
  }

  private async getUserCombatSkills(userId: number) {
    const skills = await this.prisma.userSkill.findMany({
      where: {
        userId,
        skill: {
          skey: {
            in: ['attack', 'strength', 'defense', 'health', 'range', 'magic'],
          },
        },
      },
      include: { skill: true },
    });

    const skillMap: any = {};
    skills.forEach((s: { level: number; skill: { skey: string } }) => {
      skillMap[s.skill.skey] = s.level;
    });

    return {
      attack: skillMap.attack || 1,
      strength: skillMap.strength || 1,
      defense: skillMap.defense || 1,
      health: skillMap.health || 1,
      range: skillMap.range || 1,
      magic: skillMap.magic || 1,
    };
  }

  private async calculatePlayerDamage(
    userSkills: any,
    combatStyle: CombatStyle,
    mob: any,
  ): Promise<number> {
    let baseDamage = 0;
    let accuracy = 0;

    switch (combatStyle) {
      case CombatStyle.ATTACK:
        baseDamage = userSkills.attack + Math.floor(userSkills.strength / 2);
        accuracy = userSkills.attack;
        break;
      case CombatStyle.STRENGTH:
        baseDamage = userSkills.strength * 1.5;
        accuracy = userSkills.attack * 0.8;
        break;
      case CombatStyle.DEFENSE:
        baseDamage = userSkills.attack * 0.5;
        accuracy = userSkills.attack * 1.2;
        break;
      case CombatStyle.RANGE:
        baseDamage = userSkills.range + Math.floor(userSkills.attack / 3);
        accuracy = userSkills.range;
        break;
      case CombatStyle.MAGIC:
        baseDamage = userSkills.magic * 1.2;
        accuracy = userSkills.magic;
        break;
    }

    // Factor in mob defense
    const defense = mob.defense || 1;
    const damageReduction = Math.floor(defense / 2);

    // Add randomness (80-120% of base damage)
    const variance = 0.8 + Math.random() * 0.4;

    const finalDamage = Math.max(
      1,
      Math.floor((baseDamage - damageReduction) * variance),
    );

    return finalDamage;
  }

  private async calculateMobDamage(mob: any, playerDefense: number): Promise<number> {
    const baseDamage = mob.attack;
    const damageReduction = Math.floor(playerDefense / 2);

    // Add randomness (80-120% of base damage)
    const variance = 0.8 + Math.random() * 0.4;

    const finalDamage = Math.max(
      1,
      Math.floor((baseDamage - damageReduction) * variance),
    );

    return finalDamage;
  }

  private calculateMaxHp(healthLevel: number): number {
    // Base HP of 100, +10 per health level
    return 100 + (healthLevel - 1) * 10;
  }

  private async calculateLevel(xp: number): Promise<number> {
    // Get the highest level where xp_required <= current xp
    const threshold = await this.prisma.xpThreshold.findFirst({
      where: { xpRequired: { lte: xp } },
      orderBy: { level: 'desc' },
    });

    return threshold?.level || 1;
  }

  private getBattleEndMessage(status: BattleStatus, battle: any): string {
    switch (status) {
      case BattleStatus.WON:
        return `Victory! You defeated ${battle.mobName} and earned ${battle.rewardGold} gold and ${battle.rewardXp} XP!`;
      case BattleStatus.LOST:
        return `You were defeated by ${battle.mobName}...`;
      case BattleStatus.FLED:
        return `You fled from ${battle.mobName}.`;
      default:
        return 'Battle ended.';
    }
  }

  private serializeBattle(battle: any) {
    // Convert BigInt fields to strings for JSON serialization
    return {
      ...battle,
      id: battle.id.toString(),
      turns: battle.turns?.map((turn: any) => ({
        ...turn,
        id: turn.id.toString(),
        battleId: turn.battleId.toString(),
      })) || [],
    };
  }
}
