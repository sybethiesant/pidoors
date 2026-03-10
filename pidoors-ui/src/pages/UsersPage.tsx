import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Users, Plus, Pencil, Trash2, Loader2, X, Shield, ShieldOff } from 'lucide-react';
import { getUsers, createUser, updateUser, deleteUser } from '../api/users';
import { useAuth } from '../hooks/useAuth';
import toast from 'react-hot-toast';
import type { User } from '../types';

function UserFormModal({
  user,
  onClose,
  onSave,
  saving,
}: {
  user: Partial<User> | null;
  onClose: () => void;
  onSave: (data: Partial<User> & { password?: string }) => void;
  saving: boolean;
}) {
  const isEdit = user && user.id;
  const [form, setForm] = useState<Record<string, string | number | boolean | null>>({
    user_name: user?.user_name || '',
    user_email: user?.user_email || '',
    password: '',
    admin: user?.admin ? 1 : 0,
    active: user?.active !== undefined ? (user.active ? 1 : 0) : 1,
    first_name: user?.first_name || '',
    last_name: user?.last_name || '',
    phone: user?.phone || '',
    department: user?.department || '',
    company: user?.company || '',
    job_title: user?.job_title || '',
  });

  const set = (key: string, value: unknown) => setForm({ ...form, [key]: value as string });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="card w-full max-w-lg max-h-[90vh] overflow-y-auto p-6">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-slate-900 dark:text-white">{isEdit ? 'Edit User' : 'Add User'}</h2>
          <button onClick={onClose} className="btn-ghost rounded-lg p-1"><X className="h-5 w-5" /></button>
        </div>

        <form onSubmit={(e) => { e.preventDefault(); onSave(form as Partial<User> & { password?: string }); }} className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Username *</label>
              <input className="input" value={(form.user_name as string) || ''} onChange={(e) => set('user_name', e.target.value)} required />
            </div>
            <div>
              <label className="label">Email *</label>
              <input type="email" className="input" value={(form.user_email as string) || ''} onChange={(e) => set('user_email', e.target.value)} required />
            </div>
          </div>
          <div>
            <label className="label">{isEdit ? 'New Password (leave blank to keep)' : 'Password *'}</label>
            <input type="password" className="input" value={(form.password as string) || ''} onChange={(e) => set('password', e.target.value)} required={!isEdit} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">First Name</label>
              <input className="input" value={(form.first_name as string) || ''} onChange={(e) => set('first_name', e.target.value)} />
            </div>
            <div>
              <label className="label">Last Name</label>
              <input className="input" value={(form.last_name as string) || ''} onChange={(e) => set('last_name', e.target.value)} />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Department</label>
              <input className="input" value={(form.department as string) || ''} onChange={(e) => set('department', e.target.value)} />
            </div>
            <div>
              <label className="label">Job Title</label>
              <input className="input" value={(form.job_title as string) || ''} onChange={(e) => set('job_title', e.target.value)} />
            </div>
          </div>
          <div className="flex gap-6">
            <div className="flex items-center gap-2">
              <input type="checkbox" id="admin" checked={!!form.admin} onChange={(e) => set('admin', e.target.checked ? 1 : 0)} className="h-4 w-4 rounded border-slate-300 text-primary-600" />
              <label htmlFor="admin" className="text-sm text-slate-700 dark:text-slate-300">Admin</label>
            </div>
            <div className="flex items-center gap-2">
              <input type="checkbox" id="active" checked={!!form.active} onChange={(e) => set('active', e.target.checked ? 1 : 0)} className="h-4 w-4 rounded border-slate-300 text-primary-600" />
              <label htmlFor="active" className="text-sm text-slate-700 dark:text-slate-300">Active</label>
            </div>
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="btn btn-secondary">Cancel</button>
            <button type="submit" className="btn btn-primary" disabled={saving}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              {isEdit ? 'Save Changes' : 'Add User'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export function UsersPage() {
  const queryClient = useQueryClient();
  const { user: currentUser } = useAuth();
  const [showForm, setShowForm] = useState(false);
  const [editUser, setEditUser] = useState<User | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<User | null>(null);

  const { data: users = [], isLoading } = useQuery({ queryKey: ['users'], queryFn: getUsers });

  const addMutation = useMutation({
    mutationFn: (data: Partial<User> & { password: string }) => createUser(data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['users'] }); setShowForm(false); toast.success('User created'); },
    onError: (err: Error) => toast.error(err.message),
  });

  const editMutation = useMutation({
    mutationFn: ({ id, data }: { id: number; data: Partial<User> & { password?: string } }) => updateUser(id, data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['users'] }); setEditUser(null); toast.success('User updated'); },
    onError: (err: Error) => toast.error(err.message),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteUser(id),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['users'] }); setConfirmDelete(null); toast.success('User deleted'); },
    onError: (err: Error) => toast.error(err.message),
  });

  if (isLoading) {
    return <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-primary-600" /></div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Panel Users</h1>
        <button onClick={() => setShowForm(true)} className="btn btn-primary">
          <Plus className="h-4 w-4" />
          Add User
        </button>
      </div>

      <div className="card overflow-hidden">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 dark:border-slate-700">
              <th className="px-4 py-3 text-left font-medium text-slate-500">Username</th>
              <th className="px-4 py-3 text-left font-medium text-slate-500 hidden md:table-cell">Email</th>
              <th className="px-4 py-3 text-left font-medium text-slate-500 hidden lg:table-cell">Name</th>
              <th className="px-4 py-3 text-left font-medium text-slate-500">Role</th>
              <th className="px-4 py-3 text-left font-medium text-slate-500">Status</th>
              <th className="px-4 py-3 text-right font-medium text-slate-500">Actions</th>
            </tr>
          </thead>
          <tbody>
            {users.map((u) => (
              <tr key={u.id} className="border-b border-slate-100 dark:border-slate-700/50">
                <td className="px-4 py-3 font-medium text-slate-900 dark:text-white">{u.user_name}</td>
                <td className="px-4 py-3 text-slate-500 hidden md:table-cell">{u.user_email}</td>
                <td className="px-4 py-3 text-slate-700 dark:text-slate-300 hidden lg:table-cell">
                  {[u.first_name, u.last_name].filter(Boolean).join(' ') || '-'}
                </td>
                <td className="px-4 py-3">
                  {u.admin ? (
                    <span className="badge badge-warning"><Shield className="mr-1 h-3 w-3" />Admin</span>
                  ) : (
                    <span className="badge badge-secondary">User</span>
                  )}
                </td>
                <td className="px-4 py-3">
                  <span className={`badge ${u.active ? 'badge-success' : 'badge-danger'}`}>
                    {u.active ? 'Active' : 'Inactive'}
                  </span>
                </td>
                <td className="px-4 py-3 text-right">
                  <div className="flex justify-end gap-1">
                    <button onClick={() => setEditUser(u)} className="btn-ghost rounded p-1"><Pencil className="h-4 w-4" /></button>
                    {u.id !== currentUser?.id && (
                      <button onClick={() => setConfirmDelete(u)} className="rounded p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20"><Trash2 className="h-4 w-4" /></button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {(showForm || editUser) && (
        <UserFormModal
          user={editUser}
          onClose={() => { setShowForm(false); setEditUser(null); }}
          onSave={(data) => {
            if (editUser) editMutation.mutate({ id: editUser.id, data });
            else addMutation.mutate(data as Partial<User> & { password: string });
          }}
          saving={addMutation.isPending || editMutation.isPending}
        />
      )}

      {confirmDelete && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="card w-full max-w-sm p-6">
            <h3 className="text-lg font-semibold text-slate-900 dark:text-white">Delete User</h3>
            <p className="mt-2 text-sm text-slate-500">Delete user <strong>{confirmDelete.user_name}</strong>?</p>
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
