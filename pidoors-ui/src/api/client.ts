let csrfToken: string | null = null;

async function fetchCsrfToken(): Promise<string> {
  const res = await fetch('/api/auth/csrf');
  if (!res.ok) throw new Error('Failed to fetch CSRF token');
  const data = await res.json();
  csrfToken = data.token;
  return csrfToken!;
}

export async function getCsrfToken(): Promise<string> {
  if (csrfToken) return csrfToken;
  return fetchCsrfToken();
}

export function clearCsrfToken() {
  csrfToken = null;
}

export class ApiError extends Error {
  status: number;
  constructor(message: string, status: number) {
    super(message);
    this.status = status;
    this.name = 'ApiError';
  }
}

export async function api<T = unknown>(
  path: string,
  options: RequestInit = {}
): Promise<T> {
  const url = `/api/${path.replace(/^\//, '')}`;
  const headers: Record<string, string> = {
    ...(options.headers as Record<string, string>),
  };

  // Add CSRF token for mutating requests
  if (options.method && options.method !== 'GET') {
    const token = await getCsrfToken();
    headers['X-CSRF-Token'] = token;
  }

  // Default to JSON content type for POST/PUT unless FormData
  if (
    options.body &&
    !(options.body instanceof FormData) &&
    !headers['Content-Type']
  ) {
    headers['Content-Type'] = 'application/json';
  }

  const res = await fetch(url, {
    ...options,
    headers,
    credentials: 'same-origin',
  });

  // Handle 401 — session expired
  if (res.status === 401) {
    clearCsrfToken();
    throw new ApiError('Session expired', 401);
  }

  // Handle non-JSON responses (file downloads)
  const contentType = res.headers.get('content-type');
  if (contentType && !contentType.includes('application/json')) {
    if (!res.ok) throw new ApiError('Request failed', res.status);
    return res as unknown as T;
  }

  const data = await res.json();

  if (!res.ok) {
    // Refresh CSRF token on 403 (might be stale token)
    if (res.status === 403) {
      clearCsrfToken();
    }
    throw new ApiError(data.msg || data.error || 'Request failed', res.status);
  }

  return data as T;
}
