import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { RefreshCw, Loader2, CheckCircle, AlertTriangle, Wifi, WifiOff } from 'lucide-react';
import { getUpdateStatus, checkForUpdates, runServerUpdate, getControllers, requestControllerUpdate, requestAllControllerUpdates } from '../api/updates';
import toast from 'react-hot-toast';

export function UpdatePage() {
  const queryClient = useQueryClient();
  const [updateResult, setUpdateResult] = useState<{ output: string; hasWarnings: boolean } | null>(null);
  const [showReloadPrompt, setShowReloadPrompt] = useState(false);

  const { data: status, isLoading: statusLoading } = useQuery({
    queryKey: ['update-status'],
    queryFn: getUpdateStatus,
    staleTime: 60_000,
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
      const output = data.output || 'Update completed.';
      const hasWarnings = /warning|failed|error/i.test(output);
      setUpdateResult({ output, hasWarnings });
      setShowReloadPrompt(true);
      if (hasWarnings) {
        toast.error('Update completed with warnings — see details below');
      } else {
        toast.success('Server update completed successfully');
      }
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

  const allControllersMutation = useMutation({
    mutationFn: requestAllControllerUpdates,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['controllers'] });
      toast.success('Update requested for all online controllers');
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const checkMutation = useMutation({
    mutationFn: checkForUpdates,
    onSuccess: (data) => {
      queryClient.setQueryData(['update-status'], data);
      queryClient.invalidateQueries({ queryKey: ['update-status'] });
      toast.success('Update check complete');
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const autoCheckDisabled = status?.latest_version === 'disabled';
  const isUpToDate = !autoCheckDisabled && status && status.current_version && status.latest_version &&
    status.current_version.replace(/^v/, '') === status.latest_version.replace(/^v/, '');

  const targetVersion = status?.current_version?.replace(/^v/, '') || '';
  const hasOutdatedControllers = controllers.some(
    (c) => c.status === 'online' && c.controller_version && targetVersion &&
      c.controller_version !== targetVersion && !c.update_requested
  );

  const handleServerUpdate = () => {
    const version = status?.latest_version || 'latest';
    if (window.confirm(`Update server to version ${version}? The web interface files will be replaced.`)) {
      setUpdateResult(null);
      setShowReloadPrompt(false);
      serverUpdateMutation.mutate();
    }
  };

  if (statusLoading) {
    return <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-primary-600" /></div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Updates</h1>
        <button
          onClick={() => checkMutation.mutate()}
          className="btn btn-outline btn-sm"
          disabled={checkMutation.isPending}
        >
          {checkMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
          Check for Updates
        </button>
      </div>

      {/* Reload prompt */}
      {showReloadPrompt && (
        <div className="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
          <p className="text-sm font-medium text-blue-800 dark:text-blue-200">
            The server has been updated. Please reload the page to use the new version.
          </p>
          <button
            onClick={() => window.location.reload()}
            className="btn btn-primary mt-2 btn-sm"
          >
            Reload Now
          </button>
        </div>
      )}

      {/* Update result details */}
      {updateResult && (
        <div className={`rounded-lg border p-4 ${updateResult.hasWarnings
          ? 'border-yellow-200 bg-yellow-50 dark:border-yellow-800 dark:bg-yellow-900/20'
          : 'border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-900/20'}`}
        >
          <h3 className={`text-sm font-semibold mb-2 ${updateResult.hasWarnings
            ? 'text-yellow-800 dark:text-yellow-200'
            : 'text-green-800 dark:text-green-200'}`}
          >
            {updateResult.hasWarnings ? 'Update completed with warnings' : 'Update completed successfully'}
          </h3>
          <pre className="text-xs text-slate-700 dark:text-slate-300 whitespace-pre-wrap font-mono">
            {updateResult.output}
          </pre>
        </div>
      )}

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
            <p className="text-lg font-mono font-bold text-slate-900 dark:text-white">
              {autoCheckDisabled ? <span className="text-slate-400">Auto-check disabled</span> : (status?.latest_version || 'Unknown')}
            </p>
          </div>
          <div className="ml-auto">
            {isUpToDate ? (
              <span className="badge badge-success text-sm"><CheckCircle className="mr-1 h-4 w-4" />Up to date</span>
            ) : (
              <button
                onClick={handleServerUpdate}
                className="btn btn-primary"
                disabled={serverUpdateMutation.isPending}
              >
                {serverUpdateMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
                {serverUpdateMutation.isPending ? 'Updating...' : 'Update Server'}
              </button>
            )}
          </div>
        </div>
      </div>

      {/* Controllers */}
      <div className="card p-6">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Door Controllers</h2>
          {hasOutdatedControllers && (
            <button
              onClick={() => {
                if (window.confirm('Request update for all online controllers?')) {
                  allControllersMutation.mutate();
                }
              }}
              className="btn btn-sm btn-warning"
              disabled={allControllersMutation.isPending}
            >
              {allControllersMutation.isPending ? <Loader2 className="h-3 w-3 animate-spin" /> : <RefreshCw className="h-3 w-3" />}
              Update All
            </button>
          )}
        </div>
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
                    <td className="px-4 py-3 font-mono text-slate-700 dark:text-slate-300">
                      {c.controller_version || '-'}
                      {c.controller_version && targetVersion && c.controller_version !== targetVersion && (
                        <span className="badge badge-warning ml-1 text-xs">Outdated</span>
                      )}
                    </td>
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
