import { useState } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';

export function LoginPage() {
  const { status, login } = useAuth();
  const location = useLocation();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [details, setDetails] = useState(null);
  const [pending, setPending] = useState(false);

  if (status === 'loading') {
    return <div className="screen-center">Comprobando sesión…</div>;
  }
  if (status === 'authenticated') {
    const requested = location.state?.from;
    const destination = typeof requested === 'string' && requested.startsWith('/') && !requested.startsWith('//')
      ? requested
      : '/dashboard';
    return <Navigate to={destination === '/login' ? '/dashboard' : destination} replace />;
  }

  async function onSubmit(event) {
    event.preventDefault();
    setPending(true);
    setError('');
    setDetails(null);
    try {
      const result = await login(email.trim(), password);
      if (!result.ok) {
        setError(result.message);
        setDetails(result.details);
      }
    } catch {
      setError('No se pudo contactar el servidor.');
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="login-screen">
      <section className="login-card">
        <p className="eyebrow">Bloque de acceso</p>
        <h1>NeumaControl</h1>
        <p className="lede">Ingrese con su cuenta para continuar. El alcance de clientes lo resuelve el servidor.</p>
        <form onSubmit={onSubmit} noValidate>
          <label htmlFor="email">Correo</label>
          <input
            id="email"
            name="email"
            type="email"
            autoComplete="username"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            required
          />
          {details?.email ? <p className="field-error">{details.email[0]}</p> : null}

          <label htmlFor="password">Contraseña</label>
          <input
            id="password"
            name="password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            required
          />
          {details?.password ? <p className="field-error">{details.password[0]}</p> : null}

          {error ? <p className="form-error" role="alert">{error}</p> : null}
          <button className="button button-primary" type="submit" disabled={pending}>
            {pending ? 'Ingresando…' : 'Ingresar'}
          </button>
        </form>
      </section>
    </div>
  );
}
