import { api, clearCsrfToken } from './client';
import type { AuthUser } from '../types';

export async function login(login: string, password: string): Promise<AuthUser> {
  clearCsrfToken(); // Get fresh token for login
  const data = await api<{ ok: boolean; user: AuthUser }>('auth/login', {
    method: 'POST',
    body: JSON.stringify({ login, password }),
  });
  return data.user;
}

export async function logout(): Promise<void> {
  await api('auth/logout', { method: 'POST' });
  clearCsrfToken();
}

export async function getMe(): Promise<AuthUser | null> {
  try {
    const data = await api<{ ok: boolean; user: AuthUser }>('auth/me');
    return data.user;
  } catch {
    return null;
  }
}
