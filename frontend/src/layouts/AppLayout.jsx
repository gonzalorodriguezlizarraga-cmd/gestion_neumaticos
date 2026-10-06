import { NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../auth/AuthContext';
import { canManageNeumaticos, canReadComercial, canSeeReportes, hasRole, isPortal } from '../utils/access';

export function AppLayout() {
  const { user, logout } = useAuth();
  const fullName = [user?.nombres, user?.apellidos].filter(Boolean).join(' ');
  const portal = isPortal(user);
  const operador = hasRole(user, 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS');
  const tecnico = hasRole(user, 'TECNICO_INSPECCION');
  const operacion = operador || tecnico || portal;

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
          {operador ? <NavLink to="/clientes">Clientes</NavLink> : null}
          {operacion ? <NavLink to="/unidades">Unidades</NavLink> : null}
          {operacion ? <NavLink to="/neumaticos">Neumáticos</NavLink> : null}
          {operacion ? <NavLink to="/inspecciones">Inspecciones</NavLink> : null}
          {operacion ? <NavLink to="/mantenimientos">Mantenimiento</NavLink> : null}
          {operacion ? <NavLink to="/alertas">Alertas</NavLink> : null}
          {canManageNeumaticos(user) ? <NavLink to="/indicadores">Indicadores</NavLink> : null}
          {canSeeReportes(user) ? <NavLink to="/reportes">Reportes</NavLink> : null}
          {canReadComercial(user) ? <span className="nav-label">Comercial</span> : null}
          {canReadComercial(user) ? <NavLink to="/comercial/oportunidades">Oportunidades</NavLink> : null}
          {canReadComercial(user) ? <NavLink to="/comercial/seguimientos">Seguimientos</NavLink> : null}
          {canReadComercial(user) ? <NavLink to="/comercial/cotizaciones">Cotizaciones</NavLink> : null}
          {operador ? <NavLink to="/configuraciones-unidad">Configuraciones</NavLink> : null}
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
