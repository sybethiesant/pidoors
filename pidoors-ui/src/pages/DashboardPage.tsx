import { useQuery } from '@tanstack/react-query';
import {
  CreditCard,
  DoorOpen,
  Layers,
  ShieldOff,
  Loader2,
  Lock,
  Unlock,
  LockOpen,
  Wifi,
  WifiOff,
  ArrowUp,
  ArrowDown,
  Square,
} from 'lucide-react';
import { getDashboard } from '../api/dashboard';
import { unlockDoor, holdDoor, gateCommand } from '../api/doors';
import { useAuth } from '../hooks/useAuth';
import toast from 'react-hot-toast';
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts';
import type { DashboardData, Door } from '../types';

function StatCard({
  label,
  value,
  sub,
  icon: Icon,
  color,
}: {
  label: string;
  value: number;
  sub?: string;
  icon: React.ElementType;
  color: string;
}) {
  const colorMap: Record<string, string> = {
    blue: 'text-blue-500',
    green: 'text-emerald-500',
    amber: 'text-amber-500',
    red: 'text-red-500',
  };
  const subColorMap: Record<string, string> = {
    blue: 'text-blue-600 dark:text-blue-400',
    green: 'text-emerald-600 dark:text-emerald-400',
    amber: 'text-emerald-600 dark:text-emerald-400',
    red: 'text-slate-500',
  };

  return (
    <div className="card p-5">
      <div className="flex items-start justify-between">
        <div>
          <p className="text-sm font-medium text-slate-500 dark:text-slate-400">
            {label}
          </p>
          <p className="mt-1 text-2xl font-bold text-slate-900 dark:text-white">
            {value.toLocaleString()}
          </p>
          {sub && (
            <p className={`mt-1 text-sm ${subColorMap[color]}`}>{sub}</p>
          )}
        </div>
        <Icon className={`h-10 w-10 ${colorMap[color]} opacity-80`} />
      </div>
    </div>
  );
}

