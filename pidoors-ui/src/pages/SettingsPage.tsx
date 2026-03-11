import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Settings as SettingsIcon, Loader2, Send, Save } from 'lucide-react';
import { getSettings, saveSettings, testEmail } from '../api/settings';
import toast from 'react-hot-toast';
import type { Settings } from '../types';

const TABS = ['General', 'Security', 'Controller', 'SMTP', 'Maintenance'] as const;

export function SettingsPage() {
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState<(typeof TABS)[number]>('General');
  const [form, setForm] = useState<Settings>({});
  const [testTo, setTestTo] = useState('');

  const { data: settings, isLoading } = useQuery({
    queryKey: ['settings'],
    queryFn: getSettings,
  });

  useEffect(() => {
    if (settings) setForm(settings);
  }, [settings]);

  const set = (key: string, value: string) => setForm((prev) => ({ ...prev, [key]: value }));

  const saveMutation = useMutation({
    mutationFn: () => saveSettings(form),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['settings'] }); toast.success('Settings saved'); },
    onError: (err: Error) => toast.error(err.message),
  });

  const testEmailMutation = useMutation({
    mutationFn: (to: string) => testEmail(to),
    onSuccess: () => toast.success('Test email sent'),
    onError: (err: Error) => toast.error(err.message),
  });

  if (isLoading) {
    return <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-primary-600" /></div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Settings</h1>
        <button onClick={() => saveMutation.mutate()} className="btn btn-primary" disabled={saveMutation.isPending}>
          {saveMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
          Save Settings
        </button>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-700">
        {TABS.map((tab) => (
          <button
            key={tab}
            onClick={() => setActiveTab(tab)}
            className={`whitespace-nowrap px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
              activeTab === tab
                ? 'border-primary-600 text-primary-600 dark:text-primary-400'
                : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
            }`}
          >
            {tab}
          </button>
        ))}
      </div>

      <div className="card p-6">
        {activeTab === 'General' && (
          <div className="space-y-4 max-w-lg">
            <div>
              <label className="label">Site Name</label>
              <input className="input" value={form.site_name || ''} onChange={(e) => set('site_name', e.target.value)} />
            </div>
            <div>
              <label className="label">Timezone</label>
              <input className="input" value={form.timezone || ''} onChange={(e) => set('timezone', e.target.value)} placeholder="e.g. America/New_York" />
            </div>
            <div>
              <label className="label">Log Retention (days)</label>
              <input type="number" className="input" value={form.log_retention_days || ''} onChange={(e) => set('log_retention_days', e.target.value)} min={30} max={3650} />
            </div>
          </div>
        )}

        {activeTab === 'Security' && (
          <div className="space-y-4 max-w-lg">
            <div>
              <label className="label">Session Timeout (seconds, 0 = unlimited)</label>
              <input type="number" className="input" value={form.session_timeout || ''} onChange={(e) => set('session_timeout', e.target.value)} min={0} />
            </div>
            <div>
              <label className="label">Min Password Length</label>
              <input type="number" className="input" value={form.password_min_length || ''} onChange={(e) => set('password_min_length', e.target.value)} min={1} />
            </div>
            <div className="flex items-center gap-2">
              <input type="checkbox" id="mixed_case" checked={form.password_require_mixed_case === '1'} onChange={(e) => set('password_require_mixed_case', e.target.checked ? '1' : '0')} className="h-4 w-4 rounded border-slate-300 text-primary-600" />
              <label htmlFor="mixed_case" className="text-sm text-slate-700 dark:text-slate-300">Require mixed case</label>
            </div>
            <div className="flex items-center gap-2">
              <input type="checkbox" id="numbers" checked={form.password_require_numbers === '1'} onChange={(e) => set('password_require_numbers', e.target.checked ? '1' : '0')} className="h-4 w-4 rounded border-slate-300 text-primary-600" />
              <label htmlFor="numbers" className="text-sm text-slate-700 dark:text-slate-300">Require numbers</label>
            </div>
            <div>
              <label className="label">Max Failed Login Attempts</label>
              <input type="number" className="input" value={form.max_login_attempts || ''} onChange={(e) => set('max_login_attempts', e.target.value)} min={3} max={20} />
            </div>
            <div>
              <label className="label">Lockout Duration (seconds)</label>
              <input type="number" className="input" value={form.lockout_duration || ''} onChange={(e) => set('lockout_duration', e.target.value)} min={0} />
            </div>
          </div>
        )}

        {activeTab === 'Controller' && (
          <div className="space-y-4 max-w-lg">
            <div>
              <label className="label">Max Unlock Duration (seconds)</label>
              <input type="number" className="input" value={form.max_unlock_duration || ''} onChange={(e) => set('max_unlock_duration', e.target.value)} min={60} max={86400} />
            </div>
            <div>
              <label className="label">Default Unlock Duration (seconds)</label>
              <input type="number" className="input" value={form.default_unlock_duration || ''} onChange={(e) => set('default_unlock_duration', e.target.value)} min={1} />
            </div>
            <div>
              <label className="label">Default Daily Scan Limit</label>
              <input type="number" className="input" value={form.default_daily_scan_limit || ''} onChange={(e) => set('default_daily_scan_limit', e.target.value)} min={0} max={999} />
            </div>
            <div>
              <label className="label">Heartbeat Interval (seconds)</label>
              <input type="number" className="input" value={form.heartbeat_interval || ''} onChange={(e) => set('heartbeat_interval', e.target.value)} min={30} max={600} />
            </div>
            <div>
              <label className="label">Cache Duration (seconds)</label>
              <input type="number" className="input" value={form.cache_duration || ''} onChange={(e) => set('cache_duration', e.target.value)} min={3600} max={604800} />
            </div>
            <div>
              <label className="label">Controller Update URL</label>
              <input className="input" value={form.controller_update_url || ''} onChange={(e) => set('controller_update_url', e.target.value)} />
            </div>
            <div className="border-t border-slate-200 dark:border-slate-700 pt-4 mt-4">
              <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-3">Push Communication</h3>
              <div className="space-y-4">
                <div>
                  <label className="label">Default Listen Port</label>
                  <input type="number" className="input" value={form.default_listen_port || ''} onChange={(e) => set('default_listen_port', e.target.value)} min={1024} max={65535} />
                  <p className="mt-1 text-xs text-slate-400">HTTPS port controllers listen on for push commands (default: 8443)</p>
                </div>
                <div>
                  <label className="label">Push Timeout (seconds)</label>
                  <input type="number" className="input" value={form.push_timeout || ''} onChange={(e) => set('push_timeout', e.target.value)} min={1} max={30} />
                  <p className="mt-1 text-xs text-slate-400">How long to wait for a controller to respond before falling back to polling</p>
                </div>
                <div>
                  <label className="label">Push Fallback Poll Interval (seconds)</label>
                  <input type="number" className="input" value={form.push_fallback_poll_interval || ''} onChange={(e) => set('push_fallback_poll_interval', e.target.value)} min={5} max={60} />
                  <p className="mt-1 text-xs text-slate-400">Poll interval for controllers with push enabled (safety net, default: 15s)</p>
                </div>
                <div>
                  <label className="label">Status Check Timeout (seconds)</label>
                  <input type="number" className="input" value={form.status_check_timeout || ''} onChange={(e) => set('status_check_timeout', e.target.value)} min={1} max={10} />
                  <p className="mt-1 text-xs text-slate-400">How long to wait when pinging controllers for live status (default: 2s)</p>
                </div>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'SMTP' && (
          <div className="space-y-4 max-w-lg">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="label">SMTP Host</label>
                <input className="input" value={form.smtp_host || ''} onChange={(e) => set('smtp_host', e.target.value)} />
              </div>
              <div>
                <label className="label">SMTP Port</label>
                <input type="number" className="input" value={form.smtp_port || ''} onChange={(e) => set('smtp_port', e.target.value)} />
              </div>
            </div>
            <div>
              <label className="label">SMTP Username</label>
              <input className="input" value={form.smtp_user || ''} onChange={(e) => set('smtp_user', e.target.value)} />
            </div>
            <div>
              <label className="label">SMTP Password</label>
              <input type="password" className="input" value={form.smtp_pass || ''} onChange={(e) => set('smtp_pass', e.target.value)} placeholder="Leave blank to keep current" />
            </div>
            <div>
              <label className="label">From Email</label>
              <input className="input" value={form.smtp_from || ''} onChange={(e) => set('smtp_from', e.target.value)} />
            </div>
            <div className="flex items-center gap-2">
              <input type="checkbox" id="email_notif" checked={form.email_notifications === '1'} onChange={(e) => set('email_notifications', e.target.checked ? '1' : '0')} className="h-4 w-4 rounded border-slate-300 text-primary-600" />
              <label htmlFor="email_notif" className="text-sm text-slate-700 dark:text-slate-300">Enable Email Notifications</label>
            </div>
            <div>
              <label className="label">Notification Email</label>
              <input className="input" value={form.notification_email || ''} onChange={(e) => set('notification_email', e.target.value)} />
            </div>
            <div className="border-t border-slate-200 dark:border-slate-700 pt-4">
              <label className="label">Send Test Email</label>
              <div className="flex gap-2">
                <input className="input" placeholder="recipient@example.com" value={testTo} onChange={(e) => setTestTo(e.target.value)} />
                <button
                  onClick={() => testTo && testEmailMutation.mutate(testTo)}
                  className="btn btn-secondary"
                  disabled={testEmailMutation.isPending || !testTo}
                >
                  {testEmailMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                  Test
                </button>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'Maintenance' && (
          <div className="space-y-4 max-w-lg">
            <div className="flex items-center gap-2">
              <input type="checkbox" id="maintenance" checked={form.maintenance_mode === '1'} onChange={(e) => set('maintenance_mode', e.target.checked ? '1' : '0')} className="h-4 w-4 rounded border-slate-300 text-primary-600" />
              <label htmlFor="maintenance" className="text-sm text-slate-700 dark:text-slate-300">Maintenance Mode</label>
            </div>
            <div className="flex items-center gap-2">
              <input type="checkbox" id="autobackup" checked={form.auto_backup === '1'} onChange={(e) => set('auto_backup', e.target.checked ? '1' : '0')} className="h-4 w-4 rounded border-slate-300 text-primary-600" />
              <label htmlFor="autobackup" className="text-sm text-slate-700 dark:text-slate-300">Auto Backup</label>
            </div>
            <div>
              <label className="label">Backup Retention (days)</label>
              <input type="number" className="input" value={form.backup_retention_days || ''} onChange={(e) => set('backup_retention_days', e.target.value)} min={1} />
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
