import { api } from './client';
import type { AccessGroup, ApiResponse } from '../types';

export async function getGroups(): Promise<AccessGroup[]> {
  const data = await api<{ groups: AccessGroup[] }>('groups');
  return data.groups;
}

export async function createGroup(group: Partial<AccessGroup>): Promise<ApiResponse> {
  return api('groups', { method: 'POST', body: JSON.stringify(group) });
}

export async function updateGroup(id: number, group: Partial<AccessGroup>): Promise<ApiResponse> {
  return api(`groups/${id}`, { method: 'PUT', body: JSON.stringify(group) });
}

export async function deleteGroup(id: number): Promise<ApiResponse> {
  return api(`groups/${id}`, { method: 'DELETE' });
}
