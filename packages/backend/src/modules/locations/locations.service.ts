import { Injectable, NotFoundException, BadRequestException, Inject, forwardRef } from '@nestjs/common';
import { PrismaService } from '@/common/prisma/prisma.service';
import { CombatService } from '../combat/combat.service';

@Injectable()
export class LocationsService {
  constructor(
    private prisma: PrismaService,
    @Inject(forwardRef(() => CombatService))
    private combatService: CombatService,
  ) {}

  async getAllLocations() {
    return await this.prisma.worldArea.findMany({
      orderBy: { name: 'asc' },
      select: {
        id: true,
        slug: true,
        name: true,
        shortBlurb: true,
        imagePath: true,
        loreText: true,
      },
    });
  }

  async getLocationBySlug(slug: string) {
    const location = await this.prisma.worldArea.findUnique({
      where: { slug },
      select: {
        id: true,
        slug: true,
        name: true,
        shortBlurb: true,
        imagePath: true,
        loreText: true,
      },
    });

    if (!location) {
      throw new NotFoundException('Location not found');
    }

    return location;
  }

  async getCurrentLocation(userId: number) {
    const user = await this.prisma.user.findUnique({
      where: { id: userId },
      select: { currentAreaCode: true },
    });

    if (!user) {
      throw new NotFoundException('User not found');
    }

    // If user has no location set, return the first area (default starting location)
    if (!user.currentAreaCode) {
      const defaultArea = await this.prisma.worldArea.findFirst({
        orderBy: { id: 'asc' },
      });

      if (defaultArea) {
        // Set default location for user
        await this.prisma.user.update({
          where: { id: userId },
          data: { currentAreaCode: defaultArea.slug },
        });
        return defaultArea;
      }

      throw new NotFoundException('No locations available');
    }

    return await this.getLocationBySlug(user.currentAreaCode);
  }

  async travelToLocation(userId: number, destinationSlug: string, isComplete: boolean = false) {
    // Verify destination exists
    const destination = await this.getLocationBySlug(destinationSlug);

    // Get current location
    const user = await this.prisma.user.findUnique({
      where: { id: userId },
      select: { currentAreaCode: true, currentFloor: true },
    });

    // Only check if already at destination when completing the journey
    if (isComplete && user?.currentAreaCode === destinationSlug) {
      throw new BadRequestException('You are already at this location');
    }

    // Check for random encounter (30% chance)
    const encounterChance = Math.random();
    const encounterHappens = encounterChance < 0.3;

    if (encounterHappens) {
      // Get a random mob appropriate for the user's level/floor
      const floor = user?.currentFloor || 1;
      const mobs = await this.prisma.mob.findMany({
        where: {
          minFloor: { lte: floor },
          maxFloor: { gte: floor },
        },
      });

      if (mobs.length > 0) {
        // Pick a random mob
        const randomMob = mobs[Math.floor(Math.random() * mobs.length)];

        // Start the battle
        const battleResult = await this.combatService.startBattle(userId, {
          mobId: randomMob.id,
          floor,
          voidIntensity: 0,
        });

        return {
          encounter: true,
          message: `While traveling, ${battleResult.message}`,
          battle: battleResult.battle,
          location: destination,
        };
      }
    }

    // Award small pathfinding XP for each step
    await this.awardPathfindingXp(userId, 10);

    // Only update location when journey is complete
    if (isComplete) {
      await this.prisma.user.update({
        where: { id: userId },
        data: {
          currentAreaCode: destinationSlug,
          lastSeen: new Date(),
        },
      });

      // Award bonus XP for completing the journey
      await this.awardPathfindingXp(userId, 15);

      return {
        encounter: false,
        message: `Successfully traveled to ${destination.name}`,
        location: destination,
        complete: true,
      };
    }

    // Step taken, but journey not complete yet
    return {
      encounter: false,
      message: `Taking a step toward ${destination.name}...`,
      location: destination,
      complete: false,
    };
  }

  private async awardPathfindingXp(userId: number, xpAmount: number) {
    const pathfindingSkill = await this.prisma.userSkill.findFirst({
      where: {
        userId,
        skill: { skey: 'pathfinding' },
      },
    });

    if (pathfindingSkill) {
      const newXp = pathfindingSkill.xp + xpAmount;
      const newLevel = await this.calculateLevel(newXp);

      await this.prisma.userSkill.update({
        where: {
          userId_skillId: {
            userId,
            skillId: pathfindingSkill.skillId,
          },
        },
        data: {
          xp: newXp,
          level: newLevel,
        },
      });
    }
  }

  private async calculateLevel(xp: number): Promise<number> {
    const threshold = await this.prisma.xpThreshold.findFirst({
      where: { xpRequired: { lte: xp } },
      orderBy: { level: 'desc' },
    });

    return threshold?.level || 1;
  }
}
