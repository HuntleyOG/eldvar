import { Injectable, ConflictException, BadRequestException } from '@nestjs/common';
import { JwtService } from '@nestjs/jwt';
import * as bcrypt from 'bcrypt';
import { PrismaService } from '@/common/prisma/prisma.service';
import { Prisma } from '@prisma/client';

@Injectable()
export class AuthService {
  constructor(
    private prisma: PrismaService,
    private jwtService: JwtService,
  ) {}

  async validateUser(username: string, password: string): Promise<any> {
    const user = await this.prisma.user.findUnique({ where: { username } });

    if (user && (await bcrypt.compare(password, user.password))) {
      const { password: _, ...result } = user;
      return result;
    }

    return null;
  }

  async login(user: any) {
    const payload = { username: user.username, sub: user.id, role: user.role };
    return {
      access_token: this.jwtService.sign(payload),
      user: {
        id: user.id,
        username: user.username,
        email: user.email,
        role: user.role,
        displayName: user.displayName,
        avatarUrl: user.avatarUrl,
        level: user.level,
        verified: user.verified,
      },
    };
  }

  async register(username: string, password: string, email?: string) {
    // Validate input
    if (!username || username.length < 3) {
      throw new BadRequestException('Username must be at least 3 characters long');
    }

    if (!password || password.length < 6) {
      throw new BadRequestException('Password must be at least 6 characters long');
    }

    try {
      const hashedPassword = await bcrypt.hash(password, 10);

      const user = await this.prisma.user.create({
        data: {
          username,
          password: hashedPassword,
          email,
        },
      });

      // Initialize all skills - combat skills start at level 3, others at level 1
      const skills = await this.prisma.skill.findMany();

      // Combat skill keys that should start at level 3
      const combatSkillKeys = ['attack', 'strength', 'defense', 'range', 'magic'];

      // Get XP required for level 3
      const level3Threshold = await this.prisma.xpThreshold.findUnique({
        where: { level: 3 },
      });
      const level3Xp = level3Threshold?.xpRequired || 0;

      await this.prisma.userSkill.createMany({
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

      const { password: _, ...result } = user;

      // Return the same format as login (with JWT token)
      return this.login(result);
    } catch (error) {
      // Handle Prisma unique constraint violation (duplicate username)
      if (error instanceof Prisma.PrismaClientKnownRequestError) {
        if (error.code === 'P2002') {
          throw new ConflictException('Username already exists');
        }
      }
      throw error;
    }
  }
}
