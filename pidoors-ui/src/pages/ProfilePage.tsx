import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { User, Loader2, Save, Lock } from 'lucide-react';
import { getProfile, updateProfile, changePassword } from '../api/profile';
import toast from 'react-hot-toast';

export function ProfilePage() {
  const queryClient = useQueryClient();
  const { data: profile, isLoading } = useQuery({ queryKey: ['profile'], queryFn: getProfile });
  const [form, setForm] = useState<Record<string, string>>({});
  const [pwForm, setPwForm] = useState({ current_password: '', new_password: '', confirm_password: '' });

  useEffect(() => {
    if (profile) {
      setForm({
        user_email: profile.user_email || '',
        first_name: profile.first_name || '',
        last_name: profile.last_name || '',
        phone: profile.phone || '',
        department: profile.department || '',
        company: profile.company || '',
        job_title: profile.job_title || '',
      });
    }
  }, [profile]);

  const updateMutation = useMutation({
    mutationFn: () => updateProfile(form),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['profile'] }); toast.success('Profile updated'); },
    onError: (err: Error) => toast.error(err.message),
  });

  const passwordMutation = useMutation({
    mutationFn: () => changePassword(pwForm.current_password, pwForm.new_password),
    onSuccess: () => { setPwForm({ current_password: '', new_password: '', confirm_password: '' }); toast.success('Password changed'); },
    onError: (err: Error) => toast.error(err.message),
  });

  const handlePasswordSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (pwForm.new_password !== pwForm.confirm_password) {
      toast.error('Passwords do not match');
      return;
    }
    passwordMutation.mutate();
  };

  if (isLoading) {
    return <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-primary-600" /></div>;
  }

  return (
    <div className="space-y-6 max-w-2xl">
      <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Profile</h1>

      {/* Profile info */}
      <div className="card p-6">
        <h2 className="text-lg font-semibold text-slate-900 dark:text-white mb-4">Account Information</h2>
        <div className="space-y-4">
          <div>
            <label className="label">Username</label>
            <input className="input bg-slate-50 dark:bg-slate-600" value={profile?.user_name || ''} disabled />
          </div>
          <div>
            <label className="label">Email</label>
            <input type="email" className="input" value={form.user_email || ''} onChange={(e) => setForm({ ...form, user_email: e.target.value })} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">First Name</label>
              <input className="input" value={form.first_name || ''} onChange={(e) => setForm({ ...form, first_name: e.target.value })} />
            </div>
            <div>
              <label className="label">Last Name</label>
              <input className="input" value={form.last_name || ''} onChange={(e) => setForm({ ...form, last_name: e.target.value })} />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Department</label>
              <input className="input" value={form.department || ''} onChange={(e) => setForm({ ...form, department: e.target.value })} />
            </div>
            <div>
              <label className="label">Job Title</label>
              <input className="input" value={form.job_title || ''} onChange={(e) => setForm({ ...form, job_title: e.target.value })} />
            </div>
          </div>
          <button onClick={() => updateMutation.mutate()} className="btn btn-primary" disabled={updateMutation.isPending}>
            {updateMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
            Save Profile
          </button>
        </div>
      </div>

      {/* Password change */}
      <div className="card p-6">
        <h2 className="text-lg font-semibold text-slate-900 dark:text-white mb-4">Change Password</h2>
        <form onSubmit={handlePasswordSubmit} className="space-y-4 max-w-sm">
          <div>
            <label className="label">Current Password</label>
            <input type="password" className="input" value={pwForm.current_password} onChange={(e) => setPwForm({ ...pwForm, current_password: e.target.value })} required />
          </div>
          <div>
            <label className="label">New Password</label>
            <input type="password" className="input" value={pwForm.new_password} onChange={(e) => setPwForm({ ...pwForm, new_password: e.target.value })} required />
          </div>
          <div>
            <label className="label">Confirm New Password</label>
            <input type="password" className="input" value={pwForm.confirm_password} onChange={(e) => setPwForm({ ...pwForm, confirm_password: e.target.value })} required />
          </div>
          <button type="submit" className="btn btn-primary" disabled={passwordMutation.isPending}>
            {passwordMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Lock className="h-4 w-4" />}
            Change Password
          </button>
        </form>
      </div>
    </div>
  );
}
