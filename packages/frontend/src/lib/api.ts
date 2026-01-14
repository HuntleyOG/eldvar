import axios from 'axios';

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:3001/api';

export const api = axios.create({
  baseURL: API_URL,
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
  },
});

// Add JWT token to requests
api.interceptors.request.use((config) => {
  const authStore = localStorage.getItem('eldvar-auth');
  if (authStore) {
    try {
      const { state } = JSON.parse(authStore);
      if (state?.token) {
        config.headers.Authorization = `Bearer ${state.token}`;
      }
    } catch (e) {
      // Ignore parsing errors
    }
  }
  return config;
});

// Auth API
export interface LoginRequest {
  username: string;
  password: string;
}

export interface RegisterRequest {
  username: string;
  password: string;
  email?: string;
}

export interface User {
  id: number;
  username: string;
  email?: string;
  role: string;
  displayName?: string;
  bio?: string;
  avatarUrl?: string;
  bannerUrl?: string;
  statusText?: string;
  level: number;
  overallXp?: number;
  currentFloor?: number;
  deepestFloor?: number;
  gold?: number;
  verified: boolean;
  createdAt?: string;
  lastSeen?: string;
  updatedAt?: string;
}

export interface AuthResponse {
  access_token: string;
  user: User;
}

export const authApi = {
  login: async (data: LoginRequest): Promise<AuthResponse> => {
    const response = await api.post<AuthResponse>('/auth/login', data);
    return response.data;
  },

  register: async (data: RegisterRequest): Promise<AuthResponse> => {
    const response = await api.post<AuthResponse>('/auth/register', data);
    return response.data;
  },

  logout: async (): Promise<void> => {
    await api.post('/auth/logout');
  },

  getCurrentUser: async (): Promise<User> => {
    const response = await api.get<User>('/auth/me');
    return response.data;
  },
};

// User Profile API
export interface UpdateProfileRequest {
  username?: string;
  displayName?: string;
  email?: string;
  bio?: string;
  avatarUrl?: string;
  bannerUrl?: string;
  statusText?: string;
}

export interface UserSkill {
  skillId: number;
  skillKey: string;
  skillName: string;
  level: number;
  xp: number;
  updatedAt: string;
}

export const userApi = {
  getProfile: async (): Promise<User> => {
    const response = await api.get<User>('/users/profile');
    return response.data;
  },

  getProfileById: async (id: number): Promise<User> => {
    const response = await api.get<User>(`/users/${id}`);
    return response.data;
  },

  getProfileByUsername: async (username: string): Promise<User> => {
    const response = await api.get<User>(`/users/username/${username}`);
    return response.data;
  },

  updateProfile: async (data: UpdateProfileRequest): Promise<User> => {
    const response = await api.patch<User>('/users/profile', data);
    return response.data;
  },

  getSkills: async (): Promise<UserSkill[]> => {
    const response = await api.get<UserSkill[]>('/users/profile/skills');
    return response.data;
  },

  getSkillsById: async (id: number): Promise<UserSkill[]> => {
    const response = await api.get<UserSkill[]>(`/users/${id}/skills`);
    return response.data;
  },
};

// Locations API
export interface Location {
  id: number;
  slug: string;
  name: string;
  shortBlurb?: string;
  imagePath?: string;
  loreText?: string;
}

export interface TravelResponse {
  encounter: boolean;
  message: string;
  location: Location;
  battle?: Battle;
}

export const locationApi = {
  getAllLocations: async (): Promise<Location[]> => {
    const response = await api.get<Location[]>('/locations');
    return response.data;
  },

  getLocationBySlug: async (slug: string): Promise<Location> => {
    const response = await api.get<Location>(`/locations/${slug}`);
    return response.data;
  },

  getCurrentLocation: async (): Promise<Location> => {
    const response = await api.get<Location>('/locations/current/location');
    return response.data;
  },

  travelTo: async (destination: string, isComplete: boolean = false): Promise<TravelResponse> => {
    const response = await api.post<TravelResponse>('/locations/travel', { destination, isComplete });
    return response.data;
  },
};

// Combat API
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
}

export interface BattleTurn {
  id: string;
  battleId: string;
  turnNo: number;
  actor: 'PLAYER' | 'MOB';
  action: string;
  damage: number;
  charHpAfter: number;
  mobHpAfter: number;
  logText: string;
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
  createdAt: string;
  updatedAt: string;
  mob?: Mob;
  turns?: BattleTurn[];
}

export interface StartBattleResponse {
  battle: Battle;
  message: string;
}

export interface TakeTurnResponse {
  battle: Battle;
  message?: string;
}

export const combatApi = {
  startBattle: async (mobId: number, floor?: number): Promise<StartBattleResponse> => {
    const response = await api.post<StartBattleResponse>('/combat/start', {
      mobId,
      floor,
      voidIntensity: 0,
    });
    return response.data;
  },

  takeTurn: async (battleId: string, combatStyle: CombatStyle): Promise<TakeTurnResponse> => {
    const response = await api.post<TakeTurnResponse>(`/combat/${battleId}/turn`, {
      combatStyle,
    });
    return response.data;
  },

  flee: async (battleId: string): Promise<TakeTurnResponse> => {
    const response = await api.post<TakeTurnResponse>(`/combat/${battleId}/flee`);
    return response.data;
  },

  getBattle: async (battleId: string): Promise<Battle> => {
    const response = await api.get<Battle>(`/combat/${battleId}`);
    return response.data;
  },

  getCurrentBattle: async (): Promise<Battle | null> => {
    const response = await api.get<Battle | null>('/combat/current/battle');
    return response.data;
  },
};
