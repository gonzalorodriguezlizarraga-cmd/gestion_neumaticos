import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { errorMessage, listClientes } from '../../api/clientes';
import { asPage, listEstadosNeumatico, listMarcas, listMedidas, listModelos, listNeumaticos } from '../../api/neumaticos';
import { EstadoBadge } from '../../components/EstadoBadge';
import { Pager } from '../../components/Pager';
import { SearchSelect } from '../../components/SearchSelect';
import { useAuth } from '../../auth/AuthContext';
import { canManageNeumaticos, isAdmin } from '../../utils/access';

const EMPTY = {
  search: '', cliente_id: '', cliente_label: '', marca_id: '', marca_label: '',
  modelo_id: '', modelo_label: '', medida_id: '', medida_label: '',
  estado_id: '', vida_actual: '', sort: 'codigo', order: 'asc',
};

export function NeumaticosPage() {
  const { user } = useAuth();
  const admin = isAdmin(user);
  const propios = user?.clientes ?? [];
  const [draft, setDraft] = useState(EMPTY);
  const [filters, setFilters] = useState(EMPTY);
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [estados, setEstados] = useState([]);
  const [error, setError] = useState('');

  useEffect(() => {
    listEstadosNeumatico({ limit: 20, sort: 'orden', order: 'asc' }).then((result) => {
      if (result.ok) setEstados(result.payload?.data ?? []);
    });
  }, []);

  useEffect(() => {
    if (!admin && propios.length === 1 && !draft.cliente_id) {
      setDraft((current) => ({ ...current, cliente_id: String(propios[0].id) }));
    }
  }, [admin, propios, draft.cliente_id]);

  useEffect(() => {
    let cancelled = false;
    const query = { ...filters };
    delete query.cliente_label;
    delete query.marca_label;
    delete query.modelo_label;
    delete query.medida_label;
    listNeumaticos({ ...query, page, limit: 10 }).then((result) => {
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
          <p className="eyebrow">Activo</p>
          <h1>Neumáticos</h1>
          <p className="lede">
            Identidad, estado actual y vida vigente de cada llanta.
            {' '}<Link to="/neumaticos/catalogo">Marcas, modelos y medidas</Link>
          </p>
        </div>
        {canManageNeumaticos(user) ? <Link className="button button-primary" to="/neumaticos/nuevo">Nuevo neumático</Link> : null}
      </div>
      <form className="filters" onSubmit={(event) => { event.preventDefault(); setPage(1); setFilters(draft); }}>
        <label>Buscar<input name="search" value={draft.search} placeholder="Código o serie" onChange={(event) => setDraft({ ...draft, search: event.target.value })} /></label>
        {admin ? (
          <SearchSelect
            label="Cliente"
            value={draft.cliente_id}
            selectedLabel={draft.cliente_label}
            emptyLabel="Todos los clientes"
            placeholder="Buscar cliente"
            onChange={(id, item) => setDraft({ ...draft, cliente_id: id, cliente_label: item ? (item.nombre_comercial || item.razon_social) : '' })}
            fetchPage={(params) => listClientes({ ...params, sort: 'razon_social', order: 'asc' }).then(asPage)}
            getLabel={(item) => item.nombre_comercial || item.razon_social}
          />
        ) : null}
        {!admin && propios.length > 1 ? (
          <label>Cliente
            <select name="cliente_id" value={draft.cliente_id} onChange={(event) => setDraft({ ...draft, cliente_id: event.target.value })}>
              <option value="">Todos</option>
              {propios.map((item) => <option key={item.id} value={item.id}>{item.nombre_comercial || item.razon_social}</option>)}
            </select>
          </label>
        ) : null}
        <SearchSelect
          label="Marca"
          value={draft.marca_id}
          selectedLabel={draft.marca_label}
          emptyLabel="Todas"
          placeholder="Buscar marca"
          onChange={(id, item) => setDraft({ ...draft, marca_id: id, marca_label: item?.nombre ?? '', modelo_id: '', modelo_label: '' })}
          fetchPage={(params) => listMarcas({ ...params, sort: 'nombre', order: 'asc' }).then(asPage)}
          getLabel={(item) => item.nombre}
        />
        <SearchSelect
          label="Modelo"
          value={draft.modelo_id}
          selectedLabel={draft.modelo_label}
          emptyLabel="Todos"
          placeholder={draft.marca_id ? 'Buscar modelo' : 'Buscar modelo de cualquier marca'}
          scopeKey={draft.marca_id}
          onChange={(id, item) => setDraft({ ...draft, modelo_id: id, modelo_label: item?.nombre ?? '' })}
          fetchPage={(params) => listModelos({ ...params, marca_id: draft.marca_id, sort: 'nombre', order: 'asc' }).then(asPage)}
          getLabel={(item) => draft.marca_id ? item.nombre : `${item.marca} · ${item.nombre}`}
        />
        <SearchSelect
          label="Medida"
          value={draft.medida_id}
          selectedLabel={draft.medida_label}
          emptyLabel="Todas"
          placeholder="Buscar medida"
          onChange={(id, item) => setDraft({ ...draft, medida_id: id, medida_label: item?.descripcion ?? '' })}
          fetchPage={(params) => listMedidas({ ...params, sort: 'descripcion', order: 'asc' }).then(asPage)}
          getLabel={(item) => item.descripcion}
        />
        <label>Estado
          <select name="estado_id" value={draft.estado_id} onChange={(event) => setDraft({ ...draft, estado_id: event.target.value })}>
            <option value="">Todos</option>
            {estados.map((item) => <option key={item.id} value={item.id}>{item.nombre}</option>)}
          </select>
        </label>
        <label>Vida<input name="vida_actual" inputMode="numeric" value={draft.vida_actual} placeholder="1" onChange={(event) => setDraft({ ...draft, vida_actual: event.target.value })} /></label>
        <label>Orden
          <select name="sort" value={draft.sort} onChange={(event) => setDraft({ ...draft, sort: event.target.value })}>
            <option value="codigo">Código</option>
            <option value="numero_serie">Serie</option>
            <option value="vida_actual">Vida</option>
            <option value="creado_en">Alta</option>
          </select>
        </label>
        <label>Sentido
          <select name="order" value={draft.order} onChange={(event) => setDraft({ ...draft, order: event.target.value })}>
            <option value="asc">Ascendente</option>
            <option value="desc">Descendente</option>
          </select>
        </label>
        <button className="button button-primary" type="submit">Buscar</button>
      </form>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              <th>Código</th><th>Serie</th><th>Cliente</th><th>Marca / Modelo</th><th>Medida</th><th>Estado</th><th>Criticidad</th><th>Vida</th><th>Profundidad</th><th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? <tr><td colSpan={10}>No hay neumáticos para este criterio.</td></tr> : rows.map((row) => (
              <tr key={row.id}>
                <td data-label="Código">{row.codigo}</td>
                <td data-label="Serie">{row.numero_serie || '—'}</td>
                <td data-label="Cliente">{row.cliente}</td>
                <td data-label="Marca / Modelo">{row.marca} / {row.modelo}</td>
                <td data-label="Medida">{row.medida}</td>
                <td data-label="Estado"><EstadoBadge estado={row.estado_codigo} /></td>
                <td data-label="Criticidad"><EstadoBadge estado={row.criticidad || 'NORMAL'} /></td>
                <td data-label="Vida">Vida {row.vida_actual}</td>
                <td data-label="Profundidad">{row.profundidad_actual_mm ?? 'No disponible'} / {row.profundidad_minima_mm} mm</td>
                <td data-label="Acciones"><Link to={`/neumaticos/${row.id}`}>Ver ficha</Link></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Pager page={page} limit={10} total={total} onPage={setPage} />
    </section>
  );
}
