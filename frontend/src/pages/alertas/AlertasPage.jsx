import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { listClientes, errorMessage } from '../../api/clientes';
import { pagina } from '../../api/inspecciones';
import { listAlertas, listEstadosAlerta, listTiposAlerta } from '../../api/alertas';
import { useAuth } from '../../auth/AuthContext';
import { EstadoBadge } from '../../components/EstadoBadge';
import { Pager } from '../../components/Pager';
import { SearchSelect } from '../../components/SearchSelect';
import { canManageNeumaticos, isAdmin } from '../../utils/access';
import { origenTexto } from './texto';

const EMPTY = { search: '', cliente_id: '', tipo_alerta_id: '', estado_id: '', nivel: '', fecha_inicio: '', fecha_fin: '', generada_automaticamente: '' };
const NIVELES = [
  ['INFORMATIVA', 'Informativa'],
  ['ATENCION', 'Atención'],
  ['CRITICA', 'Crítica'],
];

export function AlertasPage() {
  const { user } = useAuth();
  const admin = isAdmin(user);
  const propios = user?.clientes ?? [];
  const [draft, setDraft] = useState(EMPTY);
  const [clienteLabel, setClienteLabel] = useState('');
  const [filters, setFilters] = useState(EMPTY);
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [tipos, setTipos] = useState([]);
  const [estados, setEstados] = useState([]);
  const [error, setError] = useState('');

  useEffect(() => {
    listTiposAlerta().then((result) => { if (result.ok) setTipos(result.payload?.data ?? []); });
    listEstadosAlerta().then((result) => { if (result.ok) setEstados(result.payload?.data ?? []); });
  }, []);

  useEffect(() => {
    let cancelled = false;
    listAlertas({ ...filters, page, limit: 10, sort: 'fecha_generacion', order: 'desc' }).then((result) => {
      if (cancelled) return;
      if (!result.ok) {
        setError(errorMessage(result));
        setRows([]);
        setTotal(0);
        return;
      }
      setError('');
      setRows(result.payload?.data ?? []);
      setTotal(result.payload?.total ?? 0);
    });
    return () => { cancelled = true; };
  }, [filters, page]);

  return (
    <section>
      <div className="page-heading row-between">
        <div>
          <p className="eyebrow">Operación</p>
          <h1>Alertas</h1>
          <p className="lede">Las alertas automáticas de inspección y las manuales siguen el mismo ciclo de atención.</p>
        </div>
        {canManageNeumaticos(user) ? <Link className="button button-primary" to="/alertas/nueva">Nueva alerta</Link> : null}
      </div>
      <form className="filters" onSubmit={(event) => { event.preventDefault(); setPage(1); setFilters(draft); }}>
        <label>Buscar
          <input name="search" value={draft.search} placeholder="Título, neumático o unidad" onChange={(event) => setDraft({ ...draft, search: event.target.value })} />
        </label>
        {admin ? (
          <SearchSelect
            label="Cliente"
            value={draft.cliente_id}
            selectedLabel={clienteLabel}
            placeholder="Buscar cliente"
            emptyLabel="Todos"
            scopeKey="clientes-alerta"
            fetchPage={(params) => listClientes({ ...params, sort: 'razon_social', order: 'asc' }).then(pagina)}
            getLabel={(item) => item.nombre_comercial || item.razon_social}
            onChange={(value, item) => { setDraft({ ...draft, cliente_id: value }); setClienteLabel(item ? (item.nombre_comercial || item.razon_social) : ''); }}
          />
        ) : null}
        {!admin && propios.length > 1 ? (
          <label>Cliente
            <select name="cliente_id" value={draft.cliente_id} onChange={(event) => setDraft({ ...draft, cliente_id: event.target.value })}>
              <option value="">Todos</option>
              {propios.map((cliente) => <option key={cliente.id} value={cliente.id}>{cliente.nombre_comercial || cliente.razon_social}</option>)}
            </select>
          </label>
        ) : null}
        <label>Tipo
          <select name="tipo_alerta_id" value={draft.tipo_alerta_id} onChange={(event) => setDraft({ ...draft, tipo_alerta_id: event.target.value })}>
            <option value="">Todos</option>
            {tipos.map((tipo) => <option key={tipo.id} value={tipo.id}>{tipo.nombre}</option>)}
          </select>
        </label>
        <label>Estado
          <select name="estado_id" value={draft.estado_id} onChange={(event) => setDraft({ ...draft, estado_id: event.target.value })}>
            <option value="">Todos</option>
            {estados.map((estado) => <option key={estado.id} value={estado.id}>{estado.nombre}</option>)}
          </select>
        </label>
        <label>Nivel
          <select name="nivel" value={draft.nivel} onChange={(event) => setDraft({ ...draft, nivel: event.target.value })}>
            <option value="">Todos</option>
            {NIVELES.map(([codigo, nombre]) => <option key={codigo} value={codigo}>{nombre}</option>)}
          </select>
        </label>
        <label>Origen
          <select name="generada_automaticamente" value={draft.generada_automaticamente} onChange={(event) => setDraft({ ...draft, generada_automaticamente: event.target.value })}>
            <option value="">Todos</option>
            <option value="1">Automática</option>
            <option value="0">Manual</option>
          </select>
        </label>
        <label>Desde
          <input type="date" name="fecha_inicio" value={draft.fecha_inicio} onChange={(event) => setDraft({ ...draft, fecha_inicio: event.target.value })} />
        </label>
        <label>Hasta
          <input type="date" name="fecha_fin" value={draft.fecha_fin} onChange={(event) => setDraft({ ...draft, fecha_fin: event.target.value })} />
        </label>
        <button className="button button-primary" type="submit">Buscar</button>
      </form>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              <th>Fecha</th><th>Nivel</th><th>Tipo</th><th>Cliente</th><th>Unidad</th><th>Neumático</th><th>Estado</th><th>Origen</th><th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? <tr><td colSpan={9}>No hay alertas para este criterio.</td></tr> : rows.map((row) => (
              <tr key={row.id}>
                <td data-label="Fecha">{row.fecha_generacion}</td>
                <td data-label="Nivel"><EstadoBadge estado={row.nivel} /></td>
                <td data-label="Tipo">{row.tipo.nombre}</td>
                <td data-label="Cliente">{row.cliente.nombre_comercial || row.cliente.razon_social}</td>
                <td data-label="Unidad">{row.unidad ? row.unidad.codigo : '—'}</td>
                <td data-label="Neumático">{row.neumatico ? row.neumatico.codigo : '—'}</td>
                <td data-label="Estado"><EstadoBadge estado={row.estado.codigo} /></td>
                <td data-label="Origen">{origenTexto(row.origen)}</td>
                <td data-label="Acciones"><Link to={`/alertas/${row.id}`}>Abrir</Link></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Pager page={page} limit={10} total={total} onPage={setPage} />
    </section>
  );
}
