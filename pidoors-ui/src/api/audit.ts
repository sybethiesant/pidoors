import { api } from './client';
import type { AuditLog } from '../types';

interface AuditResponse {
  logs: AuditLog[];
  total: number;
  page: number;
  limit: number;
  pages: number;
  event_types: string[];
}

export interface AuditFilters {
  type?: string;
  from?: string;
  to?: string;
  search?: string;
  page?: number;
  limit?: number;
}

export async function getAuditLogs(filters: AuditFilters = {}): Promise<AuditResponse> {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') params.set(key, String(value));
  });
  return api<AuditResponse>(`audit?${params.toString()}`);
}
