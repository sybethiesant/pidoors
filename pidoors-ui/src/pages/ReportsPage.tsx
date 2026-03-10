import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ClipboardList, Download, Loader2 } from 'lucide-react';
import { getReport, getExportUrl, type ReportType } from '../api/reports';
import {
  BarChart, Bar, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip,
  ResponsiveContainer, Legend,
} from 'recharts';

const REPORT_TYPES: { value: ReportType; label: string }[] = [
  { value: 'access_summary', label: 'Access Summary by Door' },
  { value: 'daily_activity', label: 'Daily Activity' },
  { value: 'user_activity', label: 'User Activity' },
  { value: 'hourly_pattern', label: 'Hourly Pattern' },
  { value: 'denied_access', label: 'Denied Access' },
];

export function ReportsPage() {
  const [type, setType] = useState<ReportType>('access_summary');
  const [from, setFrom] = useState(() => {
    const d = new Date();
    d.setDate(d.getDate() - 30);
    return d.toISOString().split('T')[0];
  });
  const [to, setTo] = useState(() => new Date().toISOString().split('T')[0]);

  const { data, isLoading } = useQuery({
    queryKey: ['reports', type, from, to],
    queryFn: () => getReport({ type, from, to }),
  });

  const report = (data?.report ?? []) as Record<string, unknown>[];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Reports</h1>
        <a href={getExportUrl({ type, from, to })} className="btn btn-outline" download>
          <Download className="h-4 w-4" />
          Export CSV
        </a>
      </div>

      {/* Filters */}
      <div className="card p-4">
        <div className="grid gap-4 sm:grid-cols-3">
          <div>
            <label className="label">Report Type</label>
            <select className="input" value={type} onChange={(e) => setType(e.target.value as ReportType)}>
              {REPORT_TYPES.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
            </select>
          </div>
          <div>
            <label className="label">From</label>
            <input type="date" className="input" value={from} onChange={(e) => setFrom(e.target.value)} />
          </div>
          <div>
            <label className="label">To</label>
            <input type="date" className="input" value={to} onChange={(e) => setTo(e.target.value)} />
          </div>
        </div>
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-primary-600" /></div>
      ) : report.length === 0 ? (
        <div className="card flex flex-col items-center justify-center py-16">
          <ClipboardList className="h-12 w-12 text-slate-300 dark:text-slate-600" />
          <p className="mt-4 text-slate-500">No data for this report period.</p>
        </div>
      ) : (
        <>
          {/* Chart */}
          {(type === 'access_summary' || type === 'daily_activity' || type === 'hourly_pattern') && (
            <div className="card p-4">
              <ResponsiveContainer width="100%" height={300}>
                {type === 'daily_activity' ? (
                  <LineChart data={report}>
                    <CartesianGrid strokeDasharray="3 3" className="stroke-slate-200 dark:stroke-slate-700" />
                    <XAxis dataKey="date" tick={{ fontSize: 11 }} />
                    <YAxis allowDecimals={false} tick={{ fontSize: 11 }} />
                    <Tooltip />
                    <Legend />
                    <Line type="monotone" dataKey="granted" stroke="#10b981" name="Granted" />
                    <Line type="monotone" dataKey="denied" stroke="#ef4444" name="Denied" />
                  </LineChart>
                ) : (
                  <BarChart data={report}>
                    <CartesianGrid strokeDasharray="3 3" className="stroke-slate-200 dark:stroke-slate-700" />
                    <XAxis dataKey={type === 'hourly_pattern' ? 'hour' : 'door'} tick={{ fontSize: 11 }} />
                    <YAxis allowDecimals={false} tick={{ fontSize: 11 }} />
                    <Tooltip />
                    <Legend />
                    <Bar dataKey="granted" fill="#10b981" name="Granted" radius={[4, 4, 0, 0]} />
                    <Bar dataKey="denied" fill="#ef4444" name="Denied" radius={[4, 4, 0, 0]} />
                  </BarChart>
                )}
              </ResponsiveContainer>
            </div>
          )}

          {/* Table */}
          <div className="card overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 dark:border-slate-700">
                    {Object.keys(report[0] || {}).map((key) => (
                      <th key={key} className="px-4 py-3 text-left font-medium text-slate-500 capitalize">
                        {key.replace(/_/g, ' ')}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {report.map((row, i) => (
                    <tr key={i} className="border-b border-slate-100 dark:border-slate-700/50">
                      {Object.values(row).map((val, j) => (
                        <td key={j} className="px-4 py-3 text-slate-700 dark:text-slate-300">
                          {val === null ? '-' : String(val)}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