function DoorStatusItem({
  door,
  isAdmin,
  onAction,
}: {
  door: Door;
  isAdmin: boolean;
  onAction: () => void;
}) {
  const handleUnlock = async () => {
    try {
      await unlockDoor(door.name);
      toast.success(`${door.name} Unlocked`);
      onAction();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Unlock failed');
    }
  };

  const handleHold = async (action: 'hold' | 'release') => {
    try {
      await holdDoor(door.name, action);
      toast.success(action === 'hold' ? `${door.name} Held Open` : `${door.name} Released`);
      onAction();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Action failed');
    }
  };

  const handleGate = async (action: 'open' | 'close' | 'stop' | 'hold' | 'release') => {
    try {
      await gateCommand(door.name, action);
      toast.success(`${door.name}: ${action}`);
      onAction();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Gate command failed');
    }
  };

  const statusIcon =
    door.status === 'online' ? (
      <Wifi className="h-3.5 w-3.5 text-emerald-500" />
    ) : (
      <WifiOff className="h-3.5 w-3.5 text-red-500" />
    );

  const isGate = !!door.is_gate;
  let stateBadge = null;
  if (door.status === 'online') {
    if (isGate) {
      const cls =
        door.gate_state === 'open' ? 'badge-warning' :
        door.gate_state === 'closed' ? 'badge-success' :
        door.gate_state === 'opening' || door.gate_state === 'closing' ? 'badge-info' :
        door.gate_state === 'stopped' ? 'badge-danger' : 'badge-secondary';
      stateBadge = (
        <>
          <span className={`badge ${cls}`}>Gate: {door.gate_state}</span>
          {door.gate_held ? <span className="badge badge-warning">Held</span> : null}
        </>
      );
    } else if (door.held_open) {
      stateBadge = <span className="badge badge-warning">Held Open</span>;
    } else if (door.unlock_requested) {
      stateBadge = <span className="badge badge-info">Unlocking</span>;
    } else if (door.locked) {
      stateBadge = <span className="badge badge-success">Locked</span>;
    } else {
      stateBadge = <span className="badge badge-warning">Unlocked</span>;
    }
  }

  return (
    <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 last:border-0 dark:border-slate-700/50">
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <span className="font-medium text-slate-900 dark:text-white">
            {door.name}
          </span>
          {door.location && (
            <span className="text-xs text-slate-500">{door.location}</span>
          )}
        </div>
      </div>

      <div className="flex items-center gap-2 flex-wrap justify-end">
        {stateBadge}
        {door.status === 'online' && door.door_sensor_gpio !== null && (
          door.door_open === 1 ? (
            <span className="badge badge-warning">Sensor: Open</span>
          ) : door.door_open === 0 ? (
            <span className="badge badge-success">Sensor: Closed</span>
          ) : (
            <span className="badge badge-secondary">Sensor N/A</span>
          )
        )}
        <span
          className={`badge ${door.status === 'online' ? 'badge-success' : door.status === 'offline' ? 'badge-danger' : 'badge-secondary'}`}
        >
          <span className="mr-1">{statusIcon}</span>
          {door.status.charAt(0).toUpperCase() + door.status.slice(1)}
        </span>
        {door.status === 'online' && isAdmin && isGate && (
          <>
            <button
              onClick={() => handleGate('open')}
              className="btn btn-sm btn-success"
              disabled={!!door.gate_held}
              title={door.gate_held ? 'Release hold first' : 'Open gate'}
            >
              <ArrowUp className="h-3 w-3" />
              Open
            </button>
            <button
              onClick={() => handleGate('stop')}
              className="btn btn-sm btn-warning"
              disabled={door.gate_state !== 'opening' && door.gate_state !== 'closing'}
              title={door.gate_state !== 'opening' && door.gate_state !== 'closing' ? 'Gate is not moving' : 'Stop gate'}
            >
              <Square className="h-3 w-3" />
              Stop
            </button>
            <button
              onClick={() => handleGate('close')}
              className="btn btn-sm btn-secondary"
              disabled={!!door.gate_held}
              title={door.gate_held ? 'Release hold first' : 'Close gate'}
            >
              <ArrowDown className="h-3 w-3" />
              Close
            </button>
            {door.gate_held ? (
              <button onClick={() => handleGate('release')} className="btn btn-sm btn-danger">
                <LockOpen className="h-3 w-3" />
                Release
              </button>
            ) : (
              <button onClick={() => handleGate('hold')} className="btn btn-sm btn-ghost">
                <Lock className="h-3 w-3" />
                Hold
              </button>
            )}
          </>
        )}
        {door.status === 'online' && isAdmin && !isGate && !door.unlock_requested && (
          <>
            {door.held_open ? (
              <button
                onClick={() => handleHold('release')}
                className="btn btn-sm btn-danger"
              >
                <LockOpen className="h-3 w-3" />
                Unhold
              </button>
            ) : (
              <>
                <button onClick={handleUnlock} className="btn btn-sm btn-warning">
                  <Unlock className="h-3 w-3" />
                  Unlock
                </button>
                <button
                  onClick={() => handleHold('hold')}
                  className="btn btn-sm btn-secondary"
                >
                  <Lock className="h-3 w-3" />
                  Hold
                </button>
              </>
            )}
          </>
        )}
      </div>
    </div>
  );
}

