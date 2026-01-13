export const GAME_CONSTANTS = {
  // Skills
  MAX_SKILL_LEVEL: 99,
  MIN_SKILL_LEVEL: 1,
  STARTING_SKILL_LEVEL: 1,

  // Combat
  COMBAT_STYLES: ['ATTACK', 'STRENGTH', 'DEFENSE', 'RANGE', 'MAGIC'] as const,

  // Floor progression
  DEFAULT_WINS_PER_FLOOR: 3,
  STARTING_FLOOR: 1,

  // Void pressure
  DEFAULT_VOID_STEP: 3,
  DEFAULT_VOID_CAP: 60,

  // Chat
  CHAT_MESSAGE_MAX_LENGTH: 500,
  CHAT_HISTORY_LIMIT: 100,

  // Pagination
  DEFAULT_PAGE_SIZE: 20,
  MAX_PAGE_SIZE: 100,
} as const;

export const SKILL_KEYS = {
  ATTACK: 'attack',
  STRENGTH: 'strength',
  DEFENSE: 'defense',
  HEALTH: 'health',
  RANGE: 'range',
  MAGIC: 'magic',
  MINING: 'mining',
  CRAFTING: 'crafting',
  BLACKSMITHING: 'blacksmithing',
} as const;

export const COMBAT_STYLE_DISPLAY = {
  ATTACK: { icon: '⚔️', name: 'Attack' },
  STRENGTH: { icon: '💪', name: 'Strength' },
  DEFENSE: { icon: '🛡️', name: 'Defense' },
  RANGE: { icon: '🏹', name: 'Range' },
  MAGIC: { icon: '✨', name: 'Magic' },
} as const;
