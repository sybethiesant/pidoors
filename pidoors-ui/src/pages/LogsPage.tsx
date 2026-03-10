import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  FileText,
  Download,
  Search,
  Loader2,
  ChevronLeft,
  ChevronRight,
  Filter,
} from 'lucide-react';
import { getLogs, getExportUrl, type LogFilters } from '../api/logs';
import { getDoors } from '../api/doors';

export function LogsPage() {
  const [filters, setFilters] = useState<LogFilters>({ page: 1, limit: 50 });
  const [showFilters, setShowFilters] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['logs', filters],
    queryFn: () => getLogs(filters),
    refetchInterval: 5000,
  });

  const { data: doors = [] } = useQuery({
    queryKey: ['doors-list'],
    queryFn: getDoors,
  });

  const setFilter = (key: string, value: string) => {
    setFilters((prev) => ({ ...prev, [key]: value, page: 1 }));
  };

  if (isLoading && !data) {
    return <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-primary-600" /></div>;
  }

  const logs = data?.logs ?? [];
  const total = data?.total ?? 0;
  const page = data?.page ?? 1;
  const pages = data?.pages ?? 1;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Access Logs</h1>
        <div className="flex gap-2">
          <button onClick={() => setShowFilters(!showFilters)} className="btn btn-outline">
            <Filter className="h-4 w-4" />
            Filters
          </button>
          <a href={getExportUrl(filters)} className="btn btn-outline" download>
            <Download className="h-4 w-4" />
            Export CSV
          </a>
        </div>
      </div>

      {/* Filters */}
      {showFilters && (
        <div className="card p-4">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div>
              <label className="label">Search</label>
              <div className="relative">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                  className="input pl-9"
                  placeholder="Name or card #"
                  value={filters.search || ''}
                  onChange={(e) => setFilter('search', e.target.value)}
                />
              </div>
            </div>
            <div>
              <label className="label">Door</label>
              <select className="input" value={filters.door || ''} onChange={(e) => setFilter('door', e.target.value)}>
                <option value="">All Doors</option>
                {doors.map((d) => <option key={d.name} value={d.name}>{d.name}</option>)}
              </select>
            </div>
            <div>
              <label className="label">Status</label>
              <select className="input" value={filters.granted ?? ''} onChange={(e) => setFilter('granted', e.target.value)}>
                <option value="">All</option>
                <option value="1">Granted</option>
                <option value="0">Denied</option>
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

      {/* Table */}
      <div className="card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 dark:border-slate-700">
                <th className="px-4 py-3 text-left font-medium text-slate-500 dark:text-slate-400">Time</th>
                <th className="px-4 py-3 text-left font-medium text-slate-500 dark:text-slate-400">Card #</th>
                <th className="px-4 py-3 text-left font-medium text-slate-500 dark:text-slate-400">Name</th>
                <th className="px-4 py-3 text-left font-medium text-slate-500 dark:text-slate-400">Door</th>
                <th className="px-4 py-3 text-left font-medium text-slate-500 dark:text-slate-400">Status</th>
              </tr>
            </thead>
            <tbody>
              {logs.length === 0 ? (
                <tr>
                  <td colSpan={5} className="px-4 py-8 text-center text-slate-500">
                    No logs found
                  </td>
                </tr>
              ) : (
                logs.map((log, i) => {
                  const name = [log.firstname, log.lastname].filter(Boolean).join(' ') || `User #${log.user_id}`;
                  return (
                    <tr key={i} className="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30">
                      <td className="whitespace-nowrap px-4 py-3 text-slate-700 dark:text-slate-300">
                        {new Date(log.Date).toLocaleString([], {
                          month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit',
                        })}
                      </td>
                      <td className="px-4 py-3 font-mono text-slate-500">{log.user_id}</td>
                      <td className="px-4 py-3 text-slate-900 dark:text-white">{name}</td>
                      <td className="px-4 py-3 text-slate-700 dark:text-slate-300">{log.Location}</td>
                      <td className="px-4 py-3">
                        <span className={`badge ${log.Granted ? 'badge-success' : 'badge-danger'}`}>
                          {log.Granted ? 'Granted' : 'Denied'}
                        </span>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {pages > 1 && (
          <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 dark:border-slate-700">
            <p className="text-sm text-slate-500">
              Page {page} of {pages} ({total.toLocaleString()} total)
            </p>
            <div className="flex gap-1">
              <button
                onClick={() => setFilters((prev) => ({ ...prev, page: Math.max(1, (prev.page || 1) - 1) }))}
                disabled={page <= 1}
                className="btn btn-sm btn-ghost"
              >
                <ChevronLeft className="h-4 w-4" />
              </button>
              <button
                onClick={() => setFilters((prev) => ({ ...prev, page: Math.min(pages, (prev.page || 1) + 1) }))}
                disabled={page >= pages}
                className="btn btn-sm btn-ghost"
              >
                <ChevronRight className="h-4 w-4" />
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
