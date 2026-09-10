import { useState, type FormEvent } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Calendar, Plus, Pencil, Trash2, Loader2, X, Clock } from 'lucide-react';
import { getSchedules, createSchedule, updateSchedule, deleteSchedule } from '../api/schedules';
import toast from 'react-hot-toast';
import type { Schedule, ScheduleWindow } from '../types';

// Day index 0 = Monday .. 6 = Sunday (matches schedule_windows.day_of_week and Python weekday()).
const DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const DAY_SHORT = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
const WEEKDAYS = [0, 1, 2, 3, 4];
const ALL_DAYS = [0, 1, 2, 3, 4, 5, 6];

type ScheduleForm = {
  name: string;
  description: string;
  is_24_7: number;
  windows: ScheduleWindow[];
};

function hhmm(t: string | null | undefined): string {
  return t ? t.slice(0, 5) : '';
}

/** Human label for a window's day set: "Every day", "Mon–Fri", "Sat, Sun", "Mon, Wed, Fri". */
function daysLabel(days: number[]): string {
  const d = [...days].sort((a, b) => a - b);
  if (d.length === 7) return 'Every day';
  if (d.length === 5 && d.every((x, i) => x === i)) return 'Mon–Fri';
  if (d.length === 2 && d[0] === 5 && d[1] === 6) return 'Sat–Sun';
  // Collapse consecutive runs of 3+ days into ranges.
  const parts: string[] = [];
  let i = 0;
  while (i < d.length) {
    let j = i;
    while (j + 1 < d.length && d[j + 1] === d[j] + 1) j++;
    if (j - i >= 2) parts.push(`${DAY_LABELS[d[i]]}–${DAY_LABELS[d[j]]}`);
    else for (let k = i; k <= j; k++) parts.push(DAY_LABELS[d[k]]);
    i = j + 1;
  }
  return parts.join(', ');
}

/** Windows for an existing schedule. Older servers only send the per-day columns. */
function windowsOf(schedule: Partial<Schedule>): ScheduleWindow[] {
  if (schedule.windows && schedule.windows.length > 0) {
    return schedule.windows.map((w) => ({ days: [...w.days], start: hhmm(w.start), end: hhmm(w.end) }));
  }
  const keys = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as const;
  const groups = new Map<string, ScheduleWindow>();
  keys.forEach((day, i) => {
    const start = hhmm(schedule[`${day}_start`] as string | null);
    const end = hhmm(schedule[`${day}_end`] as string | null);
    if (!start || !end) return;
    const key = `${start}|${end}`;
    const g = groups.get(key) ?? { days: [], start, end };
    g.days.push(i);
    groups.set(key, g);
  });
  return [...groups.values()];
}

function sortedWindows(windows: ScheduleWindow[]): ScheduleWindow[] {
  return [...windows].sort((a, b) => a.start.localeCompare(b.start) || a.end.localeCompare(b.end));
}

