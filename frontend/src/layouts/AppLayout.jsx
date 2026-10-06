import { NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';

export function AppLayout() {
  const { user, logout } = useAuth();
  const fullName = [user?.nombres, user?.apellidos].filter(Boolean).join(' ');

  return (
    <div className="app-shell">
      <header className="topbar">
        <div className="brand">
          <span className="brand-mark" aria-hidden="true" />
          <div>
            <strong>NeumaControl</strong>
            <small>Gestión de llantas</small>
          </div>
        </div>
        <nav className="main-nav" aria-label="Principal">
          <NavLink to="/dashboard">Inicio</NavLink>
          <NavLink to="/clientes">Clientes</NavLink>
        </nav>
        <div className="topbar-user">
          <div>
            <strong>{fullName || 'Usuario'}</strong>
            <small>{user?.email}</small>
          </div>
          <button type="button" className="button button-ghost" onClick={logout}>
            Cerrar sesión
          </button>
        </div>
      </header>
      <main className="content">
        <Outlet />
      </main>
    </div>
  );
}
