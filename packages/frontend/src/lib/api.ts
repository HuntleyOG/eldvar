import axios from 'axios';

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:3001/api';

export const api = axios.create({
  baseURL: API_URL,
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
  },
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
  level: number;
  verified: boolean;
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
