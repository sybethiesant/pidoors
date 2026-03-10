import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Database, Plus, Download, Trash2, Loader2 } from 'lucide-react';
import { getBackups, createBackup, getDownloadUrl, deleteBackup } from '../api/backups';
import toast from 'react-hot-toast';
import type { Backup } from '../types';

function formatSize(bytes: number): string {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

export function BackupPage() {
  const queryClient = useQueryClient();
  const [confirmDelete, setConfirmDelete] = useState<string | null>(null);

  const { data: backups = [], isLoading } = useQuery({ queryKey: ['backups'], queryFn: getBackups });

  const createMutation = useMutation({
    mutationFn: createBackup,
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['backups'] });
      toast.success(`Backup created: ${(data as { name?: string }).name}`);
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const deleteMutation = useMutation({
    mutationFn: (name: string) => deleteBackup(name),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['backups'] }); setConfirmDelete(null); toast.success('Backup deleted'); },
    onError: (err: Error) => toast.error(err.message),
  });

  if (isLoading) {
    return <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-primary-600" /></div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Backups</h1>
        <button onClick={() => createMutation.mutate()} className="btn btn-primary" disabled={createMutation.isPending}>
          {createMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}
          Create Backup
        </button>
      </div>

      {backups.length === 0 ? (
        <div className="card flex flex-col items-center justify-center py-16">
          <Database className="h-12 w-12 text-slate-300 dark:text-slate-600" />
          <p className="mt-4 text-slate-500">No backups yet.</p>
        </div>
      ) : (
        <div className="card overflow-hidden">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 dark:border-slate-700">
                <th className="px-4 py-3 text-left font-medium text-slate-500">Filename</th>
                <th className="px-4 py-3 text-left font-medium text-slate-500">Size</th>
                <th className="px-4 py-3 text-left font-medium text-slate-500">Date</th>
                <th className="px-4 py-3 text-right font-medium text-slate-500">Actions</th>
              </tr>
            </thead>
            <tbody>
              {backups.map((b) => (
                <tr key={b.name} className="border-b border-slate-100 dark:border-slate-700/50">
                  <td className="px-4 py-3 font-mono text-sm text-slate-900 dark:text-white">{b.name}</td>
                  <td className="px-4 py-3 text-slate-500">{formatSize(b.size)}</td>
                  <td className="px-4 py-3 text-slate-700 dark:text-slate-300">{new Date(b.date).toLocaleString()}</td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-1">
                      <a href={getDownloadUrl(b.name)} className="btn btn-sm btn-ghost" download title="Download">
                        <Download className="h-4 w-4" />
                      </a>
                      <button onClick={() => setConfirmDelete(b.name)} className="btn btn-sm rounded p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20" title="Delete">
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {confirmDelete && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="card w-full max-w-sm p-6">
            <h3 className="text-lg font-semibold text-slate-900 dark:text-white">Delete Backup</h3>
            <p className="mt-2 text-sm text-slate-500">Delete <strong>{confirmDelete}</strong>?</p>
            <div className="mt-4 flex justify-end gap-2">
              <button onClick={() => setConfirmDelete(null)} className="btn btn-secondary">Cancel</button>
              <button onClick={() => deleteMutation.mutate(confirmDelete)} className="btn btn-danger" disabled={deleteMutation.isPending}>Delete</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
