import { useState, useRef } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Upload, FileText, Loader2, CheckCircle, AlertTriangle } from 'lucide-react';
import { importCards } from '../api/cards';
import toast from 'react-hot-toast';

export function ImportCardsPage() {
  const queryClient = useQueryClient();
  const fileRef = useRef<HTMLInputElement>(null);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string[][]>([]);
  const [result, setResult] = useState<{ imported: number; skipped: number; errors?: string[] } | null>(null);

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setSelectedFile(file);
    setResult(null);

    // Preview first 5 rows
    const reader = new FileReader();
    reader.onload = (ev) => {
      const text = ev.target?.result as string;
      const lines = text.split('\n').filter((l) => l.trim());
      const rows = lines.slice(0, 6).map((l) => {
        // Simple CSV parse (doesn't handle quoted commas)
        return l.split(',').map((c) => c.trim().replace(/^"|"$/g, ''));
      });
      setPreview(rows);
    };
    reader.readAsText(file);
  };

  const importMutation = useMutation({
    mutationFn: (file: File) => importCards(file),
    onSuccess: (data) => {
      setResult({
        imported: (data as { imported?: number }).imported ?? 0,
        skipped: (data as { skipped?: number }).skipped ?? 0,
        errors: (data as { errors?: string[] }).errors,
      });
      queryClient.invalidateQueries({ queryKey: ['cards'] });
      toast.success(`Imported ${(data as { imported?: number }).imported ?? 0} cards`);
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const handleImport = () => {
    if (!selectedFile) return;
    importMutation.mutate(selectedFile);
  };

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Import Cards</h1>

      <div className="card max-w-2xl p-6">
        <p className="text-sm text-slate-600 dark:text-slate-400 mb-4">
          Upload a CSV file with columns: <code className="rounded bg-slate-100 px-1 py-0.5 dark:bg-slate-700">user_id, firstname, lastname</code> (required), plus optional: facility, doors, active, email, phone, department, employee_id, company, title, notes.
        </p>

        {/* File drop zone */}
        <div
          onClick={() => fileRef.current?.click()}
          className="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-300 p-8 transition-colors hover:border-primary-500 dark:border-slate-600"
        >
          <Upload className="h-10 w-10 text-slate-400" />
          <p className="mt-2 text-sm font-medium text-slate-700 dark:text-slate-300">
            {selectedFile ? selectedFile.name : 'Click to select CSV file'}
          </p>
          {selectedFile && (
            <p className="text-xs text-slate-500">
              {(selectedFile.size / 1024).toFixed(1)} KB
            </p>
          )}
          <input
            ref={fileRef}
            type="file"
            accept=".csv"
            className="hidden"
            onChange={handleFileSelect}
          />
        </div>

        {/* Preview */}
        {preview.length > 0 && (
          <div className="mt-4">
            <h3 className="mb-2 text-sm font-medium text-slate-700 dark:text-slate-300">
              Preview (first {preview.length - 1} rows)
            </h3>
            <div className="overflow-x-auto rounded border border-slate-200 dark:border-slate-700">
              <table className="w-full text-xs">
                <thead>
                  <tr className="bg-slate-50 dark:bg-slate-700/50">
                    {preview[0]?.map((h, i) => (
                      <th key={i} className="px-3 py-2 text-left font-medium text-slate-500">
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {preview.slice(1).map((row, ri) => (
                    <tr key={ri} className="border-t border-slate-100 dark:border-slate-700/50">
                      {row.map((cell, ci) => (
                        <td key={ci} className="px-3 py-1.5 text-slate-700 dark:text-slate-300">
                          {cell}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* Import button */}
        {selectedFile && !result && (
          <button
            onClick={handleImport}
            disabled={importMutation.isPending}
            className="btn btn-primary mt-4"
          >
            {importMutation.isPending ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <FileText className="h-4 w-4" />
            )}
            {importMutation.isPending ? 'Importing...' : 'Import Cards'}
          </button>
        )}

        {/* Result */}
        {result && (
          <div className="mt-4 space-y-2">
            <div className="flex items-center gap-2 text-emerald-600 dark:text-emerald-400">
              <CheckCircle className="h-5 w-5" />
              <span className="font-medium">{result.imported} cards imported</span>
            </div>
            {result.skipped > 0 && (
              <div className="flex items-center gap-2 text-amber-600 dark:text-amber-400">
                <AlertTriangle className="h-5 w-5" />
                <span>{result.skipped} rows skipped (duplicates or errors)</span>
              </div>
            )}
            {result.errors && result.errors.length > 0 && (
              <div className="rounded bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-400">
                {result.errors.map((e, i) => <p key={i}>{e}</p>)}
              </div>
            )}
            <button
              onClick={() => { setSelectedFile(null); setPreview([]); setResult(null); }}
              className="btn btn-secondary mt-2"
            >
              Import Another
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
