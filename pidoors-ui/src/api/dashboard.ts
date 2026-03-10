import { api } from './client';
import type { DashboardData } from '../types';

export async function getDashboard(): Promise<DashboardData> {
  return api<DashboardData>('dashboard');
}
