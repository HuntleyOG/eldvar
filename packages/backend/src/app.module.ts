import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { ThrottlerModule } from '@nestjs/throttler';
import { ScheduleModule } from '@nestjs/schedule';
import { PrismaModule } from './common/prisma/prisma.module';
import { AuthModule } from './modules/auth/auth.module';
import { UsersModule } from './modules/users/users.module';
import { SkillsModule } from './modules/skills/skills.module';
import { CombatModule } from './modules/combat/combat.module';
import { GuildsModule } from './modules/guilds/guilds.module';
import { ChatModule } from './modules/chat/chat.module';
import { WikiModule } from './modules/wiki/wiki.module';
import { AdminModule } from './modules/admin/admin.module';

@Module({
  imports: [
    // Configuration
    ConfigModule.forRoot({
      isGlobal: true,
      envFilePath: '.env',
    }),

    // Rate limiting
    ThrottlerModule.forRoot([
      {
        ttl: 60000, // 60 seconds
        limit: 100, // 100 requests
      },
    ]),

    // Scheduling (for background tasks)
    ScheduleModule.forRoot(),

    // Database
    PrismaModule,

    // Feature modules
    AuthModule,
    UsersModule,
    SkillsModule,
    CombatModule,
    GuildsModule,
    ChatModule,
    WikiModule,
    AdminModule,
  ],
})
export class AppModule {}
