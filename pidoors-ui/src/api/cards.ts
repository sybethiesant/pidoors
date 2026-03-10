import { api } from './client';
import type { Card, ApiResponse } from '../types';

export async function getCards(): Promise<Card[]> {
  const data = await api<{ cards: Card[] }>('cards');
  return data.cards;
}

export async function getCard(id: number): Promise<Card> {
  const data = await api<{ card: Card }>(`cards/${id}`);
  return data.card;
}

export async function createCard(card: Partial<Card>): Promise<ApiResponse & { card_id?: number }> {
  return api('cards', {
    method: 'POST',
    body: JSON.stringify(card),
  });
}

export async function updateCard(id: number, card: Partial<Card>): Promise<ApiResponse> {
  return api(`cards/${id}`, {
    method: 'PUT',
    body: JSON.stringify(card),
  });
}

export async function deleteCard(id: number): Promise<ApiResponse> {
  return api(`cards/${id}`, { method: 'DELETE' });
}

export async function importCards(file: File): Promise<ApiResponse & { imported?: number; skipped?: number }> {
  const formData = new FormData();
  formData.append('file', file);
  return api('cards/import', {
    method: 'POST',
    body: formData,
  });
}

export function getExportUrl(): string {
  return '/api/cards/export';
}
