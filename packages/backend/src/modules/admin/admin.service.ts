import { Injectable, NotFoundException, BadRequestException } from '@nestjs/common';
import { PrismaService } from '@/common/prisma/prisma.service';
import { UserRole } from '@prisma/client';

@Injectable()
export class AdminService {
  constructor(private prisma: PrismaService) {}

  // Get all users with pagination
  async getAllUsers(page: number = 1, limit: number = 50) {
    const skip = (page - 1) * limit;

    const [users, total] = await Promise.all([
      this.prisma.user.findMany({
        skip,
        take: limit,
        orderBy: { createdAt: 'desc' },
        select: {
          id: true,
          username: true,
          email: true,
          role: true,
          level: true,
          overallXp: true,
          gold: true,
          verified: true,
          lastSeen: true,
          createdAt: true,
          currentAreaCode: true,
        },
      }),
      this.prisma.user.count(),
    ]);

    return {
      users,
      total,
      page,
      totalPages: Math.ceil(total / limit),
    };
  }

  // Search users by username or email
  async searchUsers(query: string) {
    return await this.prisma.user.findMany({
      where: {
        OR: [
          { username: { contains: query, mode: 'insensitive' } },
          { email: { contains: query, mode: 'insensitive' } },
        ],
      },
      take: 20,
      select: {
        id: true,
        username: true,
        email: true,
        role: true,
        level: true,
        overallXp: true,
        gold: true,
        verified: true,
        lastSeen: true,
        createdAt: true,
      },
    });
  }

  // Get user by ID with full details
  async getUserById(userId: number) {
    const user = await this.prisma.user.findUnique({
      where: { id: userId },
      include: {
        skills: {
          include: {
            skill: true,
          },
        },
        battles: {
          take: 10,
          orderBy: { createdAt: 'desc' },
        },
      },
    });

    if (!user) {
      throw new NotFoundException('User not found');
    }

    return user;
  }

  // Update user role
  async updateUserRole(userId: number, role: UserRole) {
    const user = await this.prisma.user.findUnique({
      where: { id: userId },
    });

    if (!user) {
      throw new NotFoundException('User not found');
    }

    return await this.prisma.user.update({
      where: { id: userId },
      data: { role },
      select: {
        id: true,
        username: true,
        role: true,
      },
    });
  }

  // Update user stats (admin can adjust gold, xp, etc.)
  async updateUserStats(
    userId: number,
    data: {
      gold?: number;
      overallXp?: number;
      level?: number;
    },
  ) {
    const user = await this.prisma.user.findUnique({
      where: { id: userId },
    });

    if (!user) {
      throw new NotFoundException('User not found');
    }

    return await this.prisma.user.update({
      where: { id: userId },
      data,
      select: {
        id: true,
        username: true,
        gold: true,
        overallXp: true,
        level: true,
      },
    });
  }

  // Ban/unban user (toggle verified status)
  async toggleUserBan(userId: number) {
    const user = await this.prisma.user.findUnique({
      where: { id: userId },
    });

    if (!user) {
      throw new NotFoundException('User not found');
    }

    return await this.prisma.user.update({
      where: { id: userId },
      data: { verified: !user.verified },
      select: {
        id: true,
        username: true,
        verified: true,
      },
    });
  }

  // Get dashboard statistics
  async getDashboardStats() {
    const [
      totalUsers,
      onlineUsers,
      totalBattles,
      activeBattles,
      totalGold,
    ] = await Promise.all([
      this.prisma.user.count(),
      this.prisma.user.count({
        where: {
          lastSeen: {
            gte: new Date(Date.now() - 15 * 60 * 1000), // Last 15 minutes
          },
        },
      }),
      this.prisma.battle.count(),
      this.prisma.battle.count({
        where: { status: 'ONGOING' },
      }),
      this.prisma.user.aggregate({
        _sum: { gold: true },
      }),
    ]);

    // Get new users today
    const startOfDay = new Date();
    startOfDay.setHours(0, 0, 0, 0);

    const newUsersToday = await this.prisma.user.count({
      where: {
        createdAt: {
          gte: startOfDay,
        },
      },
    });

    return {
      totalUsers,
      onlineUsers,
      newUsersToday,
      totalBattles,
      activeBattles,
      totalGoldInEconomy: totalGold._sum.gold || 0,
    };
  }
}
