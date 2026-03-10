import { api } from './client';
import type { AccessLog } from '../types';

interface LogsResponse {
  logs: AccessLog[];
  total: number;
  page: number;
  limit: number;
  pages: number;
}

export interface LogFilters {
  from?: string;
  to?: string;
  door?: string;
  granted?: string;
  search?: string;
  page?: number;
  limit?: number;
}

export async function getLogs(filters: LogFilters = {}): Promise<LogsResponse> {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') params.set(key, String(value));
  });
  return api<LogsResponse>(`logs?${params.toString()}`);
}

export function getExportUrl(filters: LogFilters = {}): string {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '' && key !== 'page' && key !== 'limit')
      params.set(key, String(value));
  });
  return `/api/logs/export?${params.toString()}`;
}
