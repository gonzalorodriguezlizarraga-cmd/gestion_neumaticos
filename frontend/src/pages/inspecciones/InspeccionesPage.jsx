import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { listClientes } from '../../api/clientes';
import { errorMessage } from '../../api/clientes';
import { listInspecciones, pagina } from '../../api/inspecciones';
import { listUnidades } from '../../api/unidades';
import { useAuth } from '../../auth/AuthContext';
import { EstadoBadge } from '../../components/EstadoBadge';
import { Pager } from '../../components/Pager';
import { SearchSelect } from '../../components/SearchSelect';
import { canInspect, isAdmin } from '../../utils/access';
import { lectura, resumenTexto } from './texto';

const EMPTY = { search: '', estado: '', cliente_id: '', unidad_id: '', fecha_inicio: '', fecha_fin: '' };

export function InspeccionesPage() {
  const { user } = useAuth();
  const admin = isAdmin(user);
  const propios = user?.clientes ?? [];
  const [draft, setDraft] = useState(EMPTY);
  const [clienteLabel, setClienteLabel] = useState('');
  const [unidadLabel, setUnidadLabel] = useState('');
  const [filters, setFilters] = useState(EMPTY);
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
    listInspecciones({ ...filters, page, limit: 10, sort: 'fecha_inspeccion', order: 'desc' }).then((result) => {
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
          <h1>Inspecciones</h1>
          <p className="lede">Mediciones, daños y fotografías por unidad. Un borrador se puede completar por partes.</p>
        </div>
        {canInspect(user) ? <Link className="button button-primary" to="/inspecciones/nueva">Nueva inspección</Link> : null}
      </div>
      <form className="filters" onSubmit={(event) => { event.preventDefault(); setPage(1); setFilters(draft); }}>
        <label>Buscar
          <input name="search" value={draft.search} placeholder="Unidad o técnico" onChange={(event) => setDraft({ ...draft, search: event.target.value })} />
        </label>
        {!admin && propios.length > 1 ? (
          <label>Cliente
            <select name="cliente_id" value={draft.cliente_id} onChange={(event) => { setDraft({ ...draft, cliente_id: event.target.value, unidad_id: '' }); setUnidadLabel(''); }}>
              <option value="">Todos</option>
              {propios.map((item) => <option key={item.id} value={item.id}>{item.nombre_comercial || item.razon_social}</option>)}
            </select>
          </label>
        ) : null}
        <label>Estado
          <select name="estado" value={draft.estado} onChange={(event) => setDraft({ ...draft, estado: event.target.value })}>
            <option value="">Todos</option>
            <option value="BORRADOR">Borrador</option>
            <option value="FINALIZADA">Finalizada</option>
            <option value="ANULADA">Anulada</option>
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
        <div className="filters">
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
              setDraft({ ...draft, cliente_id: id, unidad_id: '' });
              setClienteLabel(item ? (item.nombre_comercial || item.razon_social) : '');
              setUnidadLabel('');
            }}
          />
          <SearchSelect
            label="Unidad"
            value={draft.unidad_id}
            selectedLabel={unidadLabel}
            placeholder="Buscar unidad"
            emptyLabel="Todas las unidades"
            scopeKey={draft.cliente_id || 'todas'}
            fetchPage={(params) => listUnidades({ ...params, cliente_id: draft.cliente_id, sort: 'codigo', order: 'asc' }).then(pagina)}
            getLabel={(item) => item.codigo}
            onChange={(id, item) => {
              setDraft({ ...draft, unidad_id: id });
              setUnidadLabel(item?.codigo || '');
            }}
          />
        </div>
      ) : (
        <SearchSelect
          label="Unidad"
          value={draft.unidad_id}
          selectedLabel={unidadLabel}
          placeholder="Buscar unidad"
          emptyLabel="Todas las unidades"
          disabled={!draft.cliente_id && propios.length > 1}
          scopeKey={draft.cliente_id || 'propio'}
          fetchPage={(params) => listUnidades({ ...params, cliente_id: draft.cliente_id, sort: 'codigo', order: 'asc' }).then(pagina)}
          getLabel={(item) => item.codigo}
          onChange={(id, item) => {
            setDraft({ ...draft, unidad_id: id });
            setUnidadLabel(item?.codigo || '');
          }}
        />
      )}
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              <th>Fecha</th><th>Unidad</th><th>Cliente</th><th>Técnico</th><th>Estado</th><th>Km/Horómetro</th><th>Resultado</th><th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? <tr><td colSpan={8}>No hay inspecciones para este criterio.</td></tr> : rows.map((row) => (
              <tr key={row.id}>
                <td data-label="Fecha">{lectura(row.fecha_inspeccion)}</td>
                <td data-label="Unidad">{row.unidad.codigo}</td>
                <td data-label="Cliente">{row.cliente.nombre_comercial || row.cliente.razon_social}</td>
                <td data-label="Técnico">{row.tecnico.nombre}</td>
                <td data-label="Estado"><EstadoBadge estado={row.estado} /></td>
                <td data-label="Km/Horómetro">{row.kilometraje ?? 'Sin km'} · {row.horometro ?? 'Sin horómetro'}</td>
                <td data-label="Resultado">{resumenTexto(row.resumen)}</td>
                <td data-label="Acciones"><Link to={`/inspecciones/${row.id}`}>Abrir</Link></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Pager page={page} limit={10} total={total} onPage={setPage} />
    </section>
  );
}
