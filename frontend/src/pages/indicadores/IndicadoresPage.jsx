import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { listClientes, errorMessage } from '../../api/clientes';
import { pagina } from '../../api/inspecciones';
import { indicadoresOperativos } from '../../api/alertas';
import { useAuth } from '../../auth/AuthContext';
import { SearchSelect } from '../../components/SearchSelect';
import { canManageNeumaticos, isAdmin } from '../../utils/access';

function Tarjeta({ titulo, filas }) {
  return (
    <article className="panel">
      <h2>{titulo}</h2>
      <div className="summary-grid">
        {filas.map(([etiqueta, valor]) => (
          <p key={etiqueta}><strong>{etiqueta}</strong><span>{valor}</span></p>
        ))}
      </div>
    </article>
  );
}

export function IndicadoresPage() {
  const { user } = useAuth();
  const admin = isAdmin(user);
  const [clienteId, setClienteId] = useState('');
  const [clienteLabel, setClienteLabel] = useState('');
  const [datos, setDatos] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!canManageNeumaticos(user)) return undefined;
    let cancelled = false;
    indicadoresOperativos(clienteId ? { cliente_id: clienteId } : {}).then((result) => {
      if (cancelled) return;
      if (!result.ok) {
        setError(errorMessage(result));
        setDatos(null);
        return;
      }
      setError('');
      setDatos(result.payload?.data ?? null);
    });
    return () => { cancelled = true; };
  }, [user, clienteId]);

  if (!canManageNeumaticos(user)) {
    return <section><p className="form-error">Esta vista operativa está reservada a la gestión de neumáticos.</p><Link to="/dashboard">Volver</Link></section>;
  }

  const neumaticos = datos?.neumaticos ?? {};
  const alertas = datos?.alertas ?? {};
  const mantenimiento = datos?.mantenimiento ?? {};
  const vidas = datos?.vidas ?? {};
  const inspecciones = datos?.inspecciones ?? {};

  return (
    <section>
      <div className="page-heading">
        <p className="eyebrow">Operación</p>
        <h1>Indicadores</h1>
        <p className="lede">Vista técnica para validar conteos. El tablero formal se define más adelante.</p>
      </div>
      {admin ? (
        <SearchSelect
          label="Cliente"
          value={clienteId}
          selectedLabel={clienteLabel}
          placeholder="Todos los clientes"
          emptyLabel="Todos"
          scopeKey="indicadores-cliente"
          fetchPage={(params) => listClientes({ ...params, sort: 'razon_social', order: 'asc' }).then(pagina)}
          getLabel={(item) => item.nombre_comercial || item.razon_social}
          onChange={(value, item) => { setClienteId(value); setClienteLabel(item ? (item.nombre_comercial || item.razon_social) : ''); }}
        />
      ) : null}
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      {datos ? (
        <div className="indicator-grid">
          <Tarjeta titulo="Neumáticos" filas={[
            ['Registrados', neumaticos.total_registrados ?? 0],
            ['Operativos', neumaticos.total_operativos ?? 0],
            ['Disponible', neumaticos.DISPONIBLE ?? 0],
            ['Montado', neumaticos.MONTADO ?? 0],
            ['En mantenimiento', neumaticos.EN_MANTENIMIENTO ?? 0],
            ['En reencauche', neumaticos.EN_REENCAUCHE ?? 0],
            ['Descartado', neumaticos.DESCARTADO ?? 0],
          ]} />
          <Tarjeta titulo="Alertas activas" filas={[
            ['Abiertas', alertas.ABIERTA ?? 0],
            ['En atención', alertas.EN_ATENCION ?? 0],
            ['Críticas activas', alertas.criticas_activas ?? 0],
          ]} />
          <Tarjeta titulo="Mantenimiento activo" filas={[
            ['Solicitado', mantenimiento.SOLICITADO ?? 0],
            ['Enviado', mantenimiento.ENVIADO ?? 0],
            ['En proceso', mantenimiento.EN_PROCESO ?? 0],
          ]} />
          <Tarjeta titulo="Vidas" filas={[
            ['Vida 1', vidas.vida_1 ?? 0],
            ['Vida 2', vidas.vida_2 ?? 0],
            ['Vida 3 o más', vidas.vida_3_mas ?? 0],
          ]} />
          <Tarjeta titulo="Inspecciones" filas={[
            ['Finalizadas en 30 días', inspecciones.finalizadas_30_dias ?? 0],
            ['Detalles críticos', inspecciones.criticos_30_dias ?? 0],
          ]} />
          <article className="panel">
            <h2>Rendimiento</h2>
            <p>{datos.rendimiento_km?.motivo}</p>
          </article>
        </div>
      ) : null}
    </section>
  );
}
