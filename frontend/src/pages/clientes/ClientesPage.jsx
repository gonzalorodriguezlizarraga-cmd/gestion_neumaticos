import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { errorMessage, listClientes } from '../../api/clientes';
import { EstadoBadge } from '../../components/EstadoBadge';
import { Pager } from '../../components/Pager';
import { useAuth } from '../../auth/AuthContext';
import { isAdmin } from '../../utils/access';

const EMPTY = { search: '', estado: '', sort: 'razon_social', order: 'asc' };

export function ClientesPage() {
  const { user } = useAuth();
  const [filters, setFilters] = useState(EMPTY);
  const [draft, setDraft] = useState(EMPTY);
  const [page, setPage] = useState(1);
  const [limit, setLimit] = useState(10);
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    listClientes({ ...filters, page, limit })
      .then((result) => {
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
      })
      .catch(() => {
        if (!cancelled) setError('No se pudo contactar el servidor.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [filters, page, limit]);

  function onSearch(event) {
    event.preventDefault();
    setPage(1);
    setFilters(draft);
  }

  return (
    <section>
      <div className="page-heading row-between">
        <div>
          <p className="eyebrow">Organización</p>
          <h1>Clientes</h1>
          <p className="lede">Empresas del servicio, con sus contactos, responsables, sedes y flotas.</p>
        </div>
        {isAdmin(user) ? <Link className="button button-primary" to="/clientes/nuevo">Nuevo cliente</Link> : null}
      </div>

      <form className="filters" onSubmit={onSearch}>
        <label>
          Buscar
          <input
            name="search"
            value={draft.search}
            placeholder="Razón social, nombre o documento"
            onChange={(event) => setDraft({ ...draft, search: event.target.value })}
          />
        </label>
        <label>
          Estado
          <select name="estado" value={draft.estado} onChange={(event) => setDraft({ ...draft, estado: event.target.value })}>
            <option value="">Todos</option>
            <option value="POTENCIAL">Potencial</option>
            <option value="ACTIVO">Activo</option>
            <option value="INACTIVO">Inactivo</option>
          </select>
        </label>
        <label>
          Orden
          <select name="sort" value={draft.sort} onChange={(event) => setDraft({ ...draft, sort: event.target.value })}>
            <option value="razon_social">Razón social</option>
            <option value="nombre_comercial">Nombre comercial</option>
            <option value="estado">Estado</option>
          </select>
        </label>
        <label>
          Dirección
          <select name="order" value={draft.order} onChange={(event) => setDraft({ ...draft, order: event.target.value })}>
            <option value="asc">Ascendente</option>
            <option value="desc">Descendente</option>
          </select>
        </label>
        <label>
          Por página
          <select name="limit" value={limit} onChange={(event) => { setLimit(Number(event.target.value)); setPage(1); }}>
            <option value={1}>1</option>
            <option value={10}>10</option>
            <option value={20}>20</option>
          </select>
        </label>
        <button className="button button-primary" type="submit">Buscar</button>
      </form>

      {error ? <p className="form-error" role="alert">{error}</p> : null}
      {loading ? <p>Cargando clientes…</p> : null}

      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              <th>Razón social</th>
              <th>Nombre comercial</th>
              <th>RUC/documento</th>
              <th>Estado</th>
              <th>Teléfono</th>
              <th>Email</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && !loading ? (
              <tr><td colSpan={7}>No hay clientes para este criterio.</td></tr>
            ) : rows.map((row) => (
              <tr key={row.id}>
                <td data-label="Razón social">{row.razon_social}</td>
                <td data-label="Nombre comercial">{row.nombre_comercial || '—'}</td>
                <td data-label="RUC/documento">{row.ruc_documento || '—'}</td>
                <td data-label="Estado"><EstadoBadge estado={row.estado} /></td>
                <td data-label="Teléfono">{row.telefono || '—'}</td>
                <td data-label="Email">{row.email || '—'}</td>
                <td data-label="Acciones"><Link to={`/clientes/${row.id}`}>Ver ficha</Link></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Pager page={page} limit={limit} total={total} onPage={setPage} />
    </section>
  );
}
