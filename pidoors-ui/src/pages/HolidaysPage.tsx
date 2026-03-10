import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { CalendarDays, Plus, Pencil, Trash2, Loader2, X, RefreshCw } from 'lucide-react';
import { getHolidays, createHoliday, updateHoliday, deleteHoliday } from '../api/holidays';
import toast from 'react-hot-toast';
import type { Holiday } from '../types';

function HolidayFormModal({
  holiday,
  onClose,
  onSave,
  saving,
}: {
  holiday: Partial<Holiday> | null;
  onClose: () => void;
  onSave: (data: Partial<Holiday>) => void;
  saving: boolean;
}) {
  const isEdit = holiday && holiday.id;
  const [form, setForm] = useState({
    name: holiday?.name || '',
    date: holiday?.date || '',
    recurring: holiday?.recurring || 0,
    access_denied: holiday?.access_denied ?? 1,
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="card w-full max-w-sm p-6">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
            {isEdit ? 'Edit Holiday' : 'Add Holiday'}
          </h2>
          <button onClick={onClose} className="btn-ghost rounded-lg p-1"><X className="h-5 w-5" /></button>
        </div>

        <form onSubmit={(e) => { e.preventDefault(); onSave(form); }} className="space-y-4">
          <div>
            <label className="label">Name *</label>
            <input className="input" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
          </div>
          <div>
            <label className="label">Date *</label>
            <input type="date" className="input" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} required />
          </div>
          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              id="recurring"
              checked={!!form.recurring}
              onChange={(e) => setForm({ ...form, recurring: e.target.checked ? 1 : 0 })}
              className="h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
            />
            <label htmlFor="recurring" className="text-sm font-medium text-slate-700 dark:text-slate-300">
              Recurring annually
            </label>
          </div>
          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              id="access_denied"
              checked={!!form.access_denied}
              onChange={(e) => setForm({ ...form, access_denied: e.target.checked ? 1 : 0 })}
              className="h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
            />
            <label htmlFor="access_denied" className="text-sm font-medium text-slate-700 dark:text-slate-300">
              Deny access on this day
            </label>
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="btn btn-secondary">Cancel</button>
            <button type="submit" className="btn btn-primary" disabled={saving}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              {isEdit ? 'Save' : 'Add Holiday'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export function HolidaysPage() {
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [editHoliday, setEditHoliday] = useState<Holiday | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<Holiday | null>(null);

  const { data: holidays = [], isLoading } = useQuery({ queryKey: ['holidays'], queryFn: getHolidays });

  const addMutation = useMutation({
    mutationFn: (data: Partial<Holiday>) => createHoliday(data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['holidays'] }); setShowForm(false); toast.success('Holiday created'); },
    onError: (err: Error) => toast.error(err.message),
  });

  const editMutation = useMutation({
    mutationFn: ({ id, data }: { id: number; data: Partial<Holiday> }) => updateHoliday(id, data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['holidays'] }); setEditHoliday(null); toast.success('Holiday updated'); },
    onError: (err: Error) => toast.error(err.message),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteHoliday(id),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['holidays'] }); setConfirmDelete(null); toast.success('Holiday deleted'); },
    onError: (err: Error) => toast.error(err.message),
  });

  if (isLoading) {
    return <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-primary-600" /></div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Holidays</h1>
        <button onClick={() => setShowForm(true)} className="btn btn-primary">
          <Plus className="h-4 w-4" />
          Add Holiday
        </button>
      </div>

      {holidays.length === 0 ? (
        <div className="card flex flex-col items-center justify-center py-16">
          <CalendarDays className="h-12 w-12 text-slate-300 dark:text-slate-600" />
          <p className="mt-4 text-slate-500">No holidays configured yet.</p>
        </div>
      ) : (
        <div className="card overflow-hidden">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 dark:border-slate-700">
                <th className="px-4 py-3 text-left font-medium text-slate-500">Name</th>
                <th className="px-4 py-3 text-left font-medium text-slate-500">Date</th>
                <th className="px-4 py-3 text-left font-medium text-slate-500">Recurring</th>
                <th className="px-4 py-3 text-right font-medium text-slate-500">Actions</th>
              </tr>
            </thead>
            <tbody>
              {holidays.map((h) => (
                <tr key={h.id} className="border-b border-slate-100 dark:border-slate-700/50">
                  <td className="px-4 py-3 font-medium text-slate-900 dark:text-white">{h.name}</td>
                  <td className="px-4 py-3 text-slate-700 dark:text-slate-300">
                    {new Date(h.date + 'T00:00:00').toLocaleDateString([], { year: 'numeric', month: 'long', day: 'numeric' })}
                  </td>
                  <td className="px-4 py-3">
                    {h.recurring ? (
                      <span className="badge badge-info"><RefreshCw className="mr-1 h-3 w-3" />Yearly</span>
                    ) : (
                      <span className="badge badge-secondary">One-time</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-1">
                      <button onClick={() => setEditHoliday(h)} className="btn-ghost rounded p-1"><Pencil className="h-4 w-4" /></button>
                      <button onClick={() => setConfirmDelete(h)} className="rounded p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20"><Trash2 className="h-4 w-4" /></button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {(showForm || editHoliday) && (
        <HolidayFormModal
          holiday={editHoliday}
          onClose={() => { setShowForm(false); setEditHoliday(null); }}
          onSave={(data) => {
            if (editHoliday) editMutation.mutate({ id: editHoliday.id, data });
            else addMutation.mutate(data);
          }}
          saving={addMutation.isPending || editMutation.isPending}
        />
      )}

      {confirmDelete && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="card w-full max-w-sm p-6">
            <h3 className="text-lg font-semibold text-slate-900 dark:text-white">Delete Holiday</h3>
            <p className="mt-2 text-sm text-slate-500">Delete <strong>{confirmDelete.name}</strong>?</p>
            <div className="mt-4 flex justify-end gap-2">
              <button onClick={() => setConfirmDelete(null)} className="btn btn-secondary">Cancel</button>
              <button onClick={() => deleteMutation.mutate(confirmDelete.id)} className="btn btn-danger" disabled={deleteMutation.isPending}>Delete</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
