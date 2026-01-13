export enum CombatStyle {
  ATTACK = 'ATTACK',
  STRENGTH = 'STRENGTH',
  DEFENSE = 'DEFENSE',
  RANGE = 'RANGE',
  MAGIC = 'MAGIC',
}

export enum BattleStatus {
  ONGOING = 'ONGOING',
  WON = 'WON',
  LOST = 'LOST',
  FLED = 'FLED',
}

export enum Actor {
  PLAYER = 'PLAYER',
  MOB = 'MOB',
}

export interface Mob {
  id: number;
  name: string;
  level: number;
  hp: number;
  attack: number;
  defense: number;
  magic: number;
  range: number;
  rewardXp: number;
  rewardGold: number;
  minFloor: number;
  maxFloor: number;
}

export interface Battle {
  id: string;
  userId: number;
  mobId: number;
  charName: string;
  charHpCurrent: number;
  charHpMax: number;
  mobName: string;
  mobHpCurrent: number;
  mobHpMax: number;
  rewardXp: number;
  rewardGold: number;
  floor: number;
  voidIntensity: number;
  combatStyle: CombatStyle;
  status: BattleStatus;
  createdAt: Date;
  updatedAt: Date;
}

export interface BattleTurn {
  id: string;
  battleId: string;
  turnNo: number;
  actor: Actor;
  action: string;
  damage: number;
  charHpAfter: number;
  mobHpAfter: number;
  logText: string;
}

export interface BattleState extends Battle {
  turns: BattleTurn[];
  mob?: Mob;
}
