import { api } from './client';

export type ReportType = 'access_summary' | 'daily_activity' | 'user_activity' | 'hourly_pattern' | 'denied_access';

export interface ReportFilters {
  type?: ReportType;
  from?: string;
  to?: string;
}

export async function getReport(filters: ReportFilters = {}): Promise<{ report: Record<string, unknown>[]; type: string }> {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') params.set(key, String(value));
  });
  return api(`reports?${params.toString()}`);
}

export function getExportUrl(filters: ReportFilters = {}): string {
  const params = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') params.set(key, String(value));
  });
  return `/api/reports/export?${params.toString()}`;
}
