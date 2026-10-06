import { useState } from 'react';
import { listClientes, listFlotas, listSedes } from '../../api/clientes';
import { pagina } from '../../api/inspecciones';
import { asPage, listMarcas, listMedidas, listModelos, listNeumaticos } from '../../api/neumaticos';
import { consultarReporte, descargarReporte } from '../../api/reportes';
import { listUnidades } from '../../api/unidades';
import { useAuth } from '../../auth/AuthContext';
import { Pager } from '../../components/Pager';
import { SearchSelect } from '../../components/SearchSelect';
import { canSeeReportes, hasRole } from '../../utils/access';
import { fechaVisible } from '../../utils/fechas';

const CATALOGO = [
  ['inventario-neumaticos', 'Inventario de neumáticos', 'tecnico'],
  ['estado-neumaticos', 'Estado actual', 'tecnico'],
  ['movimientos', 'Movimientos', 'gestion'],
  ['inspecciones', 'Inspecciones', 'tecnico'],
  ['alertas', 'Alertas', 'gestion'],
  ['mantenimientos', 'Mantenimientos', 'gestion'],
  ['vidas', 'Vidas y reencauches', 'gestion'],
  ['descartes', 'Descartes', 'gestion'],
];

const ESTADOS_NEUMATICO = ['DISPONIBLE', 'MONTADO', 'EN_MANTENIMIENTO', 'EN_REENCAUCHE', 'DESCARTADO'];
const FECHAS = new Set(['fecha', 'solicitud', 'envio', 'retorno', 'fecha_inicio', 'fecha_fin', 'ultima_inspeccion']);

function visible(user, grupo) {
  if (hasRole(user, 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'ADMIN_CLIENTE', 'CONSULTA_EJECUTIVA')) return true;
  return grupo === 'tecnico' && hasRole(user, 'TECNICO_INSPECCION');
}

