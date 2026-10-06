import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { errorMessage } from '../../api/clientes';
import { listConfiguraciones } from '../../api/unidades';
import { Pager } from '../../components/Pager';
import { useAuth } from '../../auth/AuthContext';
import { canManageConfiguraciones } from '../../utils/access';

export function ConfiguracionesPage() {
  const { user } = useAuth();
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [filters, setFilters] = useState({ search: '' });
  const [error, setError] = useState('');

  useEffect(() => {
    listConfiguraciones({ ...filters, page, limit: 10, sort: 'nombre', order: 'asc' }).then((result) => {
      if (!result.ok) {
        setError(errorMessage(result));
        return;
      }
      setError('');
      setRows(result.payload?.data ?? []);
      setTotal(result.payload?.total ?? 0);
    });
  }, [filters, page]);

  return (
    <section>
      <div className="page-heading row-between">
        <div>
          <p className="eyebrow">Catálogo técnico</p>
          <h1>Configuraciones de unidad</h1>
          <p className="lede">Plantillas globales de ejes y posiciones. No pertenecen a un cliente.</p>
        </div>
        <div className="actions">
          <Link className="button button-quiet" to="/tipos-unidad">Tipos de unidad</Link>
          {canManageConfiguraciones(user) ? <Link className="button button-primary" to="/configuraciones-unidad/nueva">Nueva configuración</Link> : null}
        </div>
      </div>
      <form className="filters" onSubmit={(event) => { event.preventDefault(); setPage(1); setFilters({ search }); }}>
        <label>Buscar<input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Nombre" /></label>
        <button className="button button-primary" type="submit">Buscar</button>
      </form>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <div className="table-wrap">
        <table className="data-table">
          <thead><tr><th>Nombre</th><th>Ejes</th><th>Posiciones</th><th>Estado</th><th>Acciones</th></tr></thead>
          <tbody>
            {rows.length === 0 ? <tr><td colSpan={5}>No hay configuraciones.</td></tr> : rows.map((row) => (
              <tr key={row.id}>
                <td data-label="Nombre">{row.nombre}</td>
                <td data-label="Ejes">{row.cantidad_ejes}</td>
                <td data-label="Posiciones">{row.cantidad_posiciones}</td>
                <td data-label="Estado">{row.activo ? 'Activa' : 'Inactiva'}</td>
                <td data-label="Acciones"><Link to={`/configuraciones-unidad/${row.id}`}>Ver detalle</Link></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Pager page={page} limit={10} total={total} onPage={setPage} />
    </section>
  );
}
