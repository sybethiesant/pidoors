import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Users2, Plus, Pencil, Trash2, Loader2, X } from 'lucide-react';
import { getGroups, createGroup, updateGroup, deleteGroup } from '../api/groups';
import { getDoors } from '../api/doors';
import toast from 'react-hot-toast';
import type { AccessGroup, Door } from '../types';

function GroupFormModal({
  group,
  doors,
  onClose,
  onSave,
  saving,
}: {
  group: Partial<AccessGroup> | null;
  doors: Door[];
  onClose: () => void;
  onSave: (data: Partial<AccessGroup>) => void;
  saving: boolean;
}) {
  const isEdit = group && group.id;
  const existingDoors = (() => {
    if (!group?.doors) return [];
    try { return JSON.parse(group.doors as string) || []; } catch { return []; }
  })();

  const [form, setForm] = useState({
    name: group?.name || '',
    description: group?.description || '',
    selectedDoors: existingDoors as string[],
  });

  const toggleDoor = (name: string) => {
    setForm((prev) => ({
      ...prev,
      selectedDoors: prev.selectedDoors.includes(name)
        ? prev.selectedDoors.filter((d) => d !== name)
        : [...prev.selectedDoors, name],
    }));
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="card w-full max-w-md max-h-[90vh] overflow-y-auto p-6">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
            {isEdit ? 'Edit Group' : 'Add Group'}
          </h2>
          <button onClick={onClose} className="btn-ghost rounded-lg p-1"><X className="h-5 w-5" /></button>
        </div>

        <form onSubmit={(e) => {
          e.preventDefault();
          onSave({ name: form.name, description: form.description, doors: JSON.stringify(form.selectedDoors) });
        }} className="space-y-4">
          <div>
            <label className="label">Name *</label>
            <input className="input" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
          </div>
          <div>
            <label className="label">Description</label>
            <input className="input" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          </div>
          <div>
            <label className="label">Doors</label>
            <div className="max-h-48 overflow-y-auto space-y-1 rounded-lg border border-slate-200 p-2 dark:border-slate-700">
              {doors.length === 0 ? (
                <p className="text-sm text-slate-500 p-2">No doors available</p>
              ) : (
                doors.map((door) => (
                  <label key={door.name} className="flex items-center gap-2 rounded p-1.5 hover:bg-slate-50 dark:hover:bg-slate-700/50 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={form.selectedDoors.includes(door.name)}
                      onChange={() => toggleDoor(door.name)}
                      className="h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
                    />
                    <span className="text-sm text-slate-700 dark:text-slate-300">{door.name}</span>
                    {door.location && <span className="text-xs text-slate-400">({door.location})</span>}
                  </label>
                ))
              )}
            </div>
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="btn btn-secondary">Cancel</button>
            <button type="submit" className="btn btn-primary" disabled={saving}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              {isEdit ? 'Save Changes' : 'Add Group'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export function GroupsPage() {
  const queryClient = useQueryClient();
  const [showForm, setShowForm] = useState(false);
  const [editGroup, setEditGroup] = useState<AccessGroup | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<AccessGroup | null>(null);

  const { data: groups = [], isLoading } = useQuery({ queryKey: ['groups'], queryFn: getGroups });
  const { data: doors = [] } = useQuery({ queryKey: ['doors'], queryFn: getDoors });

  const addMutation = useMutation({
    mutationFn: (data: Partial<AccessGroup>) => createGroup(data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['groups'] }); setShowForm(false); toast.success('Group created'); },
    onError: (err: Error) => toast.error(err.message),
  });

  const editMutation = useMutation({
    mutationFn: ({ id, data }: { id: number; data: Partial<AccessGroup> }) => updateGroup(id, data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['groups'] }); setEditGroup(null); toast.success('Group updated'); },
    onError: (err: Error) => toast.error(err.message),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteGroup(id),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['groups'] }); setConfirmDelete(null); toast.success('Group deleted'); },
    onError: (err: Error) => toast.error(err.message),
  });

  if (isLoading) {
    return <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-primary-600" /></div>;
  }

  const parseDoors = (doors: string | null): string[] => {
    if (!doors) return [];
    try { return JSON.parse(doors) || []; } catch { return []; }
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Access Groups</h1>
        <button onClick={() => setShowForm(true)} className="btn btn-primary">
          <Plus className="h-4 w-4" />
          Add Group
        </button>
      </div>

      {groups.length === 0 ? (
        <div className="card flex flex-col items-center justify-center py-16">
          <Users2 className="h-12 w-12 text-slate-300 dark:text-slate-600" />
          <p className="mt-4 text-slate-500">No access groups configured yet.</p>
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {groups.map((group) => {
            const doorList = parseDoors(group.doors);
            return (
              <div key={group.id} className="card p-5">
                <div className="flex items-start justify-between">
                  <div>
                    <h3 className="font-semibold text-slate-900 dark:text-white">{group.name}</h3>
                    {group.description && <p className="text-sm text-slate-500 mt-1">{group.description}</p>}
                  </div>
                  <div className="flex gap-1">
                    <button onClick={() => setEditGroup(group)} className="btn-ghost rounded p-1"><Pencil className="h-4 w-4" /></button>
                    <button onClick={() => setConfirmDelete(group)} className="rounded p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20"><Trash2 className="h-4 w-4" /></button>
                  </div>
                </div>
                <div className="mt-2 text-xs text-slate-500">
                  {group.member_count} {group.member_count === 1 ? 'card' : 'cards'} assigned
                </div>
                <div className="mt-2 flex flex-wrap gap-1">
                  {doorList.length === 0 ? (
                    <span className="text-sm text-slate-400">No doors assigned</span>
                  ) : (
                    doorList.map((d) => (
                      <span key={d} className="badge badge-secondary">{d}</span>
                    ))
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {(showForm || editGroup) && (
        <GroupFormModal
          group={editGroup}
          doors={doors}
          onClose={() => { setShowForm(false); setEditGroup(null); }}
          onSave={(data) => {
            if (editGroup) editMutation.mutate({ id: editGroup.id, data });
            else addMutation.mutate(data);
          }}
          saving={addMutation.isPending || editMutation.isPending}
        />
      )}

      {confirmDelete && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="card w-full max-w-sm p-6">
            <h3 className="text-lg font-semibold text-slate-900 dark:text-white">Delete Group</h3>
            <p className="mt-2 text-sm text-slate-500">Delete group <strong>{confirmDelete.name}</strong>?</p>
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
