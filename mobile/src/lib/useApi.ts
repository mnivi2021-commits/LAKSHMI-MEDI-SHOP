import { useCallback, useEffect, useState } from 'react';
import { useAuth } from './auth';

type State<T> = { data: T | null; error: string | null; loading: boolean };

/**
 * Load an API path when the screen opens; reload() for pull-to-refresh / retry.
 * Results that arrive after the screen closed are ignored.
 */
export function useApi<T>(path: string): State<T> & { reload: () => void } {
  const { get } = useAuth();
  const [state, setState] = useState<State<T>>({ data: null, error: null, loading: true });
  const [nonce, setNonce] = useState(0);

  useEffect(() => {
    let active = true;
    get<T>(path)
      .then((data) => active && setState({ data, error: null, loading: false }))
      .catch((e: unknown) => active && setState((s) => ({ data: s.data, error: e instanceof Error ? e.message : 'Could not load.', loading: false })));
    return () => {
      active = false;
    };
  }, [get, path, nonce]);

  const reload = useCallback(() => {
    setState((s) => ({ ...s, loading: true }));
    setNonce((n) => n + 1);
  }, []);

  return { ...state, reload };
}
