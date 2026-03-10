import { api } from './client';
import type { Settings, ApiResponse } from '../types';

export async function getSettings(): Promise<Settings> {
  const data = await api<{ settings: Settings }>('settings');
  return data.settings;
}

export async function saveSettings(settings: Settings): Promise<ApiResponse> {
  return api('settings', { method: 'POST', body: JSON.stringify({ settings }) });
}

export async function testEmail(to: string): Promise<ApiResponse> {
  return api('settings/test-email', { method: 'POST', body: JSON.stringify({ to }) });
}
