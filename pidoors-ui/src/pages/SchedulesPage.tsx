import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Calendar, Plus, Pencil, Trash2, Loader2, X, Clock } from 'lucide-react';
import { getSchedules, createSchedule, updateSchedule, deleteSchedule } from '../api/schedules';
import toast from 'react-hot-toast';
import type { Schedule } from '../types';

const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as const;
const DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

function ScheduleFormModal({
  schedule,
  onClose,
  onSave,
  saving,
}: {
  schedule: Partial<Schedule> | null;
  onClose: () => void;
  onSave: (data: Partial<Schedule>) => void;
  saving: boolean;
}) {
  const isEdit = schedule && schedule.id;
  const [form, setForm] = useState<Record<string, unknown>>({
    name: '',
    description: '',
    is_24_7: 0,
    ...Object.fromEntries(DAYS.flatMap((d) => [[`${d}_start`, '08:00'], [`${d}_end`, '17:00']])),
    ...schedule,
  });

  const set = (field: string, value: unknown) => setForm({ ...form, [field]: value });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="card w-full max-w-xl max-h-[90vh] overflow-y-auto p-6">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
            {isEdit ? 'Edit Schedule' : 'Add Schedule'}
          </h2>
          <button onClick={onClose} className="btn-ghost rounded-lg p-1"><X className="h-5 w-5" /></button>
        </div>

        <form onSubmit={(e) => { e.preventDefault(); onSave(form as Partial<Schedule>); }} className="space-y-4">
          <div>
            <label className="label">Name *</label>
            <input className="input" value={(form.name as string) || ''} onChange={(e) => set('name', e.target.value)} required />
          </div>
          <div>
            <label className="label">Description</label>
            <input className="input" value={(form.description as string) || ''} onChange={(e) => set('description', e.target.value)} />
          </div>

          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              id="is_24_7"
              checked={!!form.is_24_7}
              onChange={(e) => set('is_24_7', e.target.checked ? 1 : 0)}
              className="h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
            />
            <label htmlFor="is_24_7" className="text-sm font-medium text-slate-700 dark:text-slate-300">
              24/7 Access (always open)
            </label>
          </div>

          {!form.is_24_7 && (
            <div className="space-y-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
              {DAYS.map((day, i) => (
                <div key={day} className="grid grid-cols-[60px_1fr_1fr] gap-2 items-center">
                  <span className="text-sm font-medium text-slate-600 dark:text-slate-400">{DAY_LABELS[i]}</span>
                  <input
                    type="time"
                    className="input text-xs"
                    value={(form[`${day}_start`] as string) || ''}
                    onChange={(e) => set(`${day}_start`, e.target.value)}
                  />
                  <input
                    type="time"
                    className="input text-xs"
                    value={(form[`${day}_end`] as string) || ''}
                    onChange={(e) => set(`${day}_end`, e.target.value)}
                  />
                </div>
              ))}
            </div>
          )}

          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="btn btn-secondary">Cancel</button>
            <button type="submit" className="btn btn-primary" disabled={saving}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              {isEdit ? 'Save Changes' : 'Add Schedule'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export function SchedulesPage() {
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [editSchedule, setEditSchedule] = useState<Schedule | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<Schedule | null>(null);

  const { data: schedules = [], isLoading } = useQuery({ queryKey: ['schedules'], queryFn: getSchedules });

  const addMutation = useMutation({
    mutationFn: (data: Partial<Schedule>) => createSchedule(data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['schedules'] }); setShowForm(false); toast.success('Schedule created'); },
    onError: (err: Error) => toast.error(err.message),
  });

  const editMutation = useMutation({
    mutationFn: ({ id, data }: { id: number; data: Partial<Schedule> }) => updateSchedule(id, data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['schedules'] }); setEditSchedule(null); toast.success('Schedule updated'); },
    onError: (err: Error) => toast.error(err.message),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteSchedule(id),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['schedules'] }); setConfirmDelete(null); toast.success('Schedule deleted'); },
    onError: (err: Error) => toast.error(err.message),
  });

  if (isLoading) {
    return <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-primary-600" /></div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Schedules</h1>
        <button onClick={() => setShowForm(true)} className="btn btn-primary">
          <Plus className="h-4 w-4" />
          Add Schedule
        </button>
      </div>

      {schedules.length === 0 ? (
        <div className="card flex flex-col items-center justify-center py-16">
          <Calendar className="h-12 w-12 text-slate-300 dark:text-slate-600" />
          <p className="mt-4 text-slate-500">No schedules configured yet.</p>
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {schedules.map((schedule) => (
            <div key={schedule.id} className="card p-5">
              <div className="flex items-start justify-between">
                <div>
                  <h3 className="font-semibold text-slate-900 dark:text-white">{schedule.name}</h3>
                  {schedule.description && <p className="text-sm text-slate-500 mt-1">{schedule.description}</p>}
                </div>
                <div className="flex gap-1">
                  <button onClick={() => setEditSchedule(schedule)} className="btn-ghost rounded p-1"><Pencil className="h-4 w-4" /></button>
                  <button onClick={() => setConfirmDelete(schedule)} className="rounded p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20"><Trash2 className="h-4 w-4" /></button>
                </div>
              </div>
              <div className="mt-3">
                {schedule.is_24_7 ? (
                  <span className="badge badge-success"><Clock className="mr-1 h-3 w-3" />24/7 Access</span>
                ) : (
                  <div className="space-y-1 text-xs text-slate-500">
                    {DAYS.map((day, i) => {
                      const start = schedule[`${day}_start` as keyof Schedule] as string;
                      const end = schedule[`${day}_end` as keyof Schedule] as string;
                      if (!start && !end) return null;
                      return (
                        <div key={day} className="flex justify-between">
                          <span className="font-medium">{DAY_LABELS[i]}</span>
                          <span>{start || '--'} - {end || '--'}</span>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      {(showForm || editSchedule) && (
        <ScheduleFormModal
          schedule={editSchedule}
          onClose={() => { setShowForm(false); setEditSchedule(null); }}
          onSave={(data) => {
            if (editSchedule) editMutation.mutate({ id: editSchedule.id, data });
            else addMutation.mutate(data);
          }}
          saving={addMutation.isPending || editMutation.isPending}
        />
      )}

      {confirmDelete && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="card w-full max-w-sm p-6">
            <h3 className="text-lg font-semibold text-slate-900 dark:text-white">Delete Schedule</h3>
            <p className="mt-2 text-sm text-slate-500">Delete schedule <strong>{confirmDelete.name}</strong>?</p>
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
