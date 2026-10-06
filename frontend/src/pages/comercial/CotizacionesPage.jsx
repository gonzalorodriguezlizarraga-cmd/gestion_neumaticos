import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { listClientesComerciales, listCotizaciones } from '../../api/comercial';
import { pagina } from '../../api/inspecciones';
import { useAuth } from '../../auth/AuthContext';
import { EstadoBadge } from '../../components/EstadoBadge';
import { Pager } from '../../components/Pager';
import { SearchSelect } from '../../components/SearchSelect';
import { canWriteComercial } from '../../utils/access';
import { clienteNombre, dinero, ESTADOS_COTIZACION, mensaje } from './texto';

const EMPTY = { search: '', cliente_id: '', estado: '' };

export function CotizacionesPage() {
  const { user } = useAuth();
  const [draft, setDraft] = useState(EMPTY);
  const [clienteLabel, setClienteLabel] = useState('');
  const [filters, setFilters] = useState(EMPTY);
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    listCotizaciones({ ...filters, page, limit: 20, sort: 'fecha', order: 'desc' }).then((result) => {
      if (cancelled) return;
      if (!result.ok) {
        setError(mensaje(result));
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
          <p className="eyebrow">Comercial</p>
          <h1>Cotizaciones</h1>
          <p className="lede">El total lo calcula el sistema. Aceptar una cotización cierra la oportunidad como ganada y no genera un pedido.</p>
        </div>
        {canWriteComercial(user) ? <Link className="button button-primary" to="/comercial/cotizaciones/nueva">Nueva cotización</Link> : null}
      </div>
      <form className="filters" onSubmit={(event) => { event.preventDefault(); setPage(1); setFilters(draft); }}>
        <label>Buscar
          <input value={draft.search} placeholder="Número o cliente" onChange={(event) => setDraft({ ...draft, search: event.target.value })} />
        </label>
        <SearchSelect
          label="Cliente"
          value={draft.cliente_id}
          selectedLabel={clienteLabel}
          placeholder="Buscar cliente"
          emptyLabel="Todos"
          scopeKey="cotizaciones-cliente"
          fetchPage={(params) => listClientesComerciales({ ...params, sort: 'razon_social', order: 'asc' }).then(pagina)}
          getLabel={(item) => item.nombre_comercial || item.razon_social}
          onChange={(value, item) => { setDraft({ ...draft, cliente_id: value }); setClienteLabel(item ? (item.nombre_comercial || item.razon_social) : ''); }}
        />
        <label>Estado
          <select value={draft.estado} onChange={(event) => setDraft({ ...draft, estado: event.target.value })}>
            <option value="">Todos</option>
            {ESTADOS_COTIZACION.map(([codigo, etiqueta]) => <option key={codigo} value={codigo}>{etiqueta}</option>)}
          </select>
        </label>
        <button className="button button-primary" type="submit">Filtrar</button>
      </form>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr><th>Número</th><th>Fecha</th><th>Cliente</th><th>Oportunidad</th><th>Estado</th><th>Moneda</th><th>Total</th><th>Acciones</th></tr>
          </thead>
          <tbody>
            {rows.length === 0 ? <tr><td colSpan={8}>No hay cotizaciones.</td></tr> : rows.map((row) => (
              <tr key={row.id}>
                <td data-label="Número">{row.numero}</td>
                <td data-label="Fecha">{row.fecha}</td>
                <td data-label="Cliente">{clienteNombre(row.cliente)}</td>
                <td data-label="Oportunidad">{row.oportunidad ? row.oportunidad.titulo : '—'}</td>
                <td data-label="Estado"><EstadoBadge estado={row.estado} /></td>
                <td data-label="Moneda">{row.moneda}</td>
                <td data-label="Total">{dinero(row.total)}</td>
                <td data-label="Acciones"><Link to={`/comercial/cotizaciones/${row.id}`}>Abrir</Link></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Pager page={page} total={total} limit={20} onPage={setPage} />
    </section>
  );
}
