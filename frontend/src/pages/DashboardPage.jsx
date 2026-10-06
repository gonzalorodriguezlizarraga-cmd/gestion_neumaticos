import { RoleGuard } from '../components/RoleGuard';
import { useAuth } from '../auth/AuthContext';
import { roleLabel } from '../utils/roles';

export function DashboardPage() {
  const { user } = useAuth();
  const clients = user?.clientes ?? [];
  const global = user?.scope === 'GLOBAL';

  return (
    <section className="dashboard">
      <div className="page-heading">
        <p className="eyebrow">Sesión activa</p>
        <h1>Hola, {user?.nombres}</h1>
        <p className="lede">
          Este panel confirma la identidad, los roles vigentes y el alcance de clientes.
          La administración de clientes está en su propio módulo.
        </p>
      </div>

      <div className="grid">
        <article className="panel">
          <h2>Roles</h2>
          {user?.roles?.length ? (
            <ul className="chips">
              {user.roles.map((role) => (
                <li key={role}>{roleLabel(role)}</li>
              ))}
            </ul>
          ) : (
            <p>No hay roles activos.</p>
          )}
        </article>

        <article className="panel">
          <h2>Alcance</h2>
          <p className="scope">{global ? 'Global' : 'Clientes asignados'}</p>
          {global ? (
            <p>Puede operar sobre los clientes del sistema. No se carga el listado completo en esta sesión.</p>
          ) : clients.length === 0 ? (
            <p>No tiene clientes vigentes.</p>
          ) : (
            <ul className="client-list">
              {clients.map((client) => (
                <li key={client.id}>
                  <strong>{client.nombre_comercial || client.razon_social}</strong>
                  <span>{client.razon_social}</span>
                </li>
              ))}
            </ul>
          )}
        </article>
      </div>

      <RoleGuard roles={['ADMIN_GENERAL']}>
        <article className="panel panel-accent">
          <h2>Administración general</h2>
          <p>Su rol tiene alcance global. Esta indicación es solo de interfaz; el permiso se vuelve a comprobar en cada solicitud.</p>
        </article>
      </RoleGuard>
    </section>
  );
}
