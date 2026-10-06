// Thin client for the CRM's /api endpoints. The server enforces every permission and
// data-scope rule; the app only decides which screens to show.

export type ApiUser = {
  id: number;
  name: string;
  username: string;
  email: string | null;
  role: { slug: string; name: string; data_scope: string };
  employee_id: number | null;
  permissions?: string[];
};

export class ApiError extends Error {
  constructor(public status: number, public type: string, message: string) {
    super(message);
  }
}

/** "192.168.29.12/marketing_crm" -> "http://192.168.29.12/marketing_crm" (no trailing slash). */
export function normaliseServer(input: string): string {
  let s = input.trim().replace(/\/+$/, '');
  if (s !== '' && !/^https?:\/\//i.test(s)) {
    s = 'http://' + s;
  }
  return s;
}

export async function api<T>(server: string, path: string, opts: { token?: string | null; method?: string; body?: unknown } = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (opts.token) headers.Authorization = `Bearer ${opts.token}`;
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 20000);
  let res: Response;
  try {
    res = await fetch(server + path, {
      method: opts.method ?? 'GET',
      headers,
      body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
      signal: controller.signal,
    });
  } catch {
    throw new ApiError(0, 'network', 'Cannot reach the CRM server. Check the address and that the phone is on the office Wi-Fi.');
  } finally {
    clearTimeout(timer);
  }

  let json: any = null;
  try {
    json = await res.json();
  } catch {
    throw new ApiError(res.status, 'bad_response', `The server sent an unexpected reply (HTTP ${res.status}). Is the address correct?`);
  }
  if (!res.ok || json?.success === false) {
    const e = json?.error ?? {};
    throw new ApiError(res.status, e.type ?? 'error', e.message ?? `Request failed (HTTP ${res.status}).`);
  }
  return json.data as T;
}

/** Indian grouping: "1234567.5" -> "₹12,34,567" (whole rupees for cards). */
export function inr(value: string | number | null | undefined, decimals = 0): string {
  if (value === null || value === undefined || value === '') return '—';
  const n = typeof value === 'number' ? value : Number(value);
  if (!Number.isFinite(n)) return '—';
  const neg = n < 0;
  const fixed = Math.abs(n).toFixed(decimals);
  const [whole, frac] = fixed.split('.');
  const last3 = whole.slice(-3);
  const rest = whole.slice(0, -3);
  const grouped = rest ? rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + last3 : last3;
  return (neg ? '-₹' : '₹') + grouped + (frac ? '.' + frac : '');
}

/** "2026-10-06" -> "06-10-2026" */
export function dmy(date: string | null | undefined): string {
  if (!date) return '—';
  const [y, m, d] = date.slice(0, 10).split('-');
  return `${d}-${m}-${y}`;
}
