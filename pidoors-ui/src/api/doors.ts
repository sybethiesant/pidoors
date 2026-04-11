import { api } from './client';
import type { Door, ApiResponse } from '../types';

export async function getDoors(): Promise<Door[]> {
  const data = await api<{ doors: Door[] }>('doors');
  return data.doors;
}

export async function getDoor(name: string): Promise<Door> {
  const data = await api<{ door: Door }>(`doors/${encodeURIComponent(name)}`);
  return data.door;
}

export async function createDoor(door: Partial<Door>): Promise<ApiResponse> {
  return api<ApiResponse>('doors', {
    method: 'POST',
    body: JSON.stringify(door),
  });
}

export async function updateDoor(name: string, door: Partial<Door>): Promise<ApiResponse> {
  return api<ApiResponse>(`doors/${encodeURIComponent(name)}`, {
    method: 'PUT',
    body: JSON.stringify(door),
  });
}

export async function deleteDoor(name: string): Promise<ApiResponse> {
  return api<ApiResponse>(`doors/${encodeURIComponent(name)}`, {
    method: 'DELETE',
  });
}

export async function unlockDoor(name: string): Promise<ApiResponse> {
  return api<ApiResponse>(`doors/${encodeURIComponent(name)}/unlock`, {
    method: 'POST',
  });
}

export async function holdDoor(name: string, action: 'hold' | 'release'): Promise<ApiResponse> {
  return api<ApiResponse>(`doors/${encodeURIComponent(name)}/hold`, {
    method: 'POST',
    body: JSON.stringify({ action }),
  });
}

export async function pingDoor(name: string): Promise<ApiResponse & { ping?: Record<string, unknown> }> {
  return api<ApiResponse & { ping?: Record<string, unknown> }>(`doors/${encodeURIComponent(name)}/ping`, {
    method: 'POST',
  });
}

export async function getAvailablePins(name: string): Promise<{ available: number[]; reserved: Record<number, string> }> {
  return api<{ available: number[]; reserved: Record<number, string> }>(
    `doors/${encodeURIComponent(name)}/available-pins`
  );
}

export async function gateCommand(
  name: string,
  action: 'open' | 'close' | 'stop' | 'hold' | 'release'
): Promise<ApiResponse> {
  return api<ApiResponse>(`doors/${encodeURIComponent(name)}/gate-${action}`, {
    method: 'POST',
  });
}
