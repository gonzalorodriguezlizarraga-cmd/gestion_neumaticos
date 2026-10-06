import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { api } from '../api/client';
import { tokenStore } from './storage';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const navigate = useNavigate();
  const location = useLocation();
  const [token, setToken] = useState(() => tokenStore.get());
  const [user, setUser] = useState(null);
  const [status, setStatus] = useState(() => (tokenStore.get() ? 'loading' : 'anonymous'));

  useEffect(() => {
    const onUnauthorized = () => {
      tokenStore.clear();
      setToken(null);
      setUser(null);
      setStatus('anonymous');
      if (location.pathname !== '/login') {
        navigate('/login', { replace: true });
      }
    };

    window.addEventListener('auth:unauthorized', onUnauthorized);
    return () => window.removeEventListener('auth:unauthorized', onUnauthorized);
  }, [location.pathname, navigate]);

  useEffect(() => {
    if (!token) {
      setUser(null);
      setStatus('anonymous');
      return undefined;
    }

    let cancelled = false;
    setStatus((current) => (current === 'authenticated' ? current : 'loading'));
    api('/api/v1/auth/me')
      .then((result) => {
        if (cancelled) {
          return;
        }
        if (result.ok && result.payload?.data) {
          setUser(result.payload.data);
          setStatus('authenticated');
          return;
        }
        tokenStore.clear();
        setToken(null);
        setUser(null);
        setStatus('anonymous');
      })
      .catch(() => {
        if (!cancelled) {
          setStatus('anonymous');
        }
      });

    return () => {
      cancelled = true;
    };
  }, [token]);

  const value = useMemo(() => ({
    user,
    status,
    async login(email, password) {
      const result = await api('/api/v1/auth/login', {
        method: 'POST',
        auth: false,
        body: { email, password },
      });
      if (!result.ok || !result.payload?.data?.token) {
        return {
          ok: false,
          message: result.payload?.err?.message || 'No se pudo iniciar sesión.',
          details: result.payload?.err?.details || null,
        };
      }
      tokenStore.set(result.payload.data.token);
      setUser(result.payload.data.user);
      setToken(result.payload.data.token);
      setStatus('authenticated');
      return { ok: true };
    },
    async logout() {
      try {
        await api('/api/v1/auth/logout', { method: 'POST' });
      } catch {
        // El cierre real es local: el servidor no revoca el JWT.
      }
      tokenStore.clear();
      setToken(null);
      setUser(null);
      setStatus('anonymous');
      navigate('/login', { replace: true });
    },
  }), [navigate, status, user]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (context === null) {
    throw new Error('useAuth debe usarse dentro de AuthProvider.');
  }
  return context;
}
