import { Injectable, NotFoundException, ConflictException, BadRequestException } from '@nestjs/common';
import { PrismaService } from '@/common/prisma/prisma.service';
import { UpdateProfileDto } from './dto/update-profile.dto';
import { Prisma } from '@prisma/client';

@Injectable()
export class UsersService {
  constructor(private prisma: PrismaService) {}

  async findById(id: number) {
    const user = await this.prisma.user.findUnique({
      where: { id },
      select: {
        id: true,
        username: true,
        email: true,
        role: true,
        displayName: true,
        bio: true,
        avatarUrl: true,
        bannerUrl: true,
        statusText: true,
        level: true,
        overallXp: true,
        currentFloor: true,
        deepestFloor: true,
        gold: true,
        verified: true,
        createdAt: true,
        lastSeen: true,
      },
    });

    if (!user) {
      throw new NotFoundException('User not found');
    }

    return user;
  }

  async findByUsername(username: string) {
    const user = await this.prisma.user.findUnique({
      where: { username },
      select: {
        id: true,
        username: true,
        email: true,
        role: true,
        displayName: true,
        bio: true,
        avatarUrl: true,
        bannerUrl: true,
        statusText: true,
        level: true,
        overallXp: true,
        currentFloor: true,
        deepestFloor: true,
        gold: true,
        verified: true,
        createdAt: true,
        lastSeen: true,
      },
    });

    if (!user) {
      throw new NotFoundException('User not found');
    }

    return user;
  }

  async updateProfile(userId: number, updateProfileDto: UpdateProfileDto) {
    try {
      const user = await this.prisma.user.update({
        where: { id: userId },
        data: {
          ...updateProfileDto,
          updatedAt: new Date(),
        },
        select: {
          id: true,
          username: true,
          email: true,
          role: true,
          displayName: true,
          bio: true,
          avatarUrl: true,
          bannerUrl: true,
          statusText: true,
          level: true,
          overallXp: true,
          currentFloor: true,
          deepestFloor: true,
          gold: true,
          verified: true,
          createdAt: true,
          lastSeen: true,
          updatedAt: true,
        },
      });

      return user;
    } catch (error) {
      if (error instanceof Prisma.PrismaClientKnownRequestError) {
        if (error.code === 'P2002') {
          throw new ConflictException('Username or email already exists');
        }
        if (error.code === 'P2025') {
          throw new NotFoundException('User not found');
        }
      }
      throw error;
    }
  }

  async getUserSkills(userId: number) {
    const userSkills = await this.prisma.userSkill.findMany({
      where: { userId },
      include: {
        skill: true,
      },
      orderBy: {
        skill: {
          name: 'asc',
        },
      },
    });

    if (!userSkills || userSkills.length === 0) {
      throw new NotFoundException('No skills found for this user');
    }

    return userSkills.map((us: { skillId: number; level: number; xp: number; updatedAt: Date; skill: { skey: string; name: string } }) => ({
      skillId: us.skillId,
      skillKey: us.skill.skey,
      skillName: us.skill.name,
      level: us.level,
      xp: us.xp,
      updatedAt: us.updatedAt,
    }));
  }

  async updateLastSeen(userId: number) {
    await this.prisma.user.update({
      where: { id: userId },
      data: { lastSeen: new Date() },
    });
  }
}
