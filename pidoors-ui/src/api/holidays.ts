import { api } from './client';
import type { Holiday, ApiResponse } from '../types';

export async function getHolidays(): Promise<Holiday[]> {
  const data = await api<{ holidays: Holiday[] }>('holidays');
  return data.holidays;
}

export async function createHoliday(holiday: Partial<Holiday>): Promise<ApiResponse> {
  return api('holidays', { method: 'POST', body: JSON.stringify(holiday) });
}

export async function updateHoliday(id: number, holiday: Partial<Holiday>): Promise<ApiResponse> {
  return api(`holidays/${id}`, { method: 'PUT', body: JSON.stringify(holiday) });
}

export async function deleteHoliday(id: number): Promise<ApiResponse> {
  return api(`holidays/${id}`, { method: 'DELETE' });
}
