export interface User {
  id: number;
  user_name: string;
  user_email: string;
  admin: boolean;
  active: boolean;
  first_name: string | null;
  last_name: string | null;
  phone: string | null;
  department: string | null;
  employee_id: string | null;
  company: string | null;
  job_title: string | null;
  notes: string | null;
  created_at: string;
  last_login: string | null;
}

export interface AuthUser {
  id: number;
  username: string;
  email: string;
  isAdmin: boolean;
  version: string;
}

export interface Door {
  name: string;
  location: string;
  doornum: number;
  description: string;
  ip_address: string;
  schedule_id: number | null;
  unlock_duration: number;
  status: 'online' | 'offline' | 'unknown';
  last_seen: string | null;
  locked: number;
  held_open: number;
  hold_requested: number;
  lockdown_mode: number;
  reader_type: string;
  controller_version: string | null;
  update_requested: number;
  update_status: string | null;
  unlock_requested: number;
  poll_interval: number;
  listen_port: number | null;
  push_available: number;
}

export interface Card {
  card_id: number;
  user_id: string;
  facility: string;
  firstname: string;
  lastname: string;
  doors: string;
  active: number;
  master_card: number;
  group_id: number | null;
  group_name: string | null;
  schedule_id: number | null;
  schedule_name: string | null;
  valid_from: string | null;
  valid_until: string | null;
  daily_scan_limit: number | null;
  email: string | null;
  phone: string | null;
  department: string | null;
  employee_id: string | null;
  company: string | null;
  title: string | null;
  notes: string | null;
}

export interface AccessLog {
  user_id: string;
  Date: string;
  Granted: number;
  Location: string;
  doorip: string;
  firstname?: string;
  lastname?: string;
  card_id?: number;
}

export interface Schedule {
  id: number;
  name: string;
  description: string;
  is_24_7: number;
  monday_start: string | null;
  monday_end: string | null;
  tuesday_start: string | null;
  tuesday_end: string | null;
  wednesday_start: string | null;
  wednesday_end: string | null;
  thursday_start: string | null;
  thursday_end: string | null;
  friday_start: string | null;
  friday_end: string | null;
  saturday_start: string | null;
  saturday_end: string | null;
  sunday_start: string | null;
  sunday_end: string | null;
}

export interface AccessGroup {
  id: number;
  name: string;
  description: string;
  doors: string; // JSON array of door names
  member_count: number;
}

export interface Holiday {
  id: number;
  name: string;
  date: string;
  recurring: number;
  access_denied: number;
}

export interface AuditLog {
  id: number;
  event_type: string;
  user_id: number | null;
  ip_address: string;
  user_agent: string;
  details: string;
  created_at: string;
  username?: string;
}

export interface DashboardData {
  total_cards: number;
  active_cards: number;
  total_doors: number;
  online_doors: number;
  today_access: number;
  today_granted: number;
  today_denied: number;
  doors: Door[];
  recent_logs: AccessLog[];
  hourly: number[];
}

export interface Settings {
  [key: string]: string;
}

export interface Backup {
  name: string;
  size: number;
  date: string;
}

export interface ApiResponse<T = unknown> {
  ok: boolean;
  data?: T;
  msg?: string;
  error?: string;
}