export function DashboardPage() {
  const { user } = useAuth();

  const { data, isLoading, refetch } = useQuery<DashboardData>({
    queryKey: ['dashboard'],
    queryFn: getDashboard,
    refetchInterval: 3000,
    refetchIntervalInBackground: false,
  });

  if (isLoading || !data) {
    return (
      <div className="flex items-center justify-center py-20">
        <Loader2 className="h-8 w-8 animate-spin text-primary-600" />
      </div>
    );
  }

  const hourlyChartData = data.hourly.map((count, i) => ({
    hour: `${i}:00`,
    events: count,
  }));

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
        Dashboard
      </h1>

      {/* Stat cards */}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="Total Cards"
          value={data.total_cards}
          sub={`${data.active_cards.toLocaleString()} active`}
          icon={CreditCard}
          color="blue"
        />
        <StatCard
          label="Doors"
          value={data.total_doors}
          sub={`${data.online_doors.toLocaleString()} online`}
          icon={DoorOpen}
          color="green"
        />
        <StatCard
          label="Today's Access"
          value={data.today_access}
          sub={`${data.today_granted.toLocaleString()} granted`}
          icon={Layers}
          color="amber"
        />
        <StatCard
          label="Denied Today"
          value={data.today_denied}
          sub="access attempts"
          icon={ShieldOff}
          color="red"
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        {/* Door status panel */}
        <div className="card lg:col-span-1">
          <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-700">
            <h2 className="font-semibold text-slate-900 dark:text-white">
              Door Status
            </h2>
          </div>
          <div>
            {data.doors.length === 0 ? (
              <p className="p-4 text-sm text-slate-500">No doors configured</p>
            ) : (
              data.doors.map((door) => (
                <DoorStatusItem
                  key={door.name}
                  door={door}
                  isAdmin={user?.isAdmin ?? false}
                  onAction={() => refetch()}
                />
              ))
            )}
          </div>
        </div>

        {/* Access chart */}
        <div className="card lg:col-span-2">
          <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-700">
            <h2 className="font-semibold text-slate-900 dark:text-white">
              Access Activity (Last 24 Hours)
            </h2>
          </div>
          <div className="p-4">
            <ResponsiveContainer width="100%" height={280}>
              <BarChart data={hourlyChartData}>
                <CartesianGrid
                  strokeDasharray="3 3"
                  className="stroke-slate-200 dark:stroke-slate-700"
                />
                <XAxis
                  dataKey="hour"
                  tick={{ fontSize: 11 }}
                  className="fill-slate-500"
                />
                <YAxis
                  allowDecimals={false}
                  tick={{ fontSize: 11 }}
                  className="fill-slate-500"
                />
                <Tooltip
                  contentStyle={{
                    backgroundColor: 'var(--tooltip-bg, #fff)',
                    border: '1px solid var(--tooltip-border, #e2e8f0)',
                    borderRadius: '8px',
                    fontSize: '13px',
                  }}
                />
                <Bar
                  dataKey="events"
                  fill="#3b82f6"
                  radius={[4, 4, 0, 0]}
                  name="Access Events"
                />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </div>
      </div>

      {/* Recent logs */}
      <div className="card">
        <div className="border-b border-slate-200 px-4 py-3 dark:border-slate-700">
          <h2 className="font-semibold text-slate-900 dark:text-white">
            Recent Access
          </h2>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 dark:border-slate-700">
                <th className="px-4 py-3 text-left font-medium text-slate-500 dark:text-slate-400">
                  Time
                </th>
                <th className="px-4 py-3 text-left font-medium text-slate-500 dark:text-slate-400">
                  User
                </th>
                <th className="px-4 py-3 text-left font-medium text-slate-500 dark:text-slate-400">
                  Location
                </th>
                <th className="px-4 py-3 text-left font-medium text-slate-500 dark:text-slate-400">
                  Status
                </th>
              </tr>
            </thead>
            <tbody>
              {data.recent_logs.length === 0 ? (
                <tr>
                  <td
                    colSpan={4}
                    className="px-4 py-8 text-center text-slate-500"
                  >
                    No recent access logs
                  </td>
                </tr>
              ) : (
                data.recent_logs.map((log, i) => {
                  const name =
                    [log.firstname, log.lastname].filter(Boolean).join(' ') ||
                    `User #${log.user_id}`;
                  return (
                    <tr
                      key={i}
                      className="border-b border-slate-100 dark:border-slate-700/50"
                    >
                      <td className="px-4 py-3 text-slate-700 dark:text-slate-300">
                        {new Date(log.Date).toLocaleString([], {
                          month: 'short',
                          day: 'numeric',
                          hour: 'numeric',
                          minute: '2-digit',
                        })}
                      </td>
                      <td className="px-4 py-3 text-slate-900 dark:text-white">
                        {name}
                      </td>
                      <td className="px-4 py-3 text-slate-700 dark:text-slate-300">
                        {log.Location}
                      </td>
                      <td className="px-4 py-3">
                        {log.Granted ? (
                          <span className="badge badge-success">Granted</span>
                        ) : (
                          <span className="badge badge-danger">Denied</span>
                        )}
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
