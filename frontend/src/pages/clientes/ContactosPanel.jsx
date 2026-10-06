import { useEffect, useState } from 'react';
import { changeContactoActivo, createContacto, errorMessage, listContactos, updateContacto } from '../../api/clientes';

const EMPTY = { nombre: '', cargo: '', telefono: '', email: '', tipo_contacto: '', principal: false, activo: true };

export function ContactosPanel({ clienteId, canWrite }) {
  const [rows, setRows] = useState([]);
  const [form, setForm] = useState(EMPTY);
  const [editing, setEditing] = useState(null);
  const [error, setError] = useState('');

  async function load() {
    const result = await listContactos(clienteId, { limit: 50, sort: 'nombre', order: 'asc' });
    if (!result.ok) {
      setError(errorMessage(result));
      return;
    }
    setRows(result.payload?.data ?? []);
  }

  useEffect(() => {
    load();
  }, [clienteId]);

  async function onSubmit(event) {
    event.preventDefault();
    const payload = {
      ...form,
      cargo: form.cargo || null,
      telefono: form.telefono || null,
      email: form.email || null,
      tipo_contacto: form.tipo_contacto || null,
    };
    const result = editing
      ? await updateContacto(clienteId, editing, payload)
      : await createContacto(clienteId, payload);
    if (!result.ok) {
      setError(errorMessage(result));
      return;
    }
    setForm(EMPTY);
    setEditing(null);
    setError('');
    load();
  }

  return (
    <div>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <ul className="record-list">
        {rows.length === 0 ? <li>No hay contactos.</li> : rows.map((row) => (
          <li key={row.id}>
            <div>
              <strong>{row.nombre}</strong>
              <span>{row.cargo || 'Sin cargo'} · {row.principal ? 'Principal' : 'Secundario'} · {row.activo ? 'Activo' : 'Inactivo'}</span>
              <span>{row.email || 'Sin correo'} · {row.telefono || 'Sin teléfono'}</span>
            </div>
            {canWrite ? (
              <div className="actions">
                <button type="button" className="button button-quiet" onClick={() => { setEditing(row.id); setForm({ ...row, cargo: row.cargo || '', telefono: row.telefono || '', email: row.email || '', tipo_contacto: row.tipo_contacto || '' }); }}>Editar</button>
                <button type="button" className="button button-quiet" onClick={() => changeContactoActivo(clienteId, row.id, !row.activo).then(load)}>
                  {row.activo ? 'Desactivar' : 'Activar'}
                </button>
              </div>
            ) : null}
          </li>
        ))}
      </ul>
      {canWrite ? (
        <form className="stack-form" onSubmit={onSubmit}>
          <h2>{editing ? 'Editar contacto' : 'Nuevo contacto'}</h2>
          <label>Nombre<input value={form.nombre} onChange={(event) => setForm({ ...form, nombre: event.target.value })} required /></label>
          <label>Cargo<input value={form.cargo} onChange={(event) => setForm({ ...form, cargo: event.target.value })} /></label>
          <label>Teléfono<input value={form.telefono} onChange={(event) => setForm({ ...form, telefono: event.target.value })} /></label>
          <label>Email<input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} /></label>
          <label className="check"><input type="checkbox" checked={form.principal} onChange={(event) => setForm({ ...form, principal: event.target.checked })} /> Contacto principal</label>
          <button className="button button-primary" type="submit">{editing ? 'Guardar contacto' : 'Agregar contacto'}</button>
        </form>
      ) : null}
    </div>
  );
}
