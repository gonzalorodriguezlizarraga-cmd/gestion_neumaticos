import { useEffect, useState } from 'react';
import { closeResponsable, createResponsable, errorMessage, listResponsables } from '../../api/clientes';

const EMPTY = { usuario_id: '', tipo_responsabilidad: 'COMERCIAL', fecha_inicio: '', fecha_fin: '', principal: false };

export function ResponsablesPanel({ clienteId, canWrite, estado }) {
  const [rows, setRows] = useState([]);
  const [form, setForm] = useState(EMPTY);
  const [error, setError] = useState('');
  const puedeCrear = canWrite && estado !== 'INACTIVO';

  async function load() {
    const result = await listResponsables(clienteId, { limit: 50, sort: 'fecha_inicio', order: 'desc' });
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
    const result = await createResponsable(clienteId, {
      usuario_id: Number(form.usuario_id),
      tipo_responsabilidad: form.tipo_responsabilidad,
      fecha_inicio: form.fecha_inicio,
      fecha_fin: form.fecha_fin || null,
      principal: form.principal,
    });
    if (!result.ok) {
      setError(errorMessage(result));
      return;
    }
    setForm(EMPTY);
    setError('');
    load();
  }

  return (
    <div>
      <p className="lede">La responsabilidad no otorga acceso al cliente. El acceso sigue definido por la asignación de usuarios.</p>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <ul className="record-list">
        {rows.length === 0 ? <li>No hay responsables.</li> : rows.map((row) => (
          <li key={row.id}>
            <div>
              <strong>{row.usuario.nombres} {row.usuario.apellidos}</strong>
              <span>{row.tipo_responsabilidad === 'TECNICO' ? 'Técnico' : 'Comercial'} · {row.vigente ? 'Vigente' : 'No vigente'}</span>
              <span>{row.fecha_inicio}{row.fecha_fin ? ` → ${row.fecha_fin}` : ' → abierto'}</span>
            </div>
            {canWrite && row.vigente ? (
              <button type="button" className="button button-quiet" onClick={() => closeResponsable(clienteId, row.id).then(load)}>Cerrar vigencia</button>
            ) : null}
          </li>
        ))}
      </ul>
      {puedeCrear ? (
        <form className="stack-form" onSubmit={onSubmit}>
          <h2>Nueva asignación</h2>
          {estado === 'POTENCIAL' ? <p>Un cliente potencial solo admite responsables comerciales.</p> : null}
          <label>Usuario
            <input inputMode="numeric" value={form.usuario_id} onChange={(event) => setForm({ ...form, usuario_id: event.target.value })} required />
          </label>
          <label>Tipo
            <select value={form.tipo_responsabilidad} onChange={(event) => setForm({ ...form, tipo_responsabilidad: event.target.value })}>
              <option value="COMERCIAL">Comercial</option>
              {estado === 'ACTIVO' ? <option value="TECNICO">Técnico</option> : null}
            </select>
          </label>
          <label>Inicio<input type="date" value={form.fecha_inicio} onChange={(event) => setForm({ ...form, fecha_inicio: event.target.value })} required /></label>
          <label>Fin<input type="date" value={form.fecha_fin} onChange={(event) => setForm({ ...form, fecha_fin: event.target.value })} /></label>
          <button className="button button-primary" type="submit">Asignar responsable</button>
        </form>
      ) : null}
      {estado === 'INACTIVO' ? <p>Un cliente inactivo no admite nuevas asignaciones.</p> : null}
    </div>
  );
}
