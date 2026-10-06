import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { dashboardCliente, dashboardComercial, dashboardOperador } from '../api/dashboard';
import { listClientes } from '../api/clientes';
import { listFlotas, listSedes } from '../api/clientes';
import { pagina } from '../api/inspecciones';
import { useAuth } from '../auth/AuthContext';
import { SearchSelect } from '../components/SearchSelect';
import { hasRole, isConsulta } from '../utils/access';
import { fechaVisible } from '../utils/fechas';

function destino(user) {
  if (hasRole(user, 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'TECNICO_INSPECCION')) return 'operador';
  if (hasRole(user, 'VENDEDOR')) return 'comercial';
  return 'cliente';
}

function Kpi({ titulo, valor, detalle }) {
  return (
    <article className="kpi-card">
      <span>{titulo}</span>
      <strong>{valor}</strong>
      {detalle ? <small>{detalle}</small> : null}
    </article>
  );
}

function Barras({ titulo, items }) {
  const maximo = Math.max(1, ...items.map((item) => item.valor));
  return (
    <article className="panel">
      <h2>{titulo}</h2>
      {items.every((item) => item.valor === 0) ? <p>Sin datos para esta distribución.</p> : (
        <ul className="dist-list">
          {items.map((item) => (
            <li key={item.etiqueta}>
              <span>{item.etiqueta}</span>
              <span className="dist-track" aria-hidden="true"><span style={{ width: `${Math.round((item.valor / maximo) * 100)}%` }} /></span>
              <strong>{item.valor}</strong>
            </li>
          ))}
        </ul>
      )}
    </article>
  );
}

function Lista({ titulo, vacio, enlace, children }) {
  return (
    <article className="panel">
      <div className="row-between">
        <h2>{titulo}</h2>
        {enlace ? <Link to={enlace.to}>{enlace.label}</Link> : null}
      </div>
      {children?.length ? <ul className="dash-list">{children}</ul> : <p>{vacio}</p>}
    </article>
  );
}

