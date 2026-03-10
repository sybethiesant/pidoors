import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { RefreshCw, Loader2, CheckCircle, AlertTriangle, Wifi, WifiOff } from 'lucide-react';
import { getUpdateStatus, runServerUpdate, getControllers, requestControllerUpdate } from '../api/updates';
import toast from 'react-hot-toast';

export function UpdatePage() {
  const queryClient = useQueryClient();

  const { data: status, isLoading: statusLoading } = useQuery({
    queryKey: ['update-status'],
    queryFn: getUpdateStatus,
  });

  const { data: controllers = [], isLoading: controllersLoading } = useQuery({
    queryKey: ['controllers'],
    queryFn: getControllers,
    refetchInterval: 5000,
  });

  const serverUpdateMutation = useMutation({
    mutationFn: runServerUpdate,
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['update-status'] });
      toast.success('Server update completed');
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const controllerUpdateMutation = useMutation({
    mutationFn: (name: string) => requestControllerUpdate(name),
    onSuccess: (_, name) => {
      queryClient.invalidateQueries({ queryKey: ['controllers'] });
      toast.success(`Update requested for ${name}`);
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const isUpToDate = status && status.current_version && status.latest_version &&
    status.current_version.replace(/^v/, '') === status.latest_version.replace(/^v/, '');

  if (statusLoading) {
    return <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-primary-600" /></div>;
  }

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Updates</h1>

      {/* Server version */}
      <div className="card p-6">
        <h2 className="text-lg font-semibold text-slate-900 dark:text-white mb-4">Server</h2>
        <div className="flex flex-wrap items-center gap-4">
          <div>
            <p className="text-sm text-slate-500">Current Version</p>
            <p className="text-lg font-mono font-bold text-slate-900 dark:text-white">{status?.current_version || 'Unknown'}</p>
          </div>
          <div>
            <p className="text-sm text-slate-500">Latest Version</p>
            <p className="text-lg font-mono font-bold text-slate-900 dark:text-white">{status?.latest_version || 'Unknown'}</p>
          </div>
          <div className="ml-auto">
            {isUpToDate ? (
              <span className="badge badge-success text-sm"><CheckCircle className="mr-1 h-4 w-4" />Up to date</span>
            ) : (
              <button
                onClick={() => serverUpdateMutation.mutate()}
                className="btn btn-primary"
                disabled={serverUpdateMutation.isPending}
              >
                {serverUpdateMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
                Update Server
              </button>
            )}
          </div>
        </div>
      </div>

      {/* Controllers */}
      <div className="card p-6">
        <h2 className="text-lg font-semibold text-slate-900 dark:text-white mb-4">Door Controllers</h2>
        {controllersLoading ? (
          <Loader2 className="h-6 w-6 animate-spin text-primary-600" />
        ) : controllers.length === 0 ? (
          <p className="text-slate-500">No door controllers configured.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 dark:border-slate-700">
                  <th className="px-4 py-2 text-left font-medium text-slate-500">Door</th>
                  <th className="px-4 py-2 text-left font-medium text-slate-500">IP</th>
                  <th className="px-4 py-2 text-left font-medium text-slate-500">Version</th>
                  <th className="px-4 py-2 text-left font-medium text-slate-500">Status</th>
                  <th className="px-4 py-2 text-right font-medium text-slate-500">Action</th>
                </tr>
              </thead>
              <tbody>
                {controllers.map((c) => (
                  <tr key={c.name} className="border-b border-slate-100 dark:border-slate-700/50">
                    <td className="px-4 py-3 font-medium text-slate-900 dark:text-white">{c.name}</td>
                    <td className="px-4 py-3 font-mono text-xs text-slate-500">{c.ip_address || '-'}</td>
                    <td className="px-4 py-3 font-mono text-slate-700 dark:text-slate-300">{c.controller_version || '-'}</td>
                    <td className="px-4 py-3">
                      <span className={`badge ${c.status === 'online' ? 'badge-success' : 'badge-danger'}`}>
                        {c.status === 'online' ? <Wifi className="mr-1 h-3 w-3" /> : <WifiOff className="mr-1 h-3 w-3" />}
                        {c.status}
                      </span>
                      {c.update_status && (
                        <span className="badge badge-info ml-1">{c.update_status}</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-right">
                      {c.status === 'online' && !c.update_requested ? (
                        <button
                          onClick={() => controllerUpdateMutation.mutate(c.name)}
                          className="btn btn-sm btn-outline"
                          disabled={controllerUpdateMutation.isPending}
                        >
                          <RefreshCw className="h-3 w-3" />
                          Update
                        </button>
                      ) : c.update_requested ? (
                        <span className="badge badge-warning">Pending</span>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
