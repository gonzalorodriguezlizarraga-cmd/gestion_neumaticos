import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { errorMessage, listClientes, listFlotas, listSedes } from '../../api/clientes';
import { listTipos, listUnidades } from '../../api/unidades';
import { EstadoBadge } from '../../components/EstadoBadge';
import { Pager } from '../../components/Pager';
import { useAuth } from '../../auth/AuthContext';
import { canManageUnidades, isAdmin } from '../../utils/access';

const EMPTY = { search: '', estado: '', cliente_id: '', sede_id: '', flota_id: '', tipo_unidad_id: '' };

export function UnidadesPage() {
  const { user } = useAuth();
  const admin = isAdmin(user);
  const propios = user?.clientes ?? [];
  const [draft, setDraft] = useState(EMPTY);
  const [filters, setFilters] = useState(EMPTY);
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [tipos, setTipos] = useState([]);
  const [sedes, setSedes] = useState([]);
  const [flotas, setFlotas] = useState([]);
  const [error, setError] = useState('');

  useEffect(() => {
    listTipos({ limit: 100, sort: 'nombre', order: 'asc' }).then((result) => {
      if (result.ok) setTipos(result.payload?.data ?? []);
    });
  }, []);

  useEffect(() => {
    if (!admin && propios.length === 1 && !draft.cliente_id) {
      setDraft((current) => ({ ...current, cliente_id: String(propios[0].id) }));
    }
  }, [admin, propios, draft.cliente_id]);

  useEffect(() => {
    if (!draft.cliente_id) {
      setSedes([]);
      setFlotas([]);
      return;
    }
    listSedes(draft.cliente_id, { limit: 100, sort: 'nombre' }).then((result) => setSedes(result.ok ? result.payload?.data ?? [] : []));
    listFlotas(draft.cliente_id, { limit: 100, sort: 'nombre' }).then((result) => setFlotas(result.ok ? result.payload?.data ?? [] : []));
  }, [draft.cliente_id]);

  useEffect(() => {
    let cancelled = false;
    listUnidades({ ...filters, page, limit: 10, sort: 'codigo', order: 'asc' }).then((result) => {
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
          <h1>Unidades</h1>
          <p className="lede">Vehículos y equipos del cliente. La sede y la flota son opcionales.</p>
        </div>
        {canManageUnidades(user) ? <Link className="button button-primary" to="/unidades/nueva">Nueva unidad</Link> : null}
      </div>
      <form className="filters" onSubmit={(event) => { event.preventDefault(); setPage(1); setFilters(draft); }}>
        <label>Buscar<input name="search" value={draft.search} placeholder="Código, placa, marca o serie" onChange={(event) => setDraft({ ...draft, search: event.target.value })} /></label>
        {!admin && propios.length > 1 ? (
          <label>Cliente
            <select name="cliente_id" value={draft.cliente_id} onChange={(event) => setDraft({ ...draft, cliente_id: event.target.value, sede_id: '', flota_id: '' })}>
              <option value="">Todos</option>
              {propios.map((item) => <option key={item.id} value={item.id}>{item.nombre_comercial || item.razon_social}</option>)}
            </select>
          </label>
        ) : null}
        <label>Sede
          <select name="sede_id" value={draft.sede_id} onChange={(event) => setDraft({ ...draft, sede_id: event.target.value })}>
            <option value="">Todas</option>
            {sedes.map((item) => <option key={item.id} value={item.id}>{item.nombre}</option>)}
          </select>
        </label>
        <label>Flota
          <select name="flota_id" value={draft.flota_id} onChange={(event) => setDraft({ ...draft, flota_id: event.target.value })}>
            <option value="">Todas</option>
            {flotas.map((item) => <option key={item.id} value={item.id}>{item.nombre}</option>)}
          </select>
        </label>
        <label>Tipo
          <select name="tipo_unidad_id" value={draft.tipo_unidad_id} onChange={(event) => setDraft({ ...draft, tipo_unidad_id: event.target.value })}>
            <option value="">Todos</option>
            {tipos.map((item) => <option key={item.id} value={item.id}>{item.nombre}</option>)}
          </select>
        </label>
        <label>Estado
          <select name="estado" value={draft.estado} onChange={(event) => setDraft({ ...draft, estado: event.target.value })}>
            <option value="">Todos</option>
            <option value="OPERATIVA">Operativa</option>
            <option value="INACTIVA">Inactiva</option>
            <option value="BAJA">Baja</option>
          </select>
        </label>
        <button className="button button-primary" type="submit">Buscar</button>
      </form>
      {admin ? <ClienteAdminSelect value={draft.cliente_id} onChange={(clienteId) => setDraft({ ...draft, cliente_id: clienteId, sede_id: '', flota_id: '' })} /> : null}
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr><th>Código</th><th>Placa</th><th>Cliente</th><th>Tipo</th><th>Sede/flota</th><th>Estado</th><th>Acciones</th></tr>
          </thead>
          <tbody>
            {rows.length === 0 ? <tr><td colSpan={7}>No hay unidades para este criterio.</td></tr> : rows.map((row) => (
              <tr key={row.id}>
                <td data-label="Código">{row.codigo}</td>
                <td data-label="Placa">{row.placa || '—'}</td>
                <td data-label="Cliente">{row.cliente}</td>
                <td data-label="Tipo">{row.tipo}</td>
                <td data-label="Sede/flota">{[row.sede, row.flota].filter(Boolean).join(' · ') || 'Sin sede ni flota'}</td>
                <td data-label="Estado"><EstadoBadge estado={row.estado} /></td>
                <td data-label="Acciones"><Link to={`/unidades/${row.id}`}>Ver ficha</Link></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Pager page={page} limit={10} total={total} onPage={setPage} />
    </section>
  );
}

function ClienteAdminSelect({ value, onChange }) {
  const [rows, setRows] = useState([]);
  useEffect(() => {
    listClientes({ limit: 100, sort: 'razon_social', order: 'asc' }).then((result) => {
      if (result.ok) setRows(result.payload?.data ?? []);
    });
  }, []);
  if (rows.length === 0) return null;
  return (
    <label className="field">Cliente del filtro
      <select value={value} onChange={(event) => onChange(event.target.value)}>
        <option value="">Todos los clientes</option>
        {rows.map((item) => <option key={item.id} value={item.id}>{item.nombre_comercial || item.razon_social}</option>)}
      </select>
    </label>
  );
}
