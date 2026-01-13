import { Injectable } from '@nestjs/common';
import { PassportSerializer } from '@nestjs/passport';
import { PrismaService } from '@/common/prisma/prisma.service';

@Injectable()
export class SessionSerializer extends PassportSerializer {
  constructor(private prisma: PrismaService) {
    super();
  }

  serializeUser(user: any, done: (err: Error | null, user: any) => void): void {
    done(null, { id: user.id });
  }

  async deserializeUser(
    payload: any,
    done: (err: Error | null, user: any) => void,
  ): Promise<void> {
    const user = await this.prisma.user.findUnique({
      where: { id: payload.id },
    });
    done(null, user);
  }
}