function DayToggles({ days, onChange }: { days: number[]; onChange: (days: number[]) => void }) {
  const toggle = (d: number) => onChange(days.includes(d) ? days.filter((x) => x !== d) : [...days, d]);
  return (
    <div className="flex gap-1" role="group" aria-label="Days">
      {DAY_SHORT.map((label, d) => {
        const on = days.includes(d);
        return (
          <button
            key={d}
            type="button"
            onClick={() => toggle(d)}
            title={DAY_LABELS[d]}
            aria-pressed={on}
            className={`h-7 w-7 rounded-full text-xs font-semibold transition-colors ${
              on
                ? 'bg-primary-600 text-white'
                : 'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-400 dark:hover:bg-slate-600'
            }`}
          >
            {label}
          </button>
        );
      })}
    </div>
  );
}

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
  const isEdit = !!(schedule && schedule.id);
  const [form, setForm] = useState<ScheduleForm>({
    name: schedule?.name ?? '',
    description: schedule?.description ?? '',
    is_24_7: schedule?.is_24_7 ?? 0,
    windows: schedule ? windowsOf(schedule) : [{ days: [...WEEKDAYS], start: '08:00', end: '17:00' }],
  });

  const set = <K extends keyof ScheduleForm>(field: K, value: ScheduleForm[K]) => setForm({ ...form, [field]: value });
  const setWindow = (i: number, patch: Partial<ScheduleWindow>) =>
    set('windows', form.windows.map((w, idx) => (idx === i ? { ...w, ...patch } : w)));
  const addWindow = () => set('windows', [...form.windows, { days: [...ALL_DAYS], start: '08:00', end: '17:00' }]);
  const removeWindow = (i: number) => set('windows', form.windows.filter((_, idx) => idx !== i));

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (!form.is_24_7) {
      if (form.windows.length === 0) { toast.error('Add at least one time window, or make the schedule 24/7'); return; }
      for (const [i, w] of form.windows.entries()) {
        if (w.days.length === 0) { toast.error(`Time window ${i + 1} needs at least one day`); return; }
        if (!w.start || !w.end) { toast.error(`Time window ${i + 1} needs a start and end time`); return; }
        if (w.start === w.end) { toast.error(`Time window ${i + 1} starts and ends at the same time`); return; }
      }
    }
    onSave({
      name: form.name,
      description: form.description,
      is_24_7: form.is_24_7,
      windows: form.is_24_7 ? [] : form.windows,
    });
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="card w-full max-w-2xl max-h-[90vh] overflow-y-auto p-6">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
            {isEdit ? 'Edit Schedule' : 'Add Schedule'}
          </h2>
          <button onClick={onClose} className="btn-ghost rounded-lg p-1"><X className="h-5 w-5" /></button>
        </div>

        <form onSubmit={submit} className="space-y-4">
          <div>
            <label className="label">Name *</label>
            <input className="input" value={form.name} onChange={(e) => set('name', e.target.value)} required />
          </div>
          <div>
            <label className="label">Description</label>
            <input className="input" value={form.description} onChange={(e) => set('description', e.target.value)} />
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
            <div className="space-y-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-slate-700 dark:text-slate-300">Time windows</span>
                <button type="button" onClick={addWindow} className="btn btn-secondary btn-sm">
                  <Plus className="h-3.5 w-3.5" />
                  Add window
                </button>
              </div>
              <p className="text-xs text-slate-500">
                Each window applies on the days selected. Add as many as you need — a day can have several
                windows. An end time earlier than the start runs past midnight.
              </p>

              {form.windows.length === 0 && (
                <p className="py-2 text-center text-sm text-slate-500">No time windows. Add one above.</p>
              )}

              {form.windows.map((w, i) => (
                <div
                  key={i}
                  className="flex flex-wrap items-center gap-3 rounded-md bg-slate-50 p-2 dark:bg-slate-800/60"
                >
                  <DayToggles days={w.days} onChange={(days) => setWindow(i, { days })} />
                  <div className="flex items-center gap-2">
                    <input
                      type="time"
                      className="input text-xs"
                      value={w.start}
                      onChange={(e) => setWindow(i, { start: e.target.value })}
                      required
                    />
                    <span className="text-xs text-slate-400">to</span>
                    <input
                      type="time"
                      className="input text-xs"
                      value={w.end}
                      onChange={(e) => setWindow(i, { end: e.target.value })}
                      required
                    />
                  </div>
                  <button
                    type="button"
                    onClick={() => removeWindow(i)}
                    className="ml-auto rounded p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20"
                    title="Remove window"
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
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
          {schedules.map((schedule) => {
            const windows = sortedWindows(windowsOf(schedule));
            return (
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
                  ) : windows.length === 0 ? (
                    <p className="text-xs text-slate-400">No time windows</p>
                  ) : (
                    <div className="space-y-1 text-xs text-slate-500">
                      {windows.map((w, i) => (
                        <div key={i} className="flex justify-between gap-2">
                          <span className="font-medium">{daysLabel(w.days)}</span>
                          <span className="tabular-nums">{w.start} – {w.end}</span>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            );
          })}
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
