import { useState, useMemo, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import {
  CreditCard,
  Plus,
  Pencil,
  Trash2,
  Download,
  Search,
  Loader2,
  X,
  ChevronLeft,
  ChevronRight,
} from 'lucide-react';
import { getCards, getCard, createCard, updateCard, deleteCard, getExportUrl } from '../api/cards';
import { getSchedules } from '../api/schedules';
import { getGroups } from '../api/groups';
import toast from 'react-hot-toast';
import type { Card, Schedule, AccessGroup } from '../types';

function CardFormModal({
  card,
  schedules,
  groups,
  onClose,
  onSave,
  saving,
}: {
  card: Partial<Card> | null;
  schedules: Schedule[];
  groups: AccessGroup[];
  onClose: () => void;
  onSave: (data: Partial<Card>) => void;
  saving: boolean;
}) {
  const isEdit = card && card.card_id;
  const [form, setForm] = useState<Partial<Card> & { master_card?: number }>({
    user_id: '',
    facility: '',
    firstname: '',
    lastname: '',
    doors: '',
    active: 1,
    master_card: 0,
    group_id: null,
    schedule_id: null,
    valid_from: null,
    valid_until: null,
    daily_scan_limit: null,
    email: '',
    phone: '',
    department: '',
    employee_id: '',
    company: '',
    title: '',
    notes: '',
    ...card,
  });

  const set = (field: string, value: unknown) => setForm({ ...form, [field]: value });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="card w-full max-w-2xl max-h-[90vh] overflow-y-auto p-6">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-slate-900 dark:text-white">
            {isEdit ? 'Edit Card' : 'Add Card'}
          </h2>
          <button onClick={onClose} className="btn-ghost rounded-lg p-1">
            <X className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={(e) => { e.preventDefault(); onSave(form); }} className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Card Number *</label>
              <input className="input" value={form.user_id || ''} onChange={(e) => set('user_id', e.target.value)} required disabled={!!isEdit} />
            </div>
            <div>
              <label className="label">Facility Code</label>
              <input className="input" value={form.facility || ''} onChange={(e) => set('facility', e.target.value)} />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">First Name</label>
              <input className="input" value={form.firstname || ''} onChange={(e) => set('firstname', e.target.value)} />
            </div>
            <div>
              <label className="label">Last Name</label>
              <input className="input" value={form.lastname || ''} onChange={(e) => set('lastname', e.target.value)} />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Email</label>
              <input type="email" className="input" value={form.email || ''} onChange={(e) => set('email', e.target.value)} />
            </div>
            <div>
              <label className="label">Phone</label>
              <input className="input" value={form.phone || ''} onChange={(e) => set('phone', e.target.value)} />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Department</label>
              <input className="input" value={form.department || ''} onChange={(e) => set('department', e.target.value)} />
            </div>
            <div>
              <label className="label">Company</label>
              <input className="input" value={form.company || ''} onChange={(e) => set('company', e.target.value)} />
            </div>
          </div>
          <div>
            <label className="label">Doors (comma-separated door names)</label>
            <input className="input" value={form.doors || ''} onChange={(e) => set('doors', e.target.value)} placeholder="front-door, back-door" />
          </div>
          <div className="grid grid-cols-3 gap-4">
            <div>
              <label className="label">Schedule</label>
              <select className="input" value={form.schedule_id ?? ''} onChange={(e) => set('schedule_id', e.target.value ? parseInt(e.target.value) : null)}>
                <option value="">None</option>
                {schedules.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </div>
            <div>
              <label className="label">Access Group</label>
              <select className="input" value={form.group_id ?? ''} onChange={(e) => set('group_id', e.target.value ? parseInt(e.target.value) : null)}>
                <option value="">None</option>
                {groups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
              </select>
            </div>
            <div>
              <label className="label">Status</label>
              <select className="input" value={form.active ?? 1} onChange={(e) => set('active', parseInt(e.target.value))}>
                <option value={1}>Active</option>
                <option value={0}>Inactive</option>
              </select>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              id="master_card"
              checked={!!form.master_card}
              onChange={(e) => set('master_card', e.target.checked ? 1 : 0)}
              className="h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
            />
            <label htmlFor="master_card" className="text-sm font-medium text-slate-700 dark:text-slate-300">
              Master Card (can toggle held-open mode)
            </label>
          </div>
          <div className="grid grid-cols-3 gap-4">
            <div>
              <label className="label">Valid From</label>
              <input type="date" className="input" value={form.valid_from || ''} onChange={(e) => set('valid_from', e.target.value || null)} />
            </div>
            <div>
              <label className="label">Valid Until</label>
              <input type="date" className="input" value={form.valid_until || ''} onChange={(e) => set('valid_until', e.target.value || null)} />
            </div>
            <div>
              <label className="label">Daily Scan Limit</label>
              <input type="number" className="input" value={form.daily_scan_limit ?? ''} onChange={(e) => set('daily_scan_limit', e.target.value ? parseInt(e.target.value) : null)} min={0} />
            </div>
          </div>
          <div>
            <label className="label">Notes</label>
            <textarea className="input" rows={2} value={form.notes || ''} onChange={(e) => set('notes', e.target.value)} />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="btn btn-secondary">Cancel</button>
            <button type="submit" className="btn btn-primary" disabled={saving}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              {isEdit ? 'Save Changes' : 'Add Card'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export function CardsPage() {
  const queryClient = useQueryClient();
  const [searchParams, setSearchParams] = useSearchParams();
  const [search, setSearch] = useState('');
  const [showForm, setShowForm] = useState(false);
  const [prefill, setPrefill] = useState<Partial<Card> | null>(null);
  const [editCard, setEditCard] = useState<Card | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<Card | null>(null);
  const [page, setPage] = useState(1);
  const perPage = 25;

  // Auto-open form when navigated with ?add=<card_number> or ?edit=<card_id>
  useEffect(() => {
    const addUserId = searchParams.get('add');
    const editId = searchParams.get('edit');
    if (addUserId) {
      setPrefill({ user_id: addUserId });
      setShowForm(true);
      setSearchParams({}, { replace: true });
    } else if (editId) {
      setSearchParams({}, { replace: true });
      getCard(editId).then((card) => {
        setEditCard(card);
      }).catch(() => {
        toast.error('Card not found');
      });
    }
  }, [searchParams, setSearchParams]);

  const { data: cards = [], isLoading } = useQuery({ queryKey: ['cards'], queryFn: getCards });
  const { data: schedules = [] } = useQuery({ queryKey: ['schedules'], queryFn: getSchedules });
  const { data: groups = [] } = useQuery({ queryKey: ['groups'], queryFn: getGroups });

  const filtered = useMemo(() => {
    if (!search) return cards;
    const s = search.toLowerCase();
    return cards.filter(
      (c) =>
        c.firstname?.toLowerCase().includes(s) ||
        c.lastname?.toLowerCase().includes(s) ||
        c.user_id?.toLowerCase().includes(s) ||
        c.email?.toLowerCase().includes(s) ||
        c.department?.toLowerCase().includes(s)
    );
  }, [cards, search]);

  const totalPages = Math.ceil(filtered.length / perPage);
  const paginated = filtered.slice((page - 1) * perPage, page * perPage);

  const addMutation = useMutation({
    mutationFn: (data: Partial<Card>) => createCard(data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['cards'] }); setShowForm(false); toast.success('Card created'); },
    onError: (err: Error) => toast.error(err.message),
  });

  const editMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<Card> }) => updateCard(id, data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['cards'] }); setEditCard(null); toast.success('Card updated'); },
    onError: (err: Error) => toast.error(err.message),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deleteCard(id),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['cards'] }); setConfirmDelete(null); toast.success('Card deleted'); },
    onError: (err: Error) => toast.error(err.message),
  });

  if (isLoading) {
    return <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-primary-600" /></div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Cards</h1>
        <div className="flex gap-2">
          <a href={getExportUrl()} className="btn btn-outline" download>
            <Download className="h-4 w-4" />
            Export CSV
          </a>
          <button onClick={() => setShowForm(true)} className="btn btn-primary">
            <Plus className="h-4 w-4" />
            Add Card
          </button>
        </div>
      </div>

      {/* Search */}
      <div className="relative max-w-sm">
        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <input
          className="input pl-9"
          placeholder="Search cards..."
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1); }}
        />
      </div>

      {/* Table */}
      <div className="card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 dark:border-slate-700">
                <th className="px-4 py-3 text-left font-medium text-slate-500 dark:text-slate-400">Card #</th>
                <th className="px-4 py-3 text-left font-medium text-slate-500 dark:text-slate-400">Name</th>
                <th className="px-4 py-3 text-left font-medium text-slate-500 dark:text-slate-400 hidden md:table-cell">Department</th>
                <th className="px-4 py-3 text-left font-medium text-slate-500 dark:text-slate-400 hidden lg:table-cell">Doors</th>
                <th className="px-4 py-3 text-left font-medium text-slate-500 dark:text-slate-400">Status</th>
                <th className="px-4 py-3 text-right font-medium text-slate-500 dark:text-slate-400">Actions</th>
              </tr>
            </thead>
            <tbody>
              {paginated.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-4 py-8 text-center text-slate-500">
                    {search ? 'No cards match your search' : 'No cards yet'}
                  </td>
                </tr>
              ) : (
                paginated.map((card) => (
                  <tr key={card.card_id} className="border-b border-slate-100 dark:border-slate-700/50 hover:bg-slate-50 dark:hover:bg-slate-700/30">
                    <td className="px-4 py-3 font-mono text-slate-900 dark:text-white">{card.user_id}</td>
                    <td className="px-4 py-3 text-slate-900 dark:text-white">
                      {card.firstname} {card.lastname}
                    </td>
                    <td className="px-4 py-3 text-slate-500 hidden md:table-cell">{card.department || '-'}</td>
                    <td className="px-4 py-3 text-slate-500 hidden lg:table-cell max-w-[200px] truncate">{card.doors || '-'}</td>
                    <td className="px-4 py-3">
                      <span className={`badge ${card.active ? 'badge-success' : 'badge-danger'}`}>
                        {card.active ? 'Active' : 'Inactive'}
                      </span>
                      {card.master_card ? <span className="badge badge-warning ml-1">Master</span> : null}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <div className="flex justify-end gap-1">
                        <button onClick={() => setEditCard(card)} className="btn-ghost rounded p-1" title="Edit">
                          <Pencil className="h-4 w-4" />
                        </button>
                        <button onClick={() => setConfirmDelete(card)} className="rounded p-1 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20" title="Delete">
                          <Trash2 className="h-4 w-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {totalPages > 1 && (
          <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 dark:border-slate-700">
            <p className="text-sm text-slate-500">
              Showing {(page - 1) * perPage + 1} - {Math.min(page * perPage, filtered.length)} of {filtered.length}
            </p>
            <div className="flex gap-1">
              <button onClick={() => setPage(Math.max(1, page - 1))} disabled={page === 1} className="btn btn-sm btn-ghost">
                <ChevronLeft className="h-4 w-4" />
              </button>
              <button onClick={() => setPage(Math.min(totalPages, page + 1))} disabled={page === totalPages} className="btn btn-sm btn-ghost">
                <ChevronRight className="h-4 w-4" />
              </button>
            </div>
          </div>
        )}
      </div>

      {/* Add/Edit Modal */}
      {(showForm || editCard) && (
        <CardFormModal
          card={editCard || prefill}
          schedules={schedules}
          groups={groups}
          onClose={() => { setShowForm(false); setEditCard(null); setPrefill(null); }}
          onSave={(data) => {
            if (editCard) {
              editMutation.mutate({ id: editCard.card_id, data });
            } else {
              addMutation.mutate(data);
            }
          }}
          saving={addMutation.isPending || editMutation.isPending}
        />
      )}

      {/* Delete confirmation */}
      {confirmDelete && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="card w-full max-w-sm p-6">
            <h3 className="text-lg font-semibold text-slate-900 dark:text-white">Delete Card</h3>
            <p className="mt-2 text-sm text-slate-500">
              Delete card <strong>{confirmDelete.user_id}</strong> ({confirmDelete.firstname} {confirmDelete.lastname})?
            </p>
            <div className="mt-4 flex justify-end gap-2">
              <button onClick={() => setConfirmDelete(null)} className="btn btn-secondary">Cancel</button>
              <button onClick={() => deleteMutation.mutate(confirmDelete.card_id)} className="btn btn-danger" disabled={deleteMutation.isPending}>
                {deleteMutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
                Delete
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
