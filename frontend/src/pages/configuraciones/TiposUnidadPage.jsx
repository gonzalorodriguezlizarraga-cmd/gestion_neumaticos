import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { errorMessage } from '../../api/clientes';
import { changeTipoActivo, createTipo, listTipos, updateTipo } from '../../api/unidades';
import { useAuth } from '../../auth/AuthContext';
import { canManageTipos } from '../../utils/access';

const EMPTY = { id: null, codigo: '', nombre: '', descripcion: '', en_uso: false };

export function TiposUnidadPage() {
  const { user } = useAuth();
  const admin = canManageTipos(user);
  const [rows, setRows] = useState([]);
  const [form, setForm] = useState(EMPTY);
  const [error, setError] = useState('');

  async function load() {
    const result = await listTipos({ limit: 100, sort: 'nombre', order: 'asc' });
    if (!result.ok) {
      setError(errorMessage(result));
      return;
    }
    setRows(result.payload?.data ?? []);
  }

  useEffect(() => { load(); }, []);

  async function onSubmit(event) {
    event.preventDefault();
    const payload = { codigo: form.codigo, nombre: form.nombre, descripcion: form.descripcion || null };
    const result = form.id ? await updateTipo(form.id, payload) : await createTipo(payload);
    if (!result.ok) {
      setError(errorMessage(result));
      return;
    }
    setForm(EMPTY);
    setError('');
    load();
  }

  return (
    <section>
      <div className="page-heading">
        <p className="eyebrow">Catálogo</p>
        <h1>Tipos de unidad</h1>
        <p className="lede">Clasificación global de los equipos. <Link to="/configuraciones-unidad">Volver a configuraciones</Link></p>
      </div>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <div className="table-wrap">
        <table className="data-table">
          <thead><tr><th>Código</th><th>Nombre</th><th>Estado</th>{admin ? <th>Acciones</th> : null}</tr></thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id}>
                <td data-label="Código">{row.codigo}</td>
                <td data-label="Nombre">{row.nombre}</td>
                <td data-label="Estado">{row.activo ? 'Activo' : 'Inactivo'}</td>
                {admin ? (
                  <td data-label="Acciones">
                    <button type="button" className="button button-quiet" onClick={() => setForm({ id: row.id, codigo: row.codigo, nombre: row.nombre, descripcion: row.descripcion ?? '', en_uso: false })}>Editar</button>
                    <button type="button" className="button button-quiet" onClick={() => changeTipoActivo(row.id, !row.activo).then(load)}>{row.activo ? 'Desactivar' : 'Activar'}</button>
                  </td>
                ) : null}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {admin ? (
        <form className="stack-form" onSubmit={onSubmit}>
          <h2>{form.id ? 'Editar tipo' : 'Nuevo tipo'}</h2>
          <label>Código<input value={form.codigo} required onChange={(event) => setForm({ ...form, codigo: event.target.value })} /></label>
          <label>Nombre<input value={form.nombre} required onChange={(event) => setForm({ ...form, nombre: event.target.value })} /></label>
          <label>Descripción<input value={form.descripcion} onChange={(event) => setForm({ ...form, descripcion: event.target.value })} /></label>
          <button className="button button-primary" type="submit">{form.id ? 'Guardar tipo' : 'Crear tipo'}</button>
        </form>
      ) : null}
    </section>
  );
}
