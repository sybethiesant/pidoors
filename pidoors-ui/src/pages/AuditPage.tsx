import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Shield, Loader2, ChevronLeft, ChevronRight, Filter, X, Search } from 'lucide-react';
import { getAuditLogs, type AuditFilters } from '../api/audit';
import type { AuditLog } from '../types';

function DetailModal({ log, onClose }: { log: AuditLog; onClose: () => void }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="card w-full max-w-md p-6">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Audit Detail</h2>
          <button onClick={onClose} className="btn-ghost rounded-lg p-1"><X className="h-5 w-5" /></button>
        </div>
        <div className="space-y-3 text-sm">
          <div><span className="font-medium text-slate-500">Event:</span> <span className="text-slate-900 dark:text-white">{log.event_type}</span></div>
          <div><span className="font-medium text-slate-500">User:</span> <span className="text-slate-900 dark:text-white">{log.username || `User #${log.user_id}` || 'System'}</span></div>
          <div><span className="font-medium text-slate-500">IP:</span> <span className="text-slate-900 dark:text-white">{log.ip_address}</span></div>
          <div><span className="font-medium text-slate-500">Time:</span> <span className="text-slate-900 dark:text-white">{new Date(log.created_at).toLocaleString()}</span></div>
          <div><span className="font-medium text-slate-500">User Agent:</span> <span className="text-xs text-slate-700 dark:text-slate-300 break-all">{log.user_agent}</span></div>
          <div><span className="font-medium text-slate-500">Details:</span> <span className="text-slate-900 dark:text-white">{log.details}</span></div>
        </div>
      </div>
    </div>
  );
}

function eventBadgeClass(type: string): string {
  if (type.includes('login_success') || type.includes('created')) return 'badge-success';
  if (type.includes('login_failed') || type.includes('deleted') || type.includes('lockout')) return 'badge-danger';
  if (type.includes('unlock') || type.includes('update') || type.includes('hold')) return 'badge-warning';
  if (type.includes('settings') || type.includes('updated') || type.includes('password')) return 'badge-info';
  return 'badge-secondary';
}

export function AuditPage() {
  const [filters, setFilters] = useState<AuditFilters>({ page: 1, limit: 50 });
  const [showFilters, setShowFilters] = useState(false);
  const [detail, setDetail] = useState<AuditLog | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['audit', filters],
    queryFn: () => getAuditLogs(filters),
  });

  const setFilter = (key: string, value: string) => {
    setFilters((prev) => ({ ...prev, [key]: value, page: 1 }));
  };

  if (isLoading && !data) {
    return <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-primary-600" /></div>;
  }

  const logs = data?.logs ?? [];
  const page = data?.page ?? 1;
  const pages = data?.pages ?? 1;
  const total = data?.total ?? 0;
  const eventTypes = data?.event_types ?? [];

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Audit Log</h1>
        <button onClick={() => setShowFilters(!showFilters)} className="btn btn-outline">
          <Filter className="h-4 w-4" />
          Filters
        </button>
      </div>

      {showFilters && (
        <div className="card p-4">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <label className="label">Search</label>
              <div className="relative">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input className="input pl-9" placeholder="Details or username" value={filters.search || ''} onChange={(e) => setFilter('search', e.target.value)} />
              </div>
            </div>
            <div>
              <label className="label">Event Type</label>
              <select className="input" value={filters.type || ''} onChange={(e) => setFilter('type', e.target.value)}>
                <option value="">All Types</option>
                {eventTypes.map((t) => <option key={t} value={t}>{t}</option>)}
              </select>
            </div>
            <div>
              <label className="label">From</label>
              <input type="date" className="input" value={filters.from || ''} onChange={(e) => setFilter('from', e.target.value)} />
            </div>
            <div>
              <label className="label">To</label>
              <input type="date" className="input" value={filters.to || ''} onChange={(e) => setFilter('to', e.target.value)} />
            </div>
          </div>
        </div>
      )}

      <div className="card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 dark:border-slate-700">
                <th className="px-4 py-3 text-left font-medium text-slate-500">Time</th>
                <th className="px-4 py-3 text-left font-medium text-slate-500">Event</th>
                <th className="px-4 py-3 text-left font-medium text-slate-500 hidden md:table-cell">User</th>
                <th className="px-4 py-3 text-left font-medium text-slate-500 hidden lg:table-cell">IP</th>
                <th className="px-4 py-3 text-left font-medium text-slate-500">Details</th>
              </tr>
            </thead>
            <tbody>
              {logs.length === 0 ? (
                <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-500">No audit logs found</td></tr>
              ) : (
                logs.map((log) => (
                  <tr
                    key={log.id}
                    className="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30 cursor-pointer"
                    onClick={() => setDetail(log)}
                  >
                    <td className="whitespace-nowrap px-4 py-3 text-slate-700 dark:text-slate-300">
                      {new Date(log.created_at).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}
                    </td>
                    <td className="px-4 py-3">
                      <span className={`badge ${eventBadgeClass(log.event_type)}`}>{log.event_type}</span>
                    </td>
                    <td className="px-4 py-3 text-slate-700 dark:text-slate-300 hidden md:table-cell">
                      {log.username || (log.user_id ? `User #${log.user_id}` : 'System')}
                    </td>
                    <td className="px-4 py-3 text-slate-500 hidden lg:table-cell font-mono text-xs">{log.ip_address}</td>
                    <td className="px-4 py-3 text-slate-600 dark:text-slate-400 max-w-[300px] truncate">{log.details}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {pages > 1 && (
          <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 dark:border-slate-700">
            <p className="text-sm text-slate-500">Page {page} of {pages} ({total.toLocaleString()} total)</p>
            <div className="flex gap-1">
              <button onClick={() => setFilters((p) => ({ ...p, page: Math.max(1, (p.page || 1) - 1) }))} disabled={page <= 1} className="btn btn-sm btn-ghost"><ChevronLeft className="h-4 w-4" /></button>
              <button onClick={() => setFilters((p) => ({ ...p, page: Math.min(pages, (p.page || 1) + 1) }))} disabled={page >= pages} className="btn btn-sm btn-ghost"><ChevronRight className="h-4 w-4" /></button>
            </div>
          </div>
        )}
      </div>

      {detail && <DetailModal log={detail} onClose={() => setDetail(null)} />}
    </div>
  );
}
