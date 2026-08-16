import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError } from '../api/client';

interface State<T> {
  data: T | null;
  loading: boolean;
  error: string | null;
}

interface Options {
  /** Poll interval in ms. The dashboard polls because clicks land continuously. */
  pollMs?: number;
  enabled?: boolean;
}

/**
 * Small fetch-with-abort hook. Every request is cancelled when its inputs
 * change or the component unmounts, so a fast range-toggle cannot leave a slow
 * earlier response to overwrite a newer one.
 */
export function useApiResource<T>(
  fetcher: (signal: AbortSignal) => Promise<T>,
  deps: unknown[],
  options: Options = {},
): State<T> & { refresh: () => void } {
  const { pollMs, enabled = true } = options;

  const [state, setState] = useState<State<T>>({ data: null, loading: true, error: null });
  const [nonce, setNonce] = useState(0);
  const fetcherRef = useRef(fetcher);
  fetcherRef.current = fetcher;

  const refresh = useCallback(() => setNonce((n) => n + 1), []);

  useEffect(() => {
    if (!enabled) {
      return;
    }

    const controller = new AbortController();
    let cancelled = false;

    const load = async (showSpinner: boolean) => {
      if (showSpinner) {
        setState((prev) => ({ ...prev, loading: true }));
      }

      try {
        const data = await fetcherRef.current(controller.signal);

        if (!cancelled) {
          setState({ data, loading: false, error: null });
        }
      } catch (error) {
        if (cancelled || controller.signal.aborted) {
          return;
        }

        const message =
          error instanceof ApiError
            ? error.message
            : error instanceof Error
              ? error.message
              : 'Something went wrong.';

        setState((prev) => ({ data: prev.data, loading: false, error: message }));
      }
    };

    void load(true);

    // Polls do not raise the spinner: the chart should refresh in place rather
    // than flashing a skeleton every few seconds.
    const timer = pollMs ? window.setInterval(() => void load(false), pollMs) : undefined;

    return () => {
      cancelled = true;
      controller.abort();

      if (timer) {
        window.clearInterval(timer);
      }
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, nonce, pollMs, enabled]);

  return { ...state, refresh };
}
