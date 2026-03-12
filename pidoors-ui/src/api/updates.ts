import { api } from './client';
import type { ApiResponse } from '../types';

interface UpdateStatus {
  current_version: string;
  latest_version: string;
}

interface Controller {
  name: string;
  ip_address: string;
  controller_version: string | null;
  status: string;
  update_requested: number;
  update_status: string | null;
}

export async function getUpdateStatus(): Promise<UpdateStatus> {
  return api<UpdateStatus>('update/status');
}

export async function checkForUpdates(): Promise<UpdateStatus> {
  return api<UpdateStatus>('update/status?force=1');
}

export async function runServerUpdate(): Promise<ApiResponse & { output?: string }> {
  return api('update/server', { method: 'POST' });
}

export async function getControllers(): Promise<Controller[]> {
  const data = await api<{ controllers: Controller[] }>('update/controllers');
  return data.controllers;
}

export async function requestControllerUpdate(doorName: string): Promise<ApiResponse> {
  return api(`update/controllers/${encodeURIComponent(doorName)}`, { method: 'POST' });
}

export async function requestAllControllerUpdates(): Promise<ApiResponse> {
  return api('update/controllers/all', { method: 'POST' });
}
