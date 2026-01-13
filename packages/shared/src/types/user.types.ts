export enum UserRole {
  PLAYER = 'PLAYER',
  SUPPORTER = 'SUPPORTER',
  HELPER = 'HELPER',
  MODERATOR = 'MODERATOR',
  ADMIN = 'ADMIN',
  GOVERNOR = 'GOVERNOR',
  LIBRARIAN = 'LIBRARIAN',
}

export interface User {
  id: number;
  username: string;
  email?: string | null;
  role: UserRole;
  displayName?: string | null;
  bio?: string | null;
  avatarUrl?: string | null;
  bannerUrl?: string | null;
  statusText?: string | null;
  level: number;
  overallXp: number;
  currentFloor: number;
  deepestFloor: number;
  currentAreaCode?: string | null;
  gold: number;
  verified: boolean;
  lastSeen?: Date | null;
  createdAt: Date;
  updatedAt: Date;
}

export interface UserProfile extends Omit<User, 'email'> {
  skills?: UserSkillWithDetails[];
}

export interface UserSkillWithDetails {
  skillId: number;
  skillKey: string;
  skillName: string;
  level: number;
  xp: number;
  xpToNext: number;
  progress: number;
}
