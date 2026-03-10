import { api } from './client';
import type { ApiResponse } from '../types';

interface Profile {
  id: number;
  user_name: string;
  user_email: string;
  admin: number;
  first_name: string | null;
  last_name: string | null;
  phone: string | null;
  department: string | null;
  employee_id: string | null;
  company: string | null;
  job_title: string | null;
  created_at: string;
  last_login: string | null;
}

export async function getProfile(): Promise<Profile> {
  const data = await api<{ profile: Profile }>('profile');
  return data.profile;
}

export async function updateProfile(profile: Partial<Profile>): Promise<ApiResponse> {
  return api('profile', { method: 'PUT', body: JSON.stringify(profile) });
}

export async function changePassword(currentPassword: string, newPassword: string): Promise<ApiResponse> {
  return api('profile/password', {
    method: 'POST',
    body: JSON.stringify({ current_password: currentPassword, new_password: newPassword }),
  });
}
