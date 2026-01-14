import { Module, forwardRef } from '@nestjs/common';
import { LocationsController } from './locations.controller';
import { LocationsService } from './locations.service';
import { PrismaModule } from '@/common/prisma/prisma.module';
import { CombatModule } from '../combat/combat.module';

@Module({
  imports: [PrismaModule, forwardRef(() => CombatModule)],
  controllers: [LocationsController],
  providers: [LocationsService],
  exports: [LocationsService],
})
export class LocationsModule {}
