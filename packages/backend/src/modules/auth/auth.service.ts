import { Injectable } from '@nestjs/common';
import { JwtService } from '@nestjs/jwt';
import * as bcrypt from 'bcrypt';
import { PrismaService } from '@/common/prisma/prisma.service';

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
        role: user.role,
        displayName: user.displayName,
        avatarUrl: user.avatarUrl,
      },
    };
  }

  async register(username: string, password: string, email?: string) {
    const hashedPassword = await bcrypt.hash(password, 10);

    const user = await this.prisma.user.create({
      data: {
        username,
        password: hashedPassword,
        email,
      },
    });

    // Initialize all skills at level 1
    const skills = await this.prisma.skill.findMany();
    await this.prisma.userSkill.createMany({
      data: skills.map((skill) => ({
        userId: user.id,
        skillId: skill.id,
        level: 1,
        xp: 0,
      })),
    });

    const { password: _, ...result } = user;
    return result;
  }
}
