import { useState, useEffect } from 'react';
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
  ChevronRight,
  ChevronDown,
  Square,
  ArrowUp,
  ArrowDown,
} from 'lucide-react';
import { getDoors, createDoor, updateDoor, deleteDoor, unlockDoor, holdDoor, pingDoor, getAvailablePins, gateCommand } from '../api/doors';
import { getSchedules } from '../api/schedules';
import toast from 'react-hot-toast';
import type { Door, Schedule, GateConfig, GateIO, StatusLedConfig } from '../types';

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
    is_gate: 0,
    gate_config: null,
    status_led_config: null,
    ...door,
  });

  const [availablePins, setAvailablePins] = useState<number[]>([]);
  const [reservedPins, setReservedPins] = useState<Record<number, string>>({});
  const [gateExpanded, setGateExpanded] = useState<boolean>(!!form.is_gate);
  const [ledExpanded, setLedExpanded] = useState<boolean>(!!form.status_led_config?.enabled);
  const [advExpanded, setAdvExpanded] = useState<boolean>(false);

  // Fetch available pins when editing an existing door
  useEffect(() => {
    if (isEdit && form.name) {
      getAvailablePins(form.name).then((res) => {
        setAvailablePins(res.available);
        setReservedPins(res.reserved);
      }).catch(() => {
        // Fallback: show all GPIO pins
        setAvailablePins([4, 5, 6, 7, 12, 13, 16, 17, 19, 20, 21, 22, 23, 24, 25, 26, 27]);
      });
    } else {
      setAvailablePins([4, 5, 6, 7, 12, 13, 16, 17, 19, 20, 21, 22, 23, 24, 25, 26, 27]);
    }
  }, [isEdit, form.name]);

  const [sensorEnabled, setSensorEnabled] = useState<boolean>(
    form.door_sensor_gpio !== null && form.door_sensor_gpio !== undefined
  );

  const updateGateIO = (section: 'inputs' | 'outputs', name: 'open' | 'stop' | 'close', patch: Partial<GateIO>) => {
    const current = (form.gate_config || {}) as GateConfig;
    const sectionData = { ...(current[section] || {}) };
    const existing = (sectionData[name] || { enabled: false, pin: null }) as GateIO;
    sectionData[name] = { ...existing, ...patch };
    setForm({ ...form, gate_config: { ...current, [section]: sectionData } });
  };

  const updateAdvanced = (patch: Partial<NonNullable<GateConfig['advanced']>>) => {
    const current = (form.gate_config || {}) as GateConfig;
    setForm({ ...form, gate_config: { ...current, advanced: { ...(current.advanced || {}), ...patch } } });
  };

  const updateStatusLed = (patch: Partial<StatusLedConfig>) => {
    const current = (form.status_led_config || { enabled: false, pin: null, active_high: true }) as StatusLedConfig;
    setForm({ ...form, status_led_config: { ...current, ...patch } });
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSave(form);
  };

  // Compute pins currently in use by other features in this form.
  // Pass excludeSelf to exclude the field that's calling this (so its own pin doesn't filter itself out).
  type SelfRef =
    | { kind: 'gate'; section: 'inputs' | 'outputs'; name: 'open' | 'stop' | 'close' }
    | { kind: 'led' }
    | { kind: 'sensor' };

  const getUsedPins = (excludeSelf?: SelfRef): Set<number> => {
    const used = new Set<number>();
    // Gate I/O
    const sections: ('inputs' | 'outputs')[] = ['inputs', 'outputs'];
    const names: ('open' | 'stop' | 'close')[] = ['open', 'stop', 'close'];
    for (const section of sections) {
      for (const name of names) {
        if (excludeSelf?.kind === 'gate' && excludeSelf.section === section && excludeSelf.name === name) continue;
        const entry = form.gate_config?.[section]?.[name] as GateIO | undefined;
        if (entry?.enabled && entry.pin != null) used.add(entry.pin);
      }
    }
    // Status LED
    if (excludeSelf?.kind !== 'led' && form.status_led_config?.enabled && form.status_led_config.pin != null) {
      used.add(form.status_led_config.pin);
    }
    // Door sensor
    if (excludeSelf?.kind !== 'sensor' && form.door_sensor_gpio != null) {
      used.add(form.door_sensor_gpio);
    }
    return used;
  };

  const renderPinSelect = (
    value: number | null | undefined,
    onChange: (v: number | null) => void,
    excludeSelf?: SelfRef
  ) => {
    const used = getUsedPins(excludeSelf);
    // Build a set of all pins to show: available + reserved (from API) + currently selected
    const optionSet = new Set<number>(availablePins);
    Object.keys(reservedPins).forEach((p) => optionSet.add(parseInt(p)));
    if (value != null) optionSet.add(value);
    const sorted = [...optionSet].sort((a, b) => a - b);
    return (
      <select
        className="input"
        value={value ?? ''}
        onChange={(e) => onChange(e.target.value ? parseInt(e.target.value) : null)}
      >
        <option value="">Select pin…</option>
        {sorted.map((p) => {
          const inUseByOther = used.has(p) && p !== value;
          const reservedBy = reservedPins[p];
          const disabled = inUseByOther || (!!reservedBy && p !== value);
          const label = inUseByOther
            ? `GPIO ${p} (in use)`
            : reservedBy
            ? `GPIO ${p} (${reservedBy})`
            : `GPIO ${p}`;
          return (
            <option key={p} value={p} disabled={disabled}>
              {label}
            </option>
          );
        })}
      </select>
    );
  };

  const renderGateIORow = (
    section: 'inputs' | 'outputs',
    name: 'open' | 'stop' | 'close',
    label: string
  ) => {
    const cfg = (form.gate_config?.[section]?.[name] || { enabled: false, pin: null }) as GateIO;
    return (
      <div className="rounded-md border border-slate-200 p-3 dark:border-slate-700">
        <label className="flex items-center justify-between gap-2 text-sm font-medium text-slate-700 dark:text-slate-300">
          <span>{label}</span>
          <input
            type="checkbox"
            checked={!!cfg.enabled}
            onChange={(e) => updateGateIO(section, name, { enabled: e.target.checked })}
            className="rounded border-slate-300"
          />
        </label>
        {cfg.enabled && (
          <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
            <div>
              <label className="label text-xs">Pin</label>
              {renderPinSelect(cfg.pin, (v) => updateGateIO(section, name, { pin: v }), { kind: 'gate', section, name })}
            </div>
            <div>
              <label className="label text-xs">Active</label>
              <select
                className="input"
                value={cfg.active_high === false ? 'low' : 'high'}
                onChange={(e) => updateGateIO(section, name, { active_high: e.target.value === 'high' })}
              >
                <option value="high">High (3.3V)</option>
                <option value="low">Low (GND)</option>
              </select>
            </div>
            {section === 'outputs' && (
              <div>
                <label className="label text-xs">Hold (sec)</label>
                <input
                  type="number"
                  min={1}
                  max={300}
                  className="input"
                  value={cfg.duration_seconds ?? 30}
                  onChange={(e) => updateGateIO(section, name, { duration_seconds: parseInt(e.target.value) || 30 })}
                />
              </div>
            )}
          </div>
        )}
      </div>
    );
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
            {!form.is_gate && (
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
            )}
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
                onChange={(e) => {
                  setSensorEnabled(e.target.checked);
                  if (!e.target.checked) setForm({ ...form, door_sensor_gpio: null });
                }}
                className="rounded border-slate-300"
              />
              Door contact sensor
            </label>
            {sensorEnabled && (
              <div className="mt-2 space-y-2">
                <div>
                  <label className="label">Sensor GPIO Pin</label>
                  {renderPinSelect(
                    form.door_sensor_gpio,
                    (v) => setForm({ ...form, door_sensor_gpio: v }),
                    { kind: 'sensor' }
                  )}
                </div>
                <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                  <input
                    type="checkbox"
                    checked={!!form.door_sensor_invert}
                    onChange={(e) => setForm({ ...form, door_sensor_invert: e.target.checked ? 1 : 0 })}
                    className="rounded border-slate-300"
                  />
                  Invert sensor logic (for normally-open sensors)
                </label>
              </div>
            )}
          </div>

          {/* ── GATE MODE ── */}
          <div className="border-t border-slate-200 pt-4 dark:border-slate-700">
            <label className="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-300">
              <input
                type="checkbox"
                checked={!!form.is_gate}
                onChange={(e) => {
                  const en = e.target.checked;
                  setForm({ ...form, is_gate: en ? 1 : 0, gate_config: en ? (form.gate_config || {}) : null });
                  setGateExpanded(en);
                }}
                className="rounded border-slate-300"
              />
              Gate mode (replaces lock with open/close/stop control)
            </label>
            {!!form.is_gate && (
              <div className="mt-3 space-y-3">
                <div className="flex items-center justify-between">
                  <button
                    type="button"
                    onClick={() => setGateExpanded(!gateExpanded)}
                    className="flex items-center gap-1 text-sm text-slate-600 hover:text-slate-900 dark:text-slate-400"
                  >
                    {gateExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                    Gate I/O Configuration
                  </button>
                </div>
                {gateExpanded && (
                  <div className="space-y-4 rounded-md bg-slate-50 p-3 dark:bg-slate-800">
                    <div>
                      <h4 className="mb-2 text-xs font-semibold uppercase text-slate-500">Inputs (physical buttons)</h4>
                      <div className="space-y-2">
                        {renderGateIORow('inputs', 'open', 'Open button')}
                        {renderGateIORow('inputs', 'stop', 'Stop button')}
                        {renderGateIORow('inputs', 'close', 'Close button')}
                      </div>
                    </div>
                    <div>
                      <h4 className="mb-2 text-xs font-semibold uppercase text-slate-500">Outputs (relays to gate motor)</h4>
                      <div className="space-y-2">
                        {renderGateIORow('outputs', 'open', 'Open relay')}
                        {renderGateIORow('outputs', 'stop', 'Stop relay')}
                        {renderGateIORow('outputs', 'close', 'Close relay')}
                      </div>
                    </div>
                    {/* Advanced options */}
                    <div>
                      <button
                        type="button"
                        onClick={() => setAdvExpanded(!advExpanded)}
                        className="flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700 dark:text-slate-400"
                      >
                        {advExpanded ? <ChevronDown className="h-3 w-3" /> : <ChevronRight className="h-3 w-3" />}
                        Advanced options
                      </button>
                      {advExpanded && (
                        <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
                          <div>
                            <label className="label text-xs">Debounce (ms)</label>
                            <input
                              type="number"
                              className="input"
                              min={10}
                              max={500}
                              value={form.gate_config?.advanced?.debounce_ms ?? 50}
                              onChange={(e) => updateAdvanced({ debounce_ms: parseInt(e.target.value) || 50 })}
                            />
                          </div>
                          <div>
                            <label className="label text-xs">Triple-tap window (ms)</label>
                            <input
                              type="number"
                              className="input"
                              min={500}
                              max={5000}
                              value={form.gate_config?.advanced?.triple_tap_window_ms ?? 2000}
                              onChange={(e) => updateAdvanced({ triple_tap_window_ms: parseInt(e.target.value) || 2000 })}
                            />
                          </div>
                          <div>
                            <label className="label text-xs">Input pull-resistor</label>
                            <select
                              className="input"
                              value={form.gate_config?.advanced?.pull ?? 'up'}
                              onChange={(e) => updateAdvanced({ pull: e.target.value as 'up' | 'down' | 'none' })}
                            >
                              <option value="up">Pull-up (button to GND)</option>
                              <option value="down">Pull-down (button to 3.3V)</option>
                              <option value="none">None</option>
                            </select>
                          </div>
                        </div>
                      )}
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>

          {/* ── STATUS LED ── */}
          <div className="border-t border-slate-200 pt-4 dark:border-slate-700">
            <label className="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-300">
              <input
                type="checkbox"
                checked={!!form.status_led_config?.enabled}
                onChange={(e) => updateStatusLed({ enabled: e.target.checked })}
                className="rounded border-slate-300"
              />
              Status LED (lights on access events)
            </label>
            {!!form.status_led_config?.enabled && (
              <div className="mt-2 grid grid-cols-2 gap-2">
                <div>
                  <label className="label text-xs">LED GPIO pin</label>
                  {renderPinSelect(
                    form.status_led_config?.pin,
                    (v) => updateStatusLed({ pin: v }),
                    { kind: 'led' }
                  )}
                </div>
                <div>
                  <label className="label text-xs">Active</label>
                  <select
                    className="input"
                    value={form.status_led_config?.active_high === false ? 'low' : 'high'}
                    onChange={(e) => updateStatusLed({ active_high: e.target.value === 'high' })}
                  >
                    <option value="high">High (3.3V)</option>
                    <option value="low">Low (GND)</option>
                  </select>
                </div>
              </div>
            )}
            {Object.keys(reservedPins).length > 0 && (
              <p className="mt-2 text-xs text-slate-400">
                Reserved pins: {Object.entries(reservedPins).map(([p, w]) => `GPIO ${p} (${w})`).join(', ')}
              </p>
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

  const handleGateCmd = async (name: string, action: 'open' | 'close' | 'stop' | 'hold' | 'release') => {
    try {
      await gateCommand(name, action);
      toast.success(`${name}: ${action}`);
      queryClient.invalidateQueries({ queryKey: ['doors'] });
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Gate command failed');
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

                  {isOnline && door.is_gate ? (
                    <>
                      <span className={`badge ${
                        door.gate_state === 'open' ? 'badge-warning' :
                        door.gate_state === 'closed' ? 'badge-success' :
                        door.gate_state === 'opening' || door.gate_state === 'closing' ? 'badge-info' :
                        door.gate_state === 'stopped' ? 'badge-danger' : 'badge-secondary'
                      }`}>
                        Gate: {door.gate_state}
                      </span>
                      {door.gate_held ? <span className="badge badge-warning">Held</span> : null}
                    </>
                  ) : isOnline && (
                    door.held_open ? (
                      <span className="badge badge-warning">Held Open</span>
                    ) : door.unlock_requested ? (
                      <span className="badge badge-info">Unlocking</span>
                    ) : door.locked ? (
                      <span className="badge badge-success">Locked</span>
                    ) : (
                      <span className="badge badge-warning">Unlocked</span>
                    )
                  )}

                  {isOnline && door.door_sensor_gpio !== null && (
                    door.door_open === 1 ? (
                      <span className="badge badge-warning">Sensor: Open</span>
                    ) : door.door_open === 0 ? (
                      <span className="badge badge-success">Sensor: Closed</span>
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

                {isOnline && door.is_gate ? (
                  <div className="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3 dark:border-slate-700">
                    <button
                      onClick={() => handleGateCmd(door.name, 'open')}
                      className="btn btn-sm btn-success"
                      disabled={!!door.gate_held}
                      title={door.gate_held ? 'Release hold first' : 'Open gate'}
                    >
                      <ArrowUp className="h-3 w-3" />
                      Open
                    </button>
                    <button
                      onClick={() => handleGateCmd(door.name, 'stop')}
                      className="btn btn-sm btn-warning"
                    >
                      <Square className="h-3 w-3" />
                      Stop
                    </button>
                    <button
                      onClick={() => handleGateCmd(door.name, 'close')}
                      className="btn btn-sm btn-secondary"
                      disabled={!!door.gate_held}
                      title={door.gate_held ? 'Release hold first' : 'Close gate'}
                    >
                      <ArrowDown className="h-3 w-3" />
                      Close
                    </button>
                    {door.gate_held ? (
                      <button
                        onClick={() => handleGateCmd(door.name, 'release')}
                        className="btn btn-sm btn-danger"
                      >
                        <LockOpen className="h-3 w-3" />
                        Release
                      </button>
                    ) : (
                      <button
                        onClick={() => handleGateCmd(door.name, 'hold')}
                        className="btn btn-sm btn-ghost"
                      >
                        <Lock className="h-3 w-3" />
                        Hold
                      </button>
                    )}
                    {door.listen_port && (
                      <button
                        onClick={() => handlePing(door.name)}
                        className="btn btn-sm btn-ghost"
                        title="Ping controller"
                      >
                        <Radio className="h-3 w-3" />
                      </button>
                    )}
                  </div>
                ) : isOnline && !door.unlock_requested && (
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
