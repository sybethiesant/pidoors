import { api } from './client';
import type { Backup, ApiResponse } from '../types';

export async function getBackups(): Promise<Backup[]> {
  const data = await api<{ backups: Backup[] }>('backups');
  return data.backups;
}

export async function createBackup(): Promise<ApiResponse & { name?: string; size?: number }> {
  return api('backups', { method: 'POST' });
}

export function getDownloadUrl(name: string): string {
  return `/api/backups/${encodeURIComponent(name)}/download`;
}

export async function deleteBackup(name: string): Promise<ApiResponse> {
  return api(`backups/${encodeURIComponent(name)}`, { method: 'DELETE' });
}
