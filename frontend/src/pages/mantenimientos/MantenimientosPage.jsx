import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { listClientes, errorMessage } from '../../api/clientes';
import { pagina } from '../../api/inspecciones';
import { listMantenimientos } from '../../api/mantenimientos';
import { useAuth } from '../../auth/AuthContext';
import { EstadoBadge } from '../../components/EstadoBadge';
import { Pager } from '../../components/Pager';
import { SearchSelect } from '../../components/SearchSelect';
import { canManageNeumaticos, isAdmin } from '../../utils/access';
import { costoTexto, lectura } from './texto';

const EMPTY = { search: '', estado: '', tipo: '', cliente_id: '', fecha_inicio: '', fecha_fin: '' };
const TIPOS = [
  ['REPARACION', 'Reparación'],
  ['REENCAUCHE', 'Reencauche'],
  ['REGRABADO', 'Regrabado'],
  ['OTRO', 'Otro'],
];
const ESTADOS = [
  ['SOLICITADO', 'Solicitado'],
  ['ENVIADO', 'Enviado'],
  ['EN_PROCESO', 'En proceso'],
  ['FINALIZADO', 'Finalizado'],
  ['CANCELADO', 'Cancelado'],
];

export function MantenimientosPage() {
  const { user } = useAuth();
  const admin = isAdmin(user);
  const propios = user?.clientes ?? [];
  const [params] = useSearchParams();
  const inicial = { ...EMPTY, estado: params.get('estado') || '' };
  const [draft, setDraft] = useState(inicial);
  const [clienteLabel, setClienteLabel] = useState('');
  const [filters, setFilters] = useState(inicial);
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!admin && propios.length === 1 && !draft.cliente_id) {
      setDraft((current) => ({ ...current, cliente_id: String(propios[0].id) }));
    }
  }, [admin, propios, draft.cliente_id]);

  useEffect(() => {
    let cancelled = false;
    listMantenimientos({ ...filters, page, limit: 10, sort: 'fecha_solicitud', order: 'desc' }).then((result) => {
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
          <h1>Mantenimiento</h1>
          <p className="lede">Reparación, reencauche y regrabado conservan la identidad del neumático durante todo el ciclo.</p>
        </div>
        {canManageNeumaticos(user) ? <Link className="button button-primary" to="/mantenimientos/nuevo">Nuevo mantenimiento</Link> : null}
      </div>
      <form className="filters" onSubmit={(event) => { event.preventDefault(); setPage(1); setFilters(draft); }}>
        <label>Buscar
          <input name="search" value={draft.search} placeholder="Neumático o tercero" onChange={(event) => setDraft({ ...draft, search: event.target.value })} />
        </label>
        {!admin && propios.length > 1 ? (
          <label>Cliente
            <select name="cliente_id" value={draft.cliente_id} onChange={(event) => setDraft({ ...draft, cliente_id: event.target.value })}>
              <option value="">Todos</option>
              {propios.map((item) => <option key={item.id} value={item.id}>{item.nombre_comercial || item.razon_social}</option>)}
            </select>
          </label>
        ) : null}
        <label>Tipo
          <select name="tipo" value={draft.tipo} onChange={(event) => setDraft({ ...draft, tipo: event.target.value })}>
            <option value="">Todos</option>
            {TIPOS.map(([codigo, nombre]) => <option key={codigo} value={codigo}>{nombre}</option>)}
          </select>
        </label>
        <label>Estado
          <select name="estado" value={draft.estado} onChange={(event) => setDraft({ ...draft, estado: event.target.value })}>
            <option value="">Todos</option>
            {ESTADOS.map(([codigo, nombre]) => <option key={codigo} value={codigo}>{nombre}</option>)}
          </select>
        </label>
        <label>Desde
          <input type="date" value={draft.fecha_inicio} onChange={(event) => setDraft({ ...draft, fecha_inicio: event.target.value })} />
        </label>
        <label>Hasta
          <input type="date" value={draft.fecha_fin} onChange={(event) => setDraft({ ...draft, fecha_fin: event.target.value })} />
        </label>
        <button className="button button-primary" type="submit">Buscar</button>
      </form>
      {admin ? (
        <SearchSelect
          label="Cliente"
          value={draft.cliente_id}
          selectedLabel={clienteLabel}
          placeholder="Buscar cliente"
          emptyLabel="Todos los clientes"
          scopeKey="clientes"
          fetchPage={(params) => listClientes({ ...params, sort: 'razon_social', order: 'asc' }).then(pagina)}
          getLabel={(item) => item.nombre_comercial || item.razon_social}
          onChange={(id, item) => {
            setDraft({ ...draft, cliente_id: id });
            setClienteLabel(item ? (item.nombre_comercial || item.razon_social) : '');
          }}
        />
      ) : null}
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              <th>Neumático</th><th>Cliente</th><th>Tipo</th><th>Estado</th><th>Solicitud</th><th>Envío</th><th>Retorno</th><th>Tercero</th><th>Costo</th><th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? <tr><td colSpan={10}>No hay mantenimientos para este criterio.</td></tr> : rows.map((row) => (
              <tr key={row.id}>
                <td data-label="Neumático"><Link to={`/neumaticos/${row.neumatico.id}`}>{row.neumatico.codigo}</Link></td>
                <td data-label="Cliente">{row.cliente.nombre_comercial || row.cliente.razon_social}</td>
                <td data-label="Tipo">{row.tipo.nombre}</td>
                <td data-label="Estado"><EstadoBadge estado={row.estado.codigo} /></td>
                <td data-label="Solicitud">{lectura(row.fecha_solicitud)}</td>
                <td data-label="Envío">{lectura(row.fecha_envio)}</td>
                <td data-label="Retorno">{lectura(row.fecha_retorno)}</td>
                <td data-label="Tercero">{row.tercero_nombre || '—'}</td>
                <td data-label="Costo">{costoTexto(row.costo, row.moneda)}</td>
                <td data-label="Acciones"><Link to={`/mantenimientos/${row.id}`}>Abrir</Link></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Pager page={page} limit={10} total={total} onPage={setPage} />
    </section>
  );
}
