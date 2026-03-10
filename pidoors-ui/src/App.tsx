import { BrowserRouter, Routes, Route } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Toaster } from 'react-hot-toast';
import { ThemeProvider } from './contexts/ThemeContext';
import { AuthProvider } from './contexts/AuthContext';
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
                <Route path="cards" element={<CardsPage />} />
                <Route path="cards/import" element={<ImportCardsPage />} />
                <Route path="logs" element={<LogsPage />} />
                <Route path="reports" element={<ReportsPage />} />
                <Route path="schedules" element={<SchedulesPage />} />
                <Route path="groups" element={<GroupsPage />} />
                <Route path="holidays" element={<HolidaysPage />} />
                <Route path="users" element={<UsersPage />} />
                <Route path="audit" element={<AuditPage />} />
                <Route path="settings" element={<SettingsPage />} />
                <Route path="backup" element={<BackupPage />} />
                <Route path="updates" element={<UpdatePage />} />
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
