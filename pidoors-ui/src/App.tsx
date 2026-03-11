import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Toaster } from 'react-hot-toast';
import { useContext, type ReactNode } from 'react';
import { ThemeProvider } from './contexts/ThemeContext';
import { AuthProvider, AuthContext } from './contexts/AuthContext';
import { AppShell } from './components/layout/AppShell';
import { LoginPage } from './pages/LoginPage';
import { DashboardPage } from './pages/DashboardPage';
import { DoorsPage } from './pages/DoorsPage';
import { CardsPage } from './pages/CardsPage';
import { ImportCardsPage } from './pages/ImportCardsPage';
import { LogsPage } from './pages/LogsPage';
import { ReportsPage } from './pages/ReportsPage';
import { SchedulesPage } from './pages/SchedulesPage';
import { GroupsPage } from './pages/GroupsPage';
import { HolidaysPage } from './pages/HolidaysPage';
import { UsersPage } from './pages/UsersPage';
import { AuditPage } from './pages/AuditPage';
import { SettingsPage } from './pages/SettingsPage';
import { BackupPage } from './pages/BackupPage';
import { UpdatePage } from './pages/UpdatePage';
import { ProfilePage } from './pages/ProfilePage';
import { NotFoundPage } from './pages/NotFoundPage';

function RequireAdmin({ children }: { children: ReactNode }) {
  const { user, loading } = useContext(AuthContext);
  if (loading) return null;
  if (!user?.isAdmin) return <Navigate to="/" replace />;
  return <>{children}</>;
}

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      refetchOnWindowFocus: true,
      staleTime: 2000,
    },
  },
});

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider>
        <BrowserRouter>
          <AuthProvider>
            <Routes>
              <Route path="/login" element={<LoginPage />} />

              <Route element={<AppShell />}>
                <Route index element={<DashboardPage />} />
                <Route path="doors" element={<DoorsPage />} />
                <Route path="cards" element={<RequireAdmin><CardsPage /></RequireAdmin>} />
                <Route path="cards/import" element={<RequireAdmin><ImportCardsPage /></RequireAdmin>} />
                <Route path="logs" element={<LogsPage />} />
                <Route path="reports" element={<RequireAdmin><ReportsPage /></RequireAdmin>} />
                <Route path="schedules" element={<SchedulesPage />} />
                <Route path="groups" element={<GroupsPage />} />
                <Route path="holidays" element={<HolidaysPage />} />
                <Route path="users" element={<RequireAdmin><UsersPage /></RequireAdmin>} />
                <Route path="audit" element={<RequireAdmin><AuditPage /></RequireAdmin>} />
                <Route path="settings" element={<RequireAdmin><SettingsPage /></RequireAdmin>} />
                <Route path="backup" element={<RequireAdmin><BackupPage /></RequireAdmin>} />
                <Route path="updates" element={<RequireAdmin><UpdatePage /></RequireAdmin>} />
                <Route path="profile" element={<ProfilePage />} />
              </Route>

              <Route path="*" element={<NotFoundPage />} />
            </Routes>
          </AuthProvider>
        </BrowserRouter>
        <Toaster
          position="top-right"
          toastOptions={{
            className:
              '!bg-white !text-slate-900 dark:!bg-slate-800 dark:!text-white !shadow-lg !border !border-slate-200 dark:!border-slate-700',
            duration: 4000,
          }}
        />
      </ThemeProvider>
    </QueryClientProvider>
  );
}
