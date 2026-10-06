import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { listClientesComerciales, listEstadosOportunidad, listOportunidades } from '../../api/comercial';
import { pagina } from '../../api/inspecciones';
import { useAuth } from '../../auth/AuthContext';
import { EstadoBadge } from '../../components/EstadoBadge';
import { Pager } from '../../components/Pager';
import { SearchSelect } from '../../components/SearchSelect';
import { canWriteComercial, isAdmin } from '../../utils/access';
import { clienteNombre, dinero, mensaje, nombre, ORIGENES, proximoTexto } from './texto';

const EMPTY = {
  search: '', cliente_id: '', estado_id: '', origen: '', moneda: '',
  fecha_inicio: '', fecha_fin: '', fecha_necesidad_inicio: '', fecha_necesidad_fin: '',
};

export function OportunidadesPage() {
  const { user } = useAuth();
  const admin = isAdmin(user);
  const [draft, setDraft] = useState(EMPTY);
  const [clienteLabel, setClienteLabel] = useState('');
  const [filters, setFilters] = useState(EMPTY);
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [estados, setEstados] = useState([]);
  const [error, setError] = useState('');

  useEffect(() => {
    listEstadosOportunidad().then((result) => { if (result.ok) setEstados(result.payload?.data ?? []); });
  }, []);

  useEffect(() => {
    let cancelled = false;
    listOportunidades({ ...filters, page, limit: 20, sort: 'fecha_deteccion', order: 'desc' }).then((result) => {
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

  function set(field, value) {
    setDraft({ ...draft, [field]: value });
  }

  return (
    <section>
      <div className="page-heading row-between">
        <div>
          <p className="eyebrow">Comercial</p>
          <h1>Oportunidades</h1>
          <p className="lede">Necesidades comerciales del cliente. El cierre no crea pedidos ni cambia el estado técnico de los neumáticos.</p>
        </div>
        {canWriteComercial(user) ? <Link className="button button-primary" to="/comercial/oportunidades/nueva">Nueva oportunidad</Link> : null}
      </div>
      <form className="filters" onSubmit={(event) => { event.preventDefault(); setPage(1); setFilters(draft); }}>
        <label>Buscar
          <input value={draft.search} placeholder="Título, cliente o descripción" onChange={(event) => set('search', event.target.value)} />
        </label>
        <SearchSelect
          label="Cliente"
          value={draft.cliente_id}
          selectedLabel={clienteLabel}
          placeholder="Buscar cliente"
          emptyLabel="Todos"
          scopeKey="clientes-comerciales"
          fetchPage={(params) => listClientesComerciales({ ...params, sort: 'razon_social', order: 'asc' }).then(pagina)}
          getLabel={(item) => item.nombre_comercial || item.razon_social}
          onChange={(value, item) => { set('cliente_id', value); setClienteLabel(item ? (item.nombre_comercial || item.razon_social) : ''); }}
        />
        <label>Estado
          <select value={draft.estado_id} onChange={(event) => set('estado_id', event.target.value)}>
            <option value="">Todos</option>
            {estados.map((estado) => <option key={estado.id} value={estado.id}>{estado.nombre}</option>)}
          </select>
        </label>
        <label>Origen
          <select value={draft.origen} onChange={(event) => set('origen', event.target.value)}>
            <option value="">Todos</option>
            {ORIGENES.map(([codigo, etiqueta]) => <option key={codigo} value={codigo}>{etiqueta}</option>)}
          </select>
        </label>
        <label>Moneda
          <input value={draft.moneda} maxLength={3} placeholder="PEN" onChange={(event) => set('moneda', event.target.value.toUpperCase())} />
        </label>
        <label>Detección desde
          <input type="date" value={draft.fecha_inicio} onChange={(event) => set('fecha_inicio', event.target.value)} />
        </label>
        <label>Detección hasta
          <input type="date" value={draft.fecha_fin} onChange={(event) => set('fecha_fin', event.target.value)} />
        </label>
        <label>Necesidad desde
          <input type="date" value={draft.fecha_necesidad_inicio} onChange={(event) => set('fecha_necesidad_inicio', event.target.value)} />
        </label>
        <label>Necesidad hasta
          <input type="date" value={draft.fecha_necesidad_fin} onChange={(event) => set('fecha_necesidad_fin', event.target.value)} />
        </label>
        <button className="button button-primary" type="submit">Filtrar</button>
      </form>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Cliente</th>
              <th>Título</th>
              {admin ? <th>Responsable</th> : null}
              <th>Origen</th>
              <th>Estado</th>
              <th>Necesidad estimada</th>
              <th>Valor estimado</th>
              <th>Moneda</th>
              <th>Próximo seguimiento</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? <tr><td colSpan={admin ? 11 : 10}>No hay oportunidades con estos filtros.</td></tr> : rows.map((row) => (
              <tr key={row.id}>
                <td data-label="Fecha">{row.fecha_deteccion}</td>
                <td data-label="Cliente">{clienteNombre(row.cliente)}</td>
                <td data-label="Título">{row.titulo}</td>
                {admin ? <td data-label="Responsable">{nombre(row.responsable)}</td> : null}
                <td data-label="Origen">{ORIGENES.find(([codigo]) => codigo === row.origen)?.[1] || row.origen}</td>
                <td data-label="Estado"><EstadoBadge estado={row.estado.codigo} /></td>
                <td data-label="Necesidad estimada">{row.fecha_estimada_necesidad || '—'}</td>
                <td data-label="Valor estimado">{dinero(row.valor_estimado)}</td>
                <td data-label="Moneda">{row.moneda || '—'}</td>
                <td data-label="Próximo seguimiento">
                  {row.proximo_seguimiento ? <span className={`seguimiento-${row.proximo_seguimiento.indicador}`}>{proximoTexto(row.proximo_seguimiento)}</span> : 'Sin fecha'}
                </td>
                <td data-label="Acciones"><Link to={`/comercial/oportunidades/${row.id}`}>Abrir</Link></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Pager page={page} total={total} limit={20} onPage={setPage} />
    </section>
  );
}
