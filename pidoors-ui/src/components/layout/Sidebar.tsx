import { NavLink } from 'react-router-dom';
import {
  LayoutDashboard,
  DoorOpen,
  CreditCard,
  FileText,
  Calendar,
  Users2,
  CalendarDays,
  Shield,
  ClipboardList,
  Settings,
  Database,
  RefreshCw,
  Upload,
  Users,
  X,
} from 'lucide-react';
import { useAuth } from '../../hooks/useAuth';

interface SidebarProps {
  open: boolean;
  onClose: () => void;
}

const navLinkClass = ({ isActive }: { isActive: boolean }) =>
  `flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
    isActive
      ? 'bg-primary-600 text-white dark:bg-primary-500'
      : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-700/50'
  }`;

export function Sidebar({ open, onClose }: SidebarProps) {
  const { user } = useAuth();

  return (
    <>
      {/* Mobile overlay */}
      {open && (
        <div
          className="fixed inset-0 z-40 bg-black/50 lg:hidden"
          onClick={onClose}
        />
      )}

      {/* Sidebar */}
      <aside
        className={`fixed inset-y-0 left-0 z-50 flex w-64 flex-col border-r border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800 transition-transform duration-200 lg:translate-x-0 lg:static lg:z-auto ${
          open ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        {/* Header */}
        <div className="flex h-16 items-center justify-between border-b border-slate-200 px-4 dark:border-slate-700">
          <NavLink to="/" className="flex items-center gap-2" onClick={onClose}>
            <DoorOpen className="h-7 w-7 text-primary-600 dark:text-primary-400" />
            <span className="text-lg font-bold text-slate-900 dark:text-white">
              PiDoors
            </span>
          </NavLink>
          <button
            onClick={onClose}
            className="rounded-lg p-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700 lg:hidden"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Navigation */}
        <nav className="flex-1 overflow-y-auto p-3 space-y-1">
          <NavLink to="/" end className={navLinkClass} onClick={onClose}>
            <LayoutDashboard className="h-4 w-4" />
            Dashboard
          </NavLink>
          <NavLink to="/doors" className={navLinkClass} onClick={onClose}>
            <DoorOpen className="h-4 w-4" />
            Doors
          </NavLink>
          <NavLink to="/cards" className={navLinkClass} onClick={onClose}>
            <CreditCard className="h-4 w-4" />
            Cards
          </NavLink>
          <NavLink to="/logs" className={navLinkClass} onClick={onClose}>
            <FileText className="h-4 w-4" />
            Access Logs
          </NavLink>
          <NavLink to="/schedules" className={navLinkClass} onClick={onClose}>
            <Calendar className="h-4 w-4" />
            Schedules
          </NavLink>
          <NavLink to="/groups" className={navLinkClass} onClick={onClose}>
            <Users2 className="h-4 w-4" />
            Access Groups
          </NavLink>
          <NavLink to="/holidays" className={navLinkClass} onClick={onClose}>
            <CalendarDays className="h-4 w-4" />
            Holidays
          </NavLink>

          {/* Admin section */}
          {user?.isAdmin && (
            <>
              <div className="pt-4 pb-1">
                <span className="px-3 text-xs font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">
                  Admin Tools
                </span>
              </div>
              <NavLink to="/users" className={navLinkClass} onClick={onClose}>
                <Users className="h-4 w-4" />
                Panel Users
              </NavLink>
              <NavLink to="/audit" className={navLinkClass} onClick={onClose}>
                <Shield className="h-4 w-4" />
                Audit Log
              </NavLink>
              <NavLink to="/reports" className={navLinkClass} onClick={onClose}>
                <ClipboardList className="h-4 w-4" />
                Reports
              </NavLink>
              <NavLink to="/settings" className={navLinkClass} onClick={onClose}>
                <Settings className="h-4 w-4" />
                Settings
              </NavLink>
              <NavLink to="/backup" className={navLinkClass} onClick={onClose}>
                <Database className="h-4 w-4" />
                Backup
              </NavLink>
              <NavLink to="/updates" className={navLinkClass} onClick={onClose}>
                <RefreshCw className="h-4 w-4" />
                Updates
              </NavLink>
              <NavLink to="/cards/import" className={navLinkClass} onClick={onClose}>
                <Upload className="h-4 w-4" />
                Import Cards
              </NavLink>
            </>
          )}
        </nav>
      </aside>
    </>
  );
}
