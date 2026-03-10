import { api } from './client';
import type { Schedule, ApiResponse } from '../types';

export async function getSchedules(): Promise<Schedule[]> {
  const data = await api<{ schedules: Schedule[] }>('schedules');
  return data.schedules;
}

export async function getSchedule(id: number): Promise<Schedule> {
  const data = await api<{ schedule: Schedule }>(`schedules/${id}`);
  return data.schedule;
}

export async function createSchedule(schedule: Partial<Schedule>): Promise<ApiResponse> {
  return api('schedules', { method: 'POST', body: JSON.stringify(schedule) });
}

export async function updateSchedule(id: number, schedule: Partial<Schedule>): Promise<ApiResponse> {
  return api(`schedules/${id}`, { method: 'PUT', body: JSON.stringify(schedule) });
}

export async function deleteSchedule(id: number): Promise<ApiResponse> {
  return api(`schedules/${id}`, { method: 'DELETE' });
}
