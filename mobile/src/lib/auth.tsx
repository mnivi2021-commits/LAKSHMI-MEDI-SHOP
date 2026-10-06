import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api, ApiError, normaliseServer, type ApiUser } from './api';
import { deleteItem, getItem, setItem } from './storage';

type AuthState = {
  ready: boolean;
  server: string;
  token: string | null;
  user: ApiUser | null;
  signIn: (server: string, username: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
  /** GET an API path with the current token; signs out automatically if the token expired. */
  get: <T>(path: string) => Promise<T>;
  can: (permission: string) => boolean;
};

const AuthContext = createContext<AuthState | null>(null);
const K_SERVER = 'crm.server';
const K_TOKEN = 'crm.token';

export function AuthProvider({ children }: { children: ReactNode }) {
  const [ready, setReady] = useState(false);
  const [server, setServer] = useState('');
  const [token, setToken] = useState<string | null>(null);
  const [user, setUser] = useState<ApiUser | null>(null);

  // Restore a saved session and check it is still valid.
  useEffect(() => {
    (async () => {
      const s = (await getItem(K_SERVER)) ?? '';
      const t = await getItem(K_TOKEN);
      setServer(s);
      if (s && t) {
        try {
          const me = await api<{ user: ApiUser }>(s, '/api/auth/me', { token: t });
          setToken(t);
          setUser(me.user);
        } catch (e) {
          if (e instanceof ApiError && e.status === 401) await deleteItem(K_TOKEN);
        }
      }
      setReady(true);
    })();
  }, []);

  const signIn = useCallback(async (rawServer: string, username: string, password: string) => {
    const s = normaliseServer(rawServer);
    const data = await api<{ token: string; user: ApiUser }>(s, '/api/auth/login', {
      method: 'POST',
      body: { username, password, device_name: 'Mobile app' },
    });
    await setItem(K_SERVER, s);
    await setItem(K_TOKEN, data.token);
    setServer(s);
    setToken(data.token);
    setUser(data.user);
  }, []);

  const signOut = useCallback(async () => {
    if (token) {
      api(server, '/api/auth/logout', { token, method: 'POST' }).catch(() => undefined);
    }
    await deleteItem(K_TOKEN);
    setToken(null);
    setUser(null);
  }, [server, token]);

  const get = useCallback(async <T,>(path: string): Promise<T> => {
    try {
      return await api<T>(server, path, { token });
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) {
        await deleteItem(K_TOKEN);
        setToken(null);
        setUser(null);
      }
      throw e;
    }
  }, [server, token]);

  const can = useCallback((p: string) => user?.role.slug === 'admin_head' || (user?.permissions ?? []).includes(p), [user]);

  const value = useMemo(() => ({ ready, server, token, user, signIn, signOut, get, can }), [ready, server, token, user, signIn, signOut, get, can]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
}
