const TOKEN_KEY = 'linkforge.token';

const ORIGIN = (import.meta.env.VITE_API_ORIGIN as string | undefined) ?? '';

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly errors: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** Flattened validation messages, ready to render under a form. */
  get fieldMessages(): string[] {
    return Object.values(this.errors).flat();
  }
}

export function getToken(): string {
  return localStorage.getItem(TOKEN_KEY) ?? ((import.meta.env.VITE_API_TOKEN as string) || '');
}

export function setToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token.trim());
}

export function clearToken(): void {
  localStorage.removeItem(TOKEN_KEY);
}

interface RequestOptions {
  method?: string;
  body?: unknown;
  signal?: AbortSignal;
  query?: Record<string, string | number | boolean | undefined>;
}

function buildUrl(path: string, query?: RequestOptions['query']): string {
  const url = new URL(`${ORIGIN}/api${path}`, window.location.origin);

  Object.entries(query ?? {}).forEach(([key, value]) => {
    if (value !== undefined && value !== '') {
      url.searchParams.set(key, String(value));
    }
  });

  return url.toString();
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const response = await fetch(buildUrl(path, options.query), {
    method: options.method ?? 'GET',
    signal: options.signal,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${getToken()}`,
    },
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  });

  if (response.status === 204) {
    return undefined as T;
  }

  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new ApiError(
      (payload as { message?: string }).message ?? `Request failed with status ${response.status}`,
      response.status,
      (payload as { errors?: Record<string, string[]> }).errors ?? {},
    );
  }

  return payload as T;
}
