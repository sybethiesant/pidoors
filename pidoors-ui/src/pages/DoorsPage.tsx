import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  DoorOpen,
  Plus,
  Pencil,
  Trash2,
  Lock,
  Unlock,
  LockOpen,
  Wifi,
  WifiOff,
  Loader2,
  X,
  Radio,
} from 'lucide-react';
import { getDoors, createDoor, updateDoor, deleteDoor, unlockDoor, holdDoor, pingDoor } from '../api/doors';
import { getSchedules } from '../api/schedules';
import toast from 'react-hot-toast';
import type { Door, Schedule } from '../types';

function DoorFormModal({
  door,
  schedules,
  onClose,
  onSave,
}: {
  door: Partial<Door> | null;
  schedules: Schedule[];
  onClose: () => void;
  onSave: (data: Partial<Door>) => void;
}) {
  const isEdit = door && 'name' in door && door.name;
  const [form, setForm] = useState<Partial<Door>>({
    name: '',
    location: '',
    doornum: 0,
    description: '',
    ip_address: '',
    schedule_id: null,
    unlock_duration: 5,
    reader_type: 'wiegand',
    listen_port: null,
    door_sensor_gpio: null,
    ...door,
  });

  const sensorEnabled = form.door_sensor_gpio !== null && form.door_sensor_gpio !== undefined;
  const SENSOR_GPIO_PINS = [4, 5, 6, 7, 12, 13, 16, 17, 19, 20, 21, 26, 27];

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSave(form);
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="card w-full max-w-lg max-h-[90vh] overflow-y-auto p-6">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
            {isEdit ? 'Edit Door' : 'Add Door'}
          </h2>
          <button onClick={onClose} className="btn-ghost rounded-lg p-1">
            <X className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="label">Name *</label>
            <input
              className="input"
              value={form.name || ''}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              required
              disabled={!!isEdit}
              placeholder="e.g., front-door"
            />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Location</label>
              <input
                className="input"
                value={form.location || ''}
                onChange={(e) => setForm({ ...form, location: e.target.value })}
                placeholder="e.g., Main Entrance"
              />
            </div>
            <div>
              <label className="label">Door Number</label>
              <input
                type="number"
                className="input"
                value={form.doornum || 0}
                onChange={(e) => setForm({ ...form, doornum: parseInt(e.target.value) || 0 })}
              />
            </div>
          </div>
          <div>
            <label className="label">Description</label>
            <input
              className="input"
              value={form.description || ''}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
            />
          </div>
          <div>
            <label className="label">IP Address</label>
            <input
              className="input"
              value={form.ip_address || ''}
              onChange={(e) => setForm({ ...form, ip_address: e.target.value })}
              placeholder="e.g., 192.168.1.100"
            />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Reader Type</label>
              <select
                className="input"
                value={form.reader_type || 'wiegand'}
                onChange={(e) => setForm({ ...form, reader_type: e.target.value })}
              >
                <option value="wiegand">Wiegand</option>
                <option value="osdp">OSDP</option>
                <option value="nfc_pn532">NFC PN532</option>
                <option value="nfc_mfrc522">NFC MFRC522</option>
              </select>
            </div>
            <div>
              <label className="label">Schedule</label>
              <select
                className="input"
                value={form.schedule_id ?? ''}
                onChange={(e) => setForm({ ...form, schedule_id: e.target.value ? parseInt(e.target.value) : null })}
              >
                <option value="">None</option>
                {schedules.map((s) => (
                  <option key={s.id} value={s.id}>{s.name}</option>
                ))}
              </select>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Unlock Duration (sec)</label>
              <input
                type="number"
                className="input"
                value={form.unlock_duration || 5}
                onChange={(e) => setForm({ ...form, unlock_duration: parseInt(e.target.value) || 5 })}
                min={1}
              />
            </div>
            <div>
              <label className="label">Listen Port</label>
              <input
                type="number"
                className="input"
                value={form.listen_port ?? ''}
                onChange={(e) => setForm({ ...form, listen_port: e.target.value ? parseInt(e.target.value) : null })}
                placeholder="8443"
                min={1024}
                max={65535}
              />
            </div>
          </div>

          <div className="border-t border-slate-200 pt-4 dark:border-slate-700">
            <label className="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-300">
              <input
                type="checkbox"
                checked={sensorEnabled}
                onChange={(e) => setForm({ ...form, door_sensor_gpio: e.target.checked ? SENSOR_GPIO_PINS[0] : null })}
                className="rounded border-slate-300"
              />
              Door contact sensor
            </label>
            {sensorEnabled && (
              <div className="mt-2">
                <label className="label">Sensor GPIO Pin</label>
                <select
                  className="input"
                  value={form.door_sensor_gpio ?? ''}
                  onChange={(e) => setForm({ ...form, door_sensor_gpio: parseInt(e.target.value) })}
                >
                  {SENSOR_GPIO_PINS.map((pin) => (
                    <option key={pin} value={pin}>GPIO {pin}</option>
                  ))}
                </select>
              </div>
            )}
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="btn btn-secondary">
              Cancel
            </button>
            <button type="submit" className="btn btn-primary">
              {isEdit ? 'Save Changes' : 'Add Door'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export function DoorsPage() {
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [editDoor, setEditDoor] = useState<Door | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<string | null>(null);

  const { data: doors = [], isLoading } = useQuery({
    queryKey: ['doors'],
    queryFn: getDoors,
    refetchInterval: 5000,
  });

  const { data: schedules = [] } = useQuery({
    queryKey: ['schedules'],
    queryFn: getSchedules,
  });

  const addMutation = useMutation({
    mutationFn: (data: Partial<Door>) => createDoor(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['doors'] });
      setShowForm(false);
      toast.success('Door created');
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const editMutation = useMutation({
    mutationFn: ({ name, data }: { name: string; data: Partial<Door> }) => updateDoor(name, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['doors'] });
      setEditDoor(null);
      toast.success('Door updated');
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const deleteMutation = useMutation({
    mutationFn: (name: string) => deleteDoor(name),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['doors'] });
      setConfirmDelete(null);
      toast.success('Door deleted');
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const handleUnlock = async (name: string) => {
    try {
      await unlockDoor(name);
      toast.success(`${name} Unlocked`);
      queryClient.invalidateQueries({ queryKey: ['doors'] });
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Unlock failed');
    }
  };

  const handleHold = async (name: string, action: 'hold' | 'release') => {
    try {
      await holdDoor(name, action);
      toast.success(action === 'hold' ? `${name} Held Open` : `${name} Released`);
      queryClient.invalidateQueries({ queryKey: ['doors'] });
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Action failed');
    }
  };

  const handlePing = async (name: string) => {
    try {
      const res = await pingDoor(name);
      if (res.ping && res.ping.ok) {
        toast.success(`${name}: reachable (v${res.ping.version})`);
      } else {
        toast.error(`${name}: not reachable`);
      }
      queryClient.invalidateQueries({ queryKey: ['doors'] });
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Ping failed');
    }
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-20">
        <Loader2 className="h-8 w-8 animate-spin text-primary-600" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Doors</h1>
        <button onClick={() => setShowForm(true)} className="btn btn-primary">
          <Plus className="h-4 w-4" />
          Add Door
        </button>
      </div>

      {doors.length === 0 ? (
        <div className="card flex flex-col items-center justify-center py-16">
          <DoorOpen className="h-12 w-12 text-slate-300 dark:text-slate-600" />
          <p className="mt-4 text-slate-500">No doors configured yet.</p>
          <button onClick={() => setShowForm(true)} className="btn btn-primary mt-4">
            <Plus className="h-4 w-4" />
            Add Your First Door
          </button>
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {doors.map((door) => {
            const isOnline = door.status === 'online';
            return (
              <div key={door.name} className="card p-5">
                <div className="flex items-start justify-between">
                  <div>
                    <h3 className="font-semibold text-slate-900 dark:text-white">
                      {door.name}
                    </h3>
                    {door.location && (
                      <p className="text-sm text-slate-500">{door.location}</p>
                    )}
                  </div>
                  <div className="flex gap-1">
                    <button
                      onClick={() => setEditDoor(door)}
                      className="btn-ghost rounded p-1"
                      title="Edit"
                    >
                      <Pencil className="h-4 w-4" />
                    </button>
                    <button
                      onClick={() => setConfirmDelete(door.name)}
                      className="rounded p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20"
                      title="Delete"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                </div>

                <div className="mt-3 flex flex-wrap gap-2">
                  <span className={`badge ${isOnline ? 'badge-success' : door.status === 'offline' ? 'badge-danger' : 'badge-secondary'}`}>
                    {isOnline ? <Wifi className="mr-1 h-3 w-3" /> : <WifiOff className="mr-1 h-3 w-3" />}
                    {door.status.charAt(0).toUpperCase() + door.status.slice(1)}
                  </span>

                  {door.held_open ? (
                    <span className="badge badge-warning">Held Open</span>
                  ) : door.unlock_requested ? (
                    <span className="badge badge-info">Unlocking</span>
                  ) : door.locked ? (
                    <span className="badge badge-success">Locked</span>
                  ) : (
                    <span className="badge badge-warning">Unlocked</span>
                  )}

                  {door.door_sensor_gpio !== null && (
                    door.door_open === 1 ? (
                      <span className="badge badge-warning">Open</span>
                    ) : door.door_open === 0 ? (
                      <span className="badge badge-success">Closed</span>
                    ) : (
                      <span className="badge badge-secondary">Sensor N/A</span>
                    )
                  )}
                </div>

                {door.ip_address && (
                  <p className="mt-2 text-xs text-slate-400">
                    {door.ip_address}{door.listen_port ? `:${door.listen_port}` : ''}
                  </p>
                )}

                {isOnline && !door.unlock_requested && (
                  <div className="mt-3 flex gap-2 border-t border-slate-100 pt-3 dark:border-slate-700">
                    {door.held_open ? (
                      <button
                        onClick={() => handleHold(door.name, 'release')}
                        className="btn btn-sm btn-danger"
                      >
                        <LockOpen className="h-3 w-3" />
                        Unhold
                      </button>
                    ) : (
                      <>
                        <button
                          onClick={() => handleUnlock(door.name)}
                          className="btn btn-sm btn-warning"
                        >
                          <Unlock className="h-3 w-3" />
                          Unlock
                        </button>
                        <button
                          onClick={() => handleHold(door.name, 'hold')}
                          className="btn btn-sm btn-secondary"
                        >
                          <Lock className="h-3 w-3" />
                          Hold
                        </button>
                      </>
                    )}
                    {door.listen_port && (
                      <button
                        onClick={() => handlePing(door.name)}
                        className="btn btn-sm btn-ghost"
                        title="Ping controller"
                      >
                        <Radio className="h-3 w-3" />
                        Ping
                      </button>
                    )}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      {/* Add/Edit Modal */}
      {(showForm || editDoor) && (
        <DoorFormModal
          door={editDoor}
          schedules={schedules}
          onClose={() => { setShowForm(false); setEditDoor(null); }}
          onSave={(data) => {
            if (editDoor) {
              editMutation.mutate({ name: editDoor.name, data });
            } else {
              addMutation.mutate(data);
            }
          }}
        />
      )}

      {/* Delete confirmation */}
      {confirmDelete && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="card w-full max-w-sm p-6">
            <h3 className="text-lg font-semibold text-slate-900 dark:text-white">
              Delete Door
            </h3>
            <p className="mt-2 text-sm text-slate-500">
              Are you sure you want to delete <strong>{confirmDelete}</strong>? This cannot be undone.
            </p>
            <div className="mt-4 flex justify-end gap-2">
              <button onClick={() => setConfirmDelete(null)} className="btn btn-secondary">
                Cancel
              </button>
              <button
                onClick={() => deleteMutation.mutate(confirmDelete)}
                className="btn btn-danger"
                disabled={deleteMutation.isPending}
              >
                {deleteMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                Delete
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
