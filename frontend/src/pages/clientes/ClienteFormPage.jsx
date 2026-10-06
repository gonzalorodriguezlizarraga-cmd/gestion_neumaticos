import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { createCliente, errorDetails, errorMessage, getCliente, updateCliente } from '../../api/clientes';
import { useAuth } from '../../auth/AuthContext';
import { canOperateCliente, isAdmin } from '../../utils/access';

const INITIAL = {
  razon_social: '',
  nombre_comercial: '',
  ruc_documento: '',
  direccion: '',
  telefono: '',
  email: '',
  fecha_inicio_servicio: '',
  estado: 'POTENCIAL',
  observacion: '',
};

export function ClienteFormPage() {
  const { id } = useParams();
  const editing = Boolean(id);
  const navigate = useNavigate();
  const { user } = useAuth();
  const admin = isAdmin(user);
  const [form, setForm] = useState(INITIAL);
  const [details, setDetails] = useState(null);
  const [error, setError] = useState('');
  const [pending, setPending] = useState(false);

  useEffect(() => {
    if (!editing) return undefined;
    let cancelled = false;
    getCliente(id).then((result) => {
      if (cancelled) return;
      if (!result.ok) {
        setError(errorMessage(result, 'No se pudo abrir el cliente.'));
        return;
      }
      const data = result.payload.data;
      setForm({
        razon_social: data.razon_social ?? '',
        nombre_comercial: data.nombre_comercial ?? '',
        ruc_documento: data.ruc_documento ?? '',
        direccion: data.direccion ?? '',
        telefono: data.telefono ?? '',
        email: data.email ?? '',
        fecha_inicio_servicio: data.fecha_inicio_servicio ?? '',
        estado: data.estado ?? 'ACTIVO',
        observacion: data.observacion ?? '',
      });
    });
    return () => {
      cancelled = true;
    };
  }, [editing, id]);

  if (!canOperateCliente(user) || (!editing && !admin)) {
    return (
      <section className="status-card">
        <h1>Acceso denegado</h1>
        <p>Su rol no puede {editing ? 'editar' : 'crear'} clientes.</p>
        <Link to="/clientes">Volver al listado</Link>
      </section>
    );
  }

  function setField(name, value) {
    setForm((current) => ({ ...current, [name]: value }));
  }

  async function onSubmit(event) {
    event.preventDefault();
    setPending(true);
    setError('');
    setDetails(null);
    const payload = {
      razon_social: form.razon_social,
      nombre_comercial: form.nombre_comercial || null,
      ruc_documento: form.ruc_documento || null,
      direccion: form.direccion || null,
      telefono: form.telefono || null,
      email: form.email || null,
      fecha_inicio_servicio: form.fecha_inicio_servicio || null,
      observacion: form.observacion || null,
    };
    const result = editing
      ? await updateCliente(id, admin ? payload : {
          nombre_comercial: payload.nombre_comercial,
          direccion: payload.direccion,
          telefono: payload.telefono,
          email: payload.email,
          observacion: payload.observacion,
        })
      : await createCliente({ ...payload, estado: form.estado });
    setPending(false);
    if (!result.ok) {
      setError(errorMessage(result));
      setDetails(errorDetails(result));
      return;
    }
    navigate(`/clientes/${result.payload.data.id}`);
  }

  return (
    <section className="form-card">
      <p className="eyebrow">{editing ? 'Edición' : 'Alta'}</p>
      <h1>{editing ? 'Editar cliente' : 'Nuevo cliente'}</h1>
      <form className="stack-form" onSubmit={onSubmit}>
        <Field label="Razón social" error={details?.razon_social?.[0]}>
          <input value={form.razon_social} disabled={editing && !admin} onChange={(event) => setField('razon_social', event.target.value)} required />
        </Field>
        <Field label="Nombre comercial" error={details?.nombre_comercial?.[0]}>
          <input value={form.nombre_comercial} onChange={(event) => setField('nombre_comercial', event.target.value)} />
        </Field>
        <div className="split">
          <Field label="RUC/documento" error={details?.ruc_documento?.[0]}>
            <input value={form.ruc_documento} disabled={editing && !admin} onChange={(event) => setField('ruc_documento', event.target.value)} />
          </Field>
          <Field label="Teléfono" error={details?.telefono?.[0]}>
            <input value={form.telefono} onChange={(event) => setField('telefono', event.target.value)} />
          </Field>
        </div>
        <Field label="Email" error={details?.email?.[0]}>
          <input type="email" value={form.email} onChange={(event) => setField('email', event.target.value)} />
        </Field>
        <Field label="Dirección" error={details?.direccion?.[0]}>
          <input value={form.direccion} onChange={(event) => setField('direccion', event.target.value)} />
        </Field>
        <div className="split">
          <Field label="Inicio del servicio" error={details?.fecha_inicio_servicio?.[0]}>
            <input type="date" value={form.fecha_inicio_servicio} disabled={editing && !admin} onChange={(event) => setField('fecha_inicio_servicio', event.target.value)} />
          </Field>
          {!editing ? (
            <Field label="Estado" error={details?.estado?.[0]}>
              <select value={form.estado} onChange={(event) => setField('estado', event.target.value)}>
                <option value="POTENCIAL">Potencial</option>
                <option value="ACTIVO">Activo</option>
              </select>
            </Field>
          ) : null}
        </div>
        <Field label="Observación" error={details?.observacion?.[0]}>
          <textarea rows={4} value={form.observacion} onChange={(event) => setField('observacion', event.target.value)} />
        </Field>
        {error ? <p className="form-error" role="alert">{error}</p> : null}
        <div className="actions">
          <button className="button button-primary" type="submit" disabled={pending}>{pending ? 'Guardando…' : 'Guardar'}</button>
          <Link to={editing ? `/clientes/${id}` : '/clientes'}>Cancelar</Link>
        </div>
      </form>
    </section>
  );
}

function Field({ label, error, children }) {
  return (
    <label className="field">
      {label}
      {children}
      {error ? <span className="field-error">{error}</span> : null}
    </label>
  );
}
