import { IsEnum, IsOptional, IsInt, IsString } from 'class-validator';
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

  // Travel context (optional - only for battles during travel)
  @IsOptional()
  @IsString()
  travelDestination?: string;

  @IsOptional()
  @IsInt()
  travelProgress?: number;

  @IsOptional()
  @IsInt()
  travelDistance?: number;

  @IsOptional()
  @IsString()
  travelStartLocation?: string;
}

export class TakeTurnDto {
  @IsEnum(CombatStyle)
  combatStyle: CombatStyle;
}