export function ReportesPage() {
  const { user } = useAuth();
  const admin = hasRole(user, 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'ADMIN_CLIENTE', 'CONSULTA_EJECUTIVA', 'TECNICO_INSPECCION');
  const tipos = CATALOGO.filter((item) => visible(user, item[2]));
  const [tipo, setTipo] = useState(tipos[0]?.[0] || 'inventario-neumaticos');
  const [clienteId, setClienteId] = useState('');
  const [clienteLabel, setClienteLabel] = useState('');
  const [sedeId, setSedeId] = useState('');
  const [sedeLabel, setSedeLabel] = useState('');
  const [flotaId, setFlotaId] = useState('');
  const [flotaLabel, setFlotaLabel] = useState('');
  const [unidadId, setUnidadId] = useState('');
  const [unidadLabel, setUnidadLabel] = useState('');
  const [neumaticoId, setNeumaticoId] = useState('');
  const [neumaticoLabel, setNeumaticoLabel] = useState('');
  const [marcaId, setMarcaId] = useState('');
  const [marcaLabel, setMarcaLabel] = useState('');
  const [modeloId, setModeloId] = useState('');
  const [modeloLabel, setModeloLabel] = useState('');
  const [medidaId, setMedidaId] = useState('');
  const [medidaLabel, setMedidaLabel] = useState('');
  const [estado, setEstado] = useState('');
  const [nivel, setNivel] = useState('');
  const [fechaInicio, setFechaInicio] = useState('');
  const [fechaFin, setFechaFin] = useState('');
  const [page, setPage] = useState(1);
  const [reporte, setReporte] = useState(null);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState('');
  const [cargando, setCargando] = useState(false);
  const [exportando, setExportando] = useState('');

  function filtros() {
    return {
      cliente_id: clienteId,
      sede_id: sedeId,
      flota_id: flotaId,
      unidad_id: unidadId,
      neumatico_id: neumaticoId,
      marca_id: marcaId,
      modelo_id: modeloId,
      medida_id: medidaId,
      estado,
      nivel,
      fecha_inicio: fechaInicio,
      fecha_fin: fechaFin,
    };
  }

  async function consultar(paginaActual = 1) {
    setCargando(true);
    setError('');
    const result = await consultarReporte(tipo, { ...filtros(), page: paginaActual, limit: 20 });
    setCargando(false);
    if (!result.ok) {
      setReporte(null);
      setError(result.payload?.err?.message || 'No se pudo consultar el reporte.');
      return;
    }
    setReporte(result.payload.data);
    setTotal(result.payload.total ?? 0);
    setPage(paginaActual);
  }

  async function exportar(formato) {
    setExportando(formato);
    setError('');
    const result = await descargarReporte(tipo, formato, filtros());
    setExportando('');
    if (!result.ok) setError('No se pudo exportar el reporte con los filtros actuales.');
  }

  const columnas = reporte?.columnas ?? [];
  const filas = reporte?.filas ?? [];
  if (!canSeeReportes(user)) {
    return <section><p className="form-error">Los reportes técnicos no están disponibles para este rol.</p></section>;
  }

  return (
    <section>
      <div className="page-heading">
        <p className="eyebrow">Consulta</p>
        <h1>Reportes</h1>
        <p className="lede">La exportación usa los mismos filtros y el alcance del usuario. El servidor no toma el total ni el cliente desde el archivo.</p>
      </div>
      <div className="filter-row">
        <label>Reporte
          <select value={tipo} onChange={(event) => { setTipo(event.target.value); setReporte(null); setPage(1); }}>
            {tipos.map(([codigo, nombre]) => <option key={codigo} value={codigo}>{nombre}</option>)}
          </select>
        </label>
        {admin ? (
          <SearchSelect label="Cliente" value={clienteId} selectedLabel={clienteLabel} placeholder="Todos" emptyLabel="Todos" scopeKey="rep-cliente" fetchPage={(params) => listClientes({ ...params, sort: 'razon_social', order: 'asc' }).then(pagina)} getLabel={(item) => item.nombre_comercial || item.razon_social} onChange={(value, item) => { setClienteId(value); setClienteLabel(item ? (item.nombre_comercial || item.razon_social) : ''); setSedeId(''); setFlotaId(''); setUnidadId(''); setNeumaticoId(''); }} />
        ) : null}
        {clienteId ? <SearchSelect label="Sede" value={sedeId} selectedLabel={sedeLabel} placeholder="Todas" emptyLabel="Todas" scopeKey={`rep-sede-${clienteId}`} fetchPage={(params) => listSedes(clienteId, params).then(pagina)} getLabel={(item) => item.nombre} onChange={(value, item) => { setSedeId(value); setSedeLabel(item?.nombre || ''); }} /> : null}
        {clienteId ? <SearchSelect label="Flota" value={flotaId} selectedLabel={flotaLabel} placeholder="Todas" emptyLabel="Todas" scopeKey={`rep-flota-${clienteId}`} fetchPage={(params) => listFlotas(clienteId, params).then(pagina)} getLabel={(item) => item.nombre} onChange={(value, item) => { setFlotaId(value); setFlotaLabel(item?.nombre || ''); }} /> : null}
        <SearchSelect label="Unidad" value={unidadId} selectedLabel={unidadLabel} placeholder="Todas" emptyLabel="Todas" scopeKey={`rep-unidad-${clienteId}`} fetchPage={(params) => listUnidades({ ...params, cliente_id: clienteId, sort: 'codigo', order: 'asc' }).then(pagina)} getLabel={(item) => item.codigo} onChange={(value, item) => { setUnidadId(value); setUnidadLabel(item?.codigo || ''); }} />
        <SearchSelect label="Neumático" value={neumaticoId} selectedLabel={neumaticoLabel} placeholder="Todos" emptyLabel="Todos" scopeKey={`rep-neu-${clienteId}`} fetchPage={(params) => listNeumaticos({ ...params, cliente_id: clienteId, sort: 'codigo', order: 'asc' }).then(pagina)} getLabel={(item) => item.codigo} onChange={(value, item) => { setNeumaticoId(value); setNeumaticoLabel(item?.codigo || ''); }} />
        {tipo.startsWith('inventario') || tipo.startsWith('estado') ? (
          <>
            <SearchSelect label="Marca" value={marcaId} selectedLabel={marcaLabel} placeholder="Todas" emptyLabel="Todas" scopeKey="rep-marca" fetchPage={(params) => listMarcas({ ...params, activo: 1, sort: 'nombre', order: 'asc' }).then(asPage)} getLabel={(item) => item.nombre} onChange={(value, item) => { setMarcaId(value); setMarcaLabel(item?.nombre || ''); }} />
            <SearchSelect label="Modelo" value={modeloId} selectedLabel={modeloLabel} placeholder="Todos" emptyLabel="Todos" scopeKey="rep-modelo" fetchPage={(params) => listModelos({ ...params, activo: 1, sort: 'nombre', order: 'asc' }).then(asPage)} getLabel={(item) => item.nombre} onChange={(value, item) => { setModeloId(value); setModeloLabel(item?.nombre || ''); }} />
            <SearchSelect label="Medida" value={medidaId} selectedLabel={medidaLabel} placeholder="Todas" emptyLabel="Todas" scopeKey="rep-medida" fetchPage={(params) => listMedidas({ ...params, activo: 1, sort: 'descripcion', order: 'asc' }).then(asPage)} getLabel={(item) => item.descripcion} onChange={(value, item) => { setMedidaId(value); setMedidaLabel(item?.descripcion || ''); }} />
            <label>Estado
              <select value={estado} onChange={(event) => setEstado(event.target.value)}>
                <option value="">Todos</option>
                {ESTADOS_NEUMATICO.map((codigo) => <option key={codigo} value={codigo}>{codigo}</option>)}
              </select>
            </label>
          </>
        ) : null}
        {tipo === 'alertas' ? (
          <label>Nivel
            <select value={nivel} onChange={(event) => setNivel(event.target.value)}>
              <option value="">Todos</option>
              <option value="INFORMATIVA">Informativa</option>
              <option value="ATENCION">Atención</option>
              <option value="CRITICA">Crítica</option>
            </select>
          </label>
        ) : null}
        <label>Desde <input type="date" value={fechaInicio} onChange={(event) => setFechaInicio(event.target.value)} /></label>
        <label>Hasta <input type="date" value={fechaFin} onChange={(event) => setFechaFin(event.target.value)} /></label>
      </div>
      <div className="actions">
        <button type="button" className="button button-primary" disabled={cargando} onClick={() => consultar(1)}>{cargando ? 'Consultando…' : 'Consultar'}</button>
        <button type="button" className="button button-quiet" disabled={exportando !== ''} onClick={() => exportar('pdf')}>{exportando === 'pdf' ? 'Generando…' : 'Exportar PDF'}</button>
        <button type="button" className="button button-quiet" disabled={exportando !== ''} onClick={() => exportar('csv')}>{exportando === 'csv' ? 'Generando…' : 'Exportar CSV'}</button>
      </div>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      {reporte ? (
        <>
          <h2>{reporte.titulo}</h2>
          {filas.length === 0 ? <p>No hay filas para los filtros indicados.</p> : (
            <div className="table-wrap">
              <table className="data-table">
                <thead><tr>{columnas.map((columna) => <th key={columna.key}>{columna.label}</th>)}</tr></thead>
                <tbody>
                  {filas.map((fila, indice) => (
                    <tr key={indice}>
                      {columnas.map((columna) => {
                        const valor = fila[columna.key];
                        const texto = valor === null || valor === '' ? 'No disponible' : (FECHAS.has(columna.key) ? fechaVisible(valor) : String(valor));
                        return <td key={columna.key} data-label={columna.label}>{texto}</td>;
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {(reporte.totales_por_moneda ?? []).length ? (
            <p>Totales por moneda: {reporte.totales_por_moneda.map((item) => `${item.total} ${item.moneda}`).join(' · ')}</p>
          ) : null}
          <Pager page={page} total={total} limit={20} onPage={consultar} />
        </>
      ) : null}
    </section>
  );
}