export function DashboardPage() {
  const { user } = useAuth();
  const modo = destino(user);
  const admin = hasRole(user, 'ADMIN_GENERAL');
  const resumido = isConsulta(user);
  const [clienteId, setClienteId] = useState('');
  const [clienteLabel, setClienteLabel] = useState('');
  const [sedeId, setSedeId] = useState('');
  const [sedeLabel, setSedeLabel] = useState('');
  const [flotaId, setFlotaId] = useState('');
  const [flotaLabel, setFlotaLabel] = useState('');
  const [datos, setDatos] = useState(null);
  const [error, setError] = useState('');
  const [cargando, setCargando] = useState(true);

  useEffect(() => {
    let cancelled = false;
    setCargando(true);
    const params = { cliente_id: clienteId, sede_id: sedeId, flota_id: flotaId };
    const pedido = modo === 'comercial'
      ? dashboardComercial()
      : modo === 'cliente'
        ? dashboardCliente(params)
        : dashboardOperador(params);
    pedido.then((result) => {
      if (cancelled) return;
      setCargando(false);
      if (!result.ok) {
        setError(result.payload?.err?.message || 'No se pudo cargar el tablero.');
        setDatos(null);
        return;
      }
      setError('');
      setDatos(result.payload?.data ?? null);
    });
    return () => { cancelled = true; };
  }, [modo, clienteId, sedeId, flotaId]);

  const neumaticos = datos?.resumen?.neumaticos ?? {};
  const alertas = datos?.alertas ?? {};
  const mantenimiento = datos?.mantenimiento ?? {};
  const vidas = datos?.resumen?.vidas ?? {};
  const inspecciones = datos?.inspecciones ?? {};
  const criticidad = datos?.resumen?.criticidad ?? {};
  const listas = datos?.listas ?? {};
  const tecnico = datos?.vista === 'tecnico';

  return (
    <section className="dashboard">
      <div className="page-heading">
        <p className="eyebrow">{modo === 'comercial' ? 'Comercial' : modo === 'cliente' ? 'Consulta' : 'Operación'}</p>
        <h1>{modo === 'comercial' ? 'Tablero comercial' : modo === 'cliente' ? 'Tablero del cliente' : tecnico ? 'Tablero técnico' : 'Tablero general'}</h1>
        <p className="lede">
          {modo === 'comercial'
            ? 'Resumen de sus oportunidades y cotizaciones. El detalle sigue en el módulo Comercial.'
            : modo === 'cliente'
              ? 'Indicadores de su operación. No incluye notas comerciales ni alertas descartadas.'
              : 'Resumen operativo calculado con los mismos indicadores del sistema.'}
        </p>
      </div>

      {modo === 'operador' ? (
        <div className="filter-row">
          {admin || hasRole(user, 'GESTOR_NEUMATICOS') ? (
            <SearchSelect
              label="Cliente"
              value={clienteId}
              selectedLabel={clienteLabel}
              placeholder="Todos los clientes del alcance"
              emptyLabel="Todos"
              scopeKey="dash-cliente"
              fetchPage={(params) => listClientes({ ...params, sort: 'razon_social', order: 'asc' }).then(pagina)}
              getLabel={(item) => item.nombre_comercial || item.razon_social}
              onChange={(value, item) => {
                setClienteId(value);
                setClienteLabel(item ? (item.nombre_comercial || item.razon_social) : '');
                setSedeId('');
                setSedeLabel('');
                setFlotaId('');
                setFlotaLabel('');
              }}
            />
          ) : null}
          {clienteId ? (
            <>
              <SearchSelect
                label="Sede"
                value={sedeId}
                selectedLabel={sedeLabel}
                placeholder="Todas las sedes"
                emptyLabel="Todas"
                scopeKey={`dash-sede-${clienteId}`}
                fetchPage={(params) => listSedes(clienteId, { ...params, sort: 'nombre', order: 'asc' }).then(pagina)}
                getLabel={(item) => item.nombre}
                onChange={(value, item) => { setSedeId(value); setSedeLabel(item?.nombre || ''); }}
              />
              <SearchSelect
                label="Flota"
                value={flotaId}
                selectedLabel={flotaLabel}
                placeholder="Todas las flotas"
                emptyLabel="Todas"
                scopeKey={`dash-flota-${clienteId}`}
                fetchPage={(params) => listFlotas(clienteId, { ...params, sort: 'nombre', order: 'asc' }).then(pagina)}
                getLabel={(item) => item.nombre}
                onChange={(value, item) => { setFlotaId(value); setFlotaLabel(item?.nombre || ''); }}
              />
            </>
          ) : null}
        </div>
      ) : null}

      {modo === 'cliente' && (datos?.clientes_permitidos?.length ?? 0) > 1 ? (
        <label>Cliente
          <select value={clienteId} onChange={(event) => setClienteId(event.target.value)}>
            <option value="">Todos los autorizados</option>
            {datos.clientes_permitidos.map((cliente) => <option key={cliente.id} value={cliente.id}>{cliente.nombre}</option>)}
          </select>
        </label>
      ) : null}

      {cargando ? <p>Cargando tablero…</p> : null}
      {error ? <p className="form-error" role="alert">{error}</p> : null}

      {datos?.cliente ? (
        <article className="panel">
          <h2>{datos.cliente.nombre_comercial || datos.cliente.razon_social}</h2>
          <p>{datos.cliente.razon_social}</p>
          <p>Documento {datos.cliente.documento || '—'} · Estado {datos.cliente.estado}</p>
        </article>
      ) : null}

      {datos && modo === 'comercial' ? (
        <div className="kpi-grid">
          <Kpi titulo="Oportunidades abiertas" valor={datos.resumen.abiertas} />
          <Kpi titulo="En seguimiento" valor={datos.resumen.en_seguimiento} />
          <Kpi titulo="Cotizadas" valor={datos.resumen.cotizadas} />
          <Kpi titulo="Ganadas" valor={datos.resumen.ganadas} />
          <Kpi titulo="Cotizaciones pendientes" valor={datos.resumen.cotizaciones_pendientes} detalle={datos.resumen.proximo_seguimiento ? `Próximo seguimiento ${fechaVisible(datos.resumen.proximo_seguimiento)}` : 'Sin próximo seguimiento'} />
        </div>
      ) : null}

      {datos && modo !== 'comercial' ? (
        <>
          <div className="kpi-grid">
            {datos.resumen.clientes_activos !== null && datos.resumen.clientes_activos !== undefined ? <Kpi titulo="Clientes activos" valor={datos.resumen.clientes_activos} /> : null}
            <Kpi titulo="Unidades operativas" valor={datos.resumen.unidades_operativas} />
            <Kpi titulo="Neumáticos operativos" valor={neumaticos.total_operativos ?? 0} />
            <Kpi titulo="Montados" valor={neumaticos.MONTADO ?? 0} />
            <Kpi titulo="Disponibles" valor={neumaticos.DISPONIBLE ?? 0} />
            <Kpi titulo="En mantenimiento" valor={neumaticos.EN_MANTENIMIENTO ?? 0} />
            <Kpi titulo="En reencauche" valor={neumaticos.EN_REENCAUCHE ?? 0} />
            <Kpi titulo="Descartados" valor={neumaticos.DESCARTADO ?? 0} />
            <Kpi titulo="Alertas abiertas" valor={alertas.ABIERTA ?? 0} />
            <Kpi titulo="En atención" valor={alertas.EN_ATENCION ?? 0} />
            <Kpi titulo="Críticas activas" valor={alertas.criticas_activas ?? 0} />
          </div>
          <div className="grid">
            <Barras titulo="Estado de neumáticos" items={[
              { etiqueta: 'Disponible', valor: neumaticos.DISPONIBLE ?? 0 },
              { etiqueta: 'Montado', valor: neumaticos.MONTADO ?? 0 },
              { etiqueta: 'Mantenimiento', valor: neumaticos.EN_MANTENIMIENTO ?? 0 },
              { etiqueta: 'Reencauche', valor: neumaticos.EN_REENCAUCHE ?? 0 },
              { etiqueta: 'Descartado', valor: neumaticos.DESCARTADO ?? 0 },
            ]} />
            <Barras titulo="Vidas" items={[
              { etiqueta: 'Vida 1', valor: vidas.vida_1 ?? 0 },
              { etiqueta: 'Vida 2', valor: vidas.vida_2 ?? 0 },
              { etiqueta: 'Vida 3 o más', valor: vidas.vida_3_mas ?? 0 },
            ]} />
            <Barras titulo="Criticidad" items={[
              { etiqueta: 'Normal', valor: criticidad.NORMAL ?? 0 },
              { etiqueta: 'Atención', valor: criticidad.ATENCION ?? 0 },
              { etiqueta: 'Crítica', valor: criticidad.CRITICA ?? 0 },
            ]} />
            <article className="panel">
              <h2>Inspecciones y mantenimiento</h2>
              <div className="summary-grid">
                <p><strong>Finalizadas 30 días</strong><span>{inspecciones.finalizadas_30_dias ?? 0}</span></p>
                <p><strong>Detalles críticos</strong><span>{inspecciones.criticos_30_dias ?? 0}</span></p>
                {!tecnico ? <p><strong>Solicitados</strong><span>{mantenimiento.SOLICITADO ?? 0}</span></p> : null}
                {!tecnico ? <p><strong>Enviados</strong><span>{mantenimiento.ENVIADO ?? 0}</span></p> : null}
                {!tecnico ? <p><strong>En proceso</strong><span>{mantenimiento.EN_PROCESO ?? 0}</span></p> : null}
              </div>
              <p className="lede">El rendimiento kilométrico no se proyecta: {datos.rendimiento_km?.motivo || 'sin base suficiente.'}</p>
            </article>
          </div>
          <div className="grid">
            <Lista titulo="Alertas críticas" vacio="No hay alertas críticas activas." enlace={resumido ? null : { to: '/alertas?nivel=CRITICA', label: 'Ver todas' }}>
              {(listas.alertas_criticas ?? []).map((item) => (
                <li key={item.id}>
                  {resumido ? <strong>{item.titulo}</strong> : <Link to={`/alertas/${item.id}`}><strong>{item.titulo}</strong></Link>}
                  <span>{item.cliente} · {item.unidad || item.neumatico || 'Sin unidad'} · {fechaVisible(item.fecha)}</span>
                </li>
              ))}
            </Lista>
            <Lista titulo="Inspecciones recientes" vacio="No hay inspecciones finalizadas." enlace={resumido ? null : { to: '/inspecciones', label: 'Ver todas' }}>
              {(listas.inspecciones_recientes ?? []).map((item) => (
                <li key={item.id}>
                  {resumido ? <strong>{item.unidad}</strong> : <Link to={`/inspecciones/${item.id}`}><strong>{item.unidad}</strong></Link>}
                  <span>{fechaVisible(item.fecha)} · {item.tecnico}</span>
                </li>
              ))}
            </Lista>
            {!tecnico ? (
              <Lista titulo="Mantenimientos activos" vacio="No hay mantenimientos activos." enlace={resumido ? null : { to: '/mantenimientos', label: 'Ver todos' }}>
                {(listas.mantenimientos_activos ?? []).map((item) => (
                  <li key={item.id}>
                    {resumido ? <strong>{item.neumatico}</strong> : <Link to={`/mantenimientos/${item.id}`}><strong>{item.neumatico}</strong></Link>}
                    <span>{item.tipo} · {item.estado} · {fechaVisible(item.fecha)}</span>
                  </li>
                ))}
              </Lista>
            ) : null}
            <Lista titulo="Neumáticos críticos" vacio="No hay neumáticos con criticidad crítica." enlace={resumido ? null : { to: '/neumaticos', label: 'Ver todos' }}>
              {(listas.neumaticos_criticos ?? []).map((item) => (
                <li key={item.id}>
                  {resumido ? <strong>{item.codigo}</strong> : <Link to={`/neumaticos/${item.id}`}><strong>{item.codigo}</strong></Link>}
                  <span>{item.cliente} · {item.estado}</span>
                </li>
              ))}
            </Lista>
          </div>
          {(datos.clientes_actividad ?? []).length ? (
            <article className="panel">
              <div className="row-between"><h2>Clientes con más actividad</h2><Link to="/clientes">Ver todos</Link></div>
              <div className="table-wrap">
                <table className="data-table">
                  <thead><tr><th>Cliente</th><th>Unidades</th><th>Neumáticos</th><th>Críticas</th></tr></thead>
                  <tbody>
                    {datos.clientes_actividad.map((item) => (
                      <tr key={item.id}>
                        <td data-label="Cliente"><Link to={`/clientes/${item.id}`}>{item.cliente}</Link></td>
                        <td data-label="Unidades">{item.unidades}</td>
                        <td data-label="Neumáticos">{item.neumaticos}</td>
                        <td data-label="Críticas">{item.criticas}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </article>
          ) : null}
        </>
      ) : null}

      {datos && modo === 'comercial' ? (
        <p><Link to="/comercial/oportunidades">Ver oportunidades</Link> · <Link to="/comercial/cotizaciones">Ver cotizaciones</Link> · <Link to="/comercial/seguimientos">Ver seguimientos</Link></p>
      ) : null}
    </section>
  );
}
