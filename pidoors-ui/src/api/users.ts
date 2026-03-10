import { api } from './client';
import type { User, ApiResponse } from '../types';

export async function getUsers(): Promise<User[]> {
  const data = await api<{ users: User[] }>('users');
  return data.users;
}

export async function getUser(id: number): Promise<User> {
  const data = await api<{ user: User }>(`users/${id}`);
  return data.user;
}

export async function createUser(user: Partial<User> & { password: string }): Promise<ApiResponse> {
  return api('users', { method: 'POST', body: JSON.stringify(user) });
}

export async function updateUser(id: number, user: Partial<User> & { password?: string }): Promise<ApiResponse> {
  return api(`users/${id}`, { method: 'PUT', body: JSON.stringify(user) });
}

export async function deleteUser(id: number): Promise<ApiResponse> {
  return api(`users/${id}`, { method: 'DELETE' });
}
