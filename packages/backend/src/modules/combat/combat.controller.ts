import { Controller, Get, Post, Body, Param, UseGuards, Request } from '@nestjs/common';
import { JwtAuthGuard } from '../auth/guards/jwt-auth.guard';
import { CombatService } from './combat.service';
import { StartBattleDto, TakeTurnDto } from './dto/combat.dto';

@Controller('combat')
@UseGuards(JwtAuthGuard)
export class CombatController {
  constructor(private readonly combatService: CombatService) {}

  // Start a new battle
  @Post('start')
  async startBattle(@Request() req: any, @Body() dto: StartBattleDto) {
    return await this.combatService.startBattle(req.user.userId, dto);
  }

  // Take a turn in combat
  @Post(':battleId/turn')
  async takeTurn(
    @Request() req: any,
    @Param('battleId') battleId: string,
    @Body() dto: TakeTurnDto,
  ) {
    return await this.combatService.takeTurn(req.user.userId, battleId, dto);
  }

  // Flee from battle
  @Post(':battleId/flee')
  async flee(@Request() req: any, @Param('battleId') battleId: string) {
    return await this.combatService.flee(req.user.userId, battleId);
  }

  // Get specific battle by ID
  @Get(':battleId')
  async getBattle(@Param('battleId') battleId: string) {
    return await this.combatService.getBattle(battleId);
  }

  // Get current ongoing battle
  @Get('current/battle')
  async getCurrentBattle(@Request() req: any) {
    return await this.combatService.getCurrentBattle(req.user.userId);
  }
}
