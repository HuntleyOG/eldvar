import { IsEnum, IsOptional, IsInt } from 'class-validator';
import { CombatStyle } from '@prisma/client';

export class StartBattleDto {
  @IsInt()
  mobId: number;

  @IsOptional()
  @IsInt()
  floor?: number;

  @IsOptional()
  @IsInt()
  voidIntensity?: number;
}

export class TakeTurnDto {
  @IsEnum(CombatStyle)
  combatStyle: CombatStyle;
}
