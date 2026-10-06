import { useEffect, useState } from 'react';
import { createFlota, createSede, deleteFlota, deleteSede, errorMessage, listFlotas, listSedes } from '../../api/clientes';

export function EstructuraPanel({ clienteId, tipo, canWrite, estado }) {
  const esFlota = tipo === 'flota';
  const [rows, setRows] = useState([]);
  const [form, setForm] = useState({ nombre: '', codigo: '', extra: '' });
  const [error, setError] = useState('');
  const puedeCrear = canWrite && estado === 'ACTIVO';

  async function load() {
    const result = esFlota
      ? await listFlotas(clienteId, { limit: 50, sort: 'nombre', order: 'asc' })
      : await listSedes(clienteId, { limit: 50, sort: 'nombre', order: 'asc' });
    if (!result.ok) {
      setError(errorMessage(result));
      return;
    }
    setRows(result.payload?.data ?? []);
  }

  useEffect(() => {
    load();
  }, [clienteId, tipo]);

  async function onSubmit(event) {
    event.preventDefault();
    const payload = esFlota
      ? { nombre: form.nombre, codigo: form.codigo || null, descripcion: form.extra || null, activo: true }
      : { nombre: form.nombre, codigo: form.codigo || null, direccion: form.extra || null, activo: true };
    const result = esFlota ? await createFlota(clienteId, payload) : await createSede(clienteId, payload);
    if (!result.ok) {
      setError(errorMessage(result));
      return;
    }
    setForm({ nombre: '', codigo: '', extra: '' });
    setError('');
    load();
  }

  async function remove(id) {
    const result = esFlota ? await deleteFlota(clienteId, id) : await deleteSede(clienteId, id);
    if (!result.ok) {
      setError(errorMessage(result));
      return;
    }
    load();
  }

  return (
    <div>
      {estado !== 'ACTIVO' ? <p>Las {esFlota ? 'flotas' : 'sedes'} nuevas se habilitan cuando el cliente está activo. El estado actual es {estado === 'POTENCIAL' ? 'potencial' : 'inactivo'}.</p> : null}
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <ul className="record-list">
        {rows.length === 0 ? <li>No hay {esFlota ? 'flotas' : 'sedes'}.</li> : rows.map((row) => (
          <li key={row.id}>
            <div>
              <strong>{row.nombre}</strong>
              <span>{row.codigo || 'Sin código'} · {row.activo ? 'Activa' : 'Inactiva'}</span>
              <span>{esFlota ? (row.descripcion || 'Sin descripción') : (row.direccion || 'Sin dirección')}</span>
            </div>
            {canWrite ? <button type="button" className="button button-quiet" onClick={() => remove(row.id)}>Eliminar</button> : null}
          </li>
        ))}
      </ul>
      {puedeCrear ? (
        <form className="stack-form" onSubmit={onSubmit}>
          <h2>{esFlota ? 'Nueva flota' : 'Nueva sede'}</h2>
          <label>Nombre<input value={form.nombre} onChange={(event) => setForm({ ...form, nombre: event.target.value })} required /></label>
          <label>Código<input value={form.codigo} onChange={(event) => setForm({ ...form, codigo: event.target.value })} /></label>
          <label>{esFlota ? 'Descripción' : 'Dirección'}<input value={form.extra} onChange={(event) => setForm({ ...form, extra: event.target.value })} /></label>
          <button className="button button-primary" type="submit">Agregar</button>
        </form>
      ) : null}
    </div>
  );
}
