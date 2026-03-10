import { Link } from 'react-router-dom';
import { Home } from 'lucide-react';

export function NotFoundPage() {
  return (
    <div className="flex flex-col items-center justify-center py-20">
      <h1 className="text-6xl font-bold text-slate-300 dark:text-slate-600">
        404
      </h1>
      <p className="mt-4 text-lg text-slate-600 dark:text-slate-400">
        Page not found
      </p>
      <Link to="/" className="btn btn-primary mt-6">
        <Home className="h-4 w-4" />
        Back to Dashboard
      </Link>
    </div>
  );
}
