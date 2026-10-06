import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { listClientes, listFlotas, listSedes, errorMessage } from '../../api/clientes';
import { createUnidad, getUnidad, listConfiguraciones, listTipos, updateUnidad } from '../../api/unidades';
import { useAuth } from '../../auth/AuthContext';
import { canManageUnidades, isAdmin } from '../../utils/access';

const EMPTY = {
  cliente_id: '', sede_id: '', flota_id: '', tipo_unidad_id: '', configuracion_id: '',
  codigo: '', placa: '', marca: '', modelo: '', anio: '', numero_serie: '',
  kilometraje_actual: '', horometro_actual: '', estado: 'OPERATIVA', observacion: '',
};

export function UnidadFormPage() {
  const { id } = useParams();
  const editing = Boolean(id);
  const navigate = useNavigate();
  const { user } = useAuth();
  const admin = isAdmin(user);
  const propios = user?.clientes ?? [];
  const [form, setForm] = useState(EMPTY);
  const [clientes, setClientes] = useState(propios);
  const [sedes, setSedes] = useState([]);
  const [flotas, setFlotas] = useState([]);
  const [tipos, setTipos] = useState([]);
  const [configs, setConfigs] = useState([]);
  const [sedeActual, setSedeActual] = useState(null);
  const [flotaActual, setFlotaActual] = useState(null);
  const [error, setError] = useState('');
  const [pending, setPending] = useState(false);
  const unico = !admin && propios.length === 1;

  useEffect(() => {
    if (admin) {
      listClientes({ limit: 100, sort: 'razon_social', order: 'asc', estado: 'ACTIVO' }).then((result) => {
        if (result.ok) setClientes(result.payload?.data ?? []);
      });
    }
    listTipos({ limit: 100, activo: 1, sort: 'nombre' }).then((result) => {
      if (result.ok) setTipos(result.payload?.data ?? []);
    });
    listConfiguraciones({ limit: 100, activo: 1, sort: 'nombre' }).then((result) => {
      if (result.ok) setConfigs(result.payload?.data ?? []);
    });
  }, [admin]);

  useEffect(() => {
    if (unico) setForm((current) => ({ ...current, cliente_id: String(propios[0].id) }));
  }, [unico, propios]);

  useEffect(() => {
    if (!editing) return undefined;
    let cancelled = false;
    getUnidad(id).then((result) => {
      if (cancelled || !result.ok) {
        if (!cancelled && !result.ok) setError(errorMessage(result));
        return;
      }
      const data = result.payload.data;
      setForm({
        cliente_id: String(data.cliente.id),
        sede_id: data.sede ? String(data.sede.id) : '',
        flota_id: data.flota ? String(data.flota.id) : '',
        tipo_unidad_id: String(data.tipo.id),
        configuracion_id: data.configuracion ? String(data.configuracion.id) : '',
        codigo: data.codigo ?? '',
        placa: data.placa ?? '',
        marca: data.marca ?? '',
        modelo: data.modelo ?? '',
        anio: data.anio ?? '',
        numero_serie: data.numero_serie ?? '',
        kilometraje_actual: data.kilometraje_actual ?? '',
        horometro_actual: data.horometro_actual ?? '',
        estado: data.estado,
        observacion: data.observacion ?? '',
      });
      if (data.tipo && !data.tipo.activo) {
        setTipos((current) => current.some((item) => item.id === data.tipo.id) ? current : [...current, data.tipo]);
      }
      setSedeActual(data.sede);
      setFlotaActual(data.flota);
      if (data.configuracion && !data.configuracion.activo) {
        setConfigs((current) => current.some((item) => item.id === data.configuracion.id) ? current : [...current, data.configuracion]);
      }
    });
    return () => { cancelled = true; };
  }, [editing, id]);

  useEffect(() => {
    if (!form.cliente_id) return;
    listSedes(form.cliente_id, { limit: 100, activo: 1, sort: 'nombre' }).then((result) => setSedes(result.ok ? result.payload?.data ?? [] : []));
    listFlotas(form.cliente_id, { limit: 100, activo: 1, sort: 'nombre' }).then((result) => setFlotas(result.ok ? result.payload?.data ?? [] : []));
  }, [form.cliente_id]);

  if (!canManageUnidades(user)) {
    return <section className="status-card"><h1>Acceso denegado</h1><p>Su rol no administra unidades.</p><Link to="/unidades">Volver</Link></section>;
  }

  function setField(name, value) {
    setForm((current) => ({ ...current, [name]: value }));
  }

  async function onSubmit(event) {
    event.preventDefault();
    setPending(true);
    setError('');
    const payload = {
      tipo_unidad_id: Number(form.tipo_unidad_id),
      codigo: form.codigo,
      sede_id: form.sede_id ? Number(form.sede_id) : null,
      flota_id: form.flota_id ? Number(form.flota_id) : null,
      configuracion_id: form.configuracion_id ? Number(form.configuracion_id) : null,
      placa: form.placa || null,
      marca: form.marca || null,
      modelo: form.modelo || null,
      anio: form.anio === '' ? null : Number(form.anio),
      numero_serie: form.numero_serie || null,
      kilometraje_actual: form.kilometraje_actual === '' ? null : Number(form.kilometraje_actual),
      horometro_actual: form.horometro_actual === '' ? null : Number(form.horometro_actual),
      observacion: form.observacion || null,
    };
    const result = editing
      ? await updateUnidad(id, payload)
      : await createUnidad({ ...payload, cliente_id: Number(form.cliente_id), estado: form.estado });
    setPending(false);
    if (!result.ok) {
      setError(errorMessage(result));
      return;
    }
    navigate(`/unidades/${result.payload.data.id}`);
  }

  return (
    <section className="form-card">
      <p className="eyebrow">{editing ? 'Edición' : 'Alta'}</p>
      <h1>{editing ? 'Editar unidad' : 'Nueva unidad'}</h1>
      <form className="stack-form" onSubmit={onSubmit}>
        {editing || unico ? <p>Cliente: {clientes.find((item) => String(item.id) === String(form.cliente_id))?.razon_social || form.cliente_id}</p> : (
          <label>Cliente
            <select value={form.cliente_id} required onChange={(event) => setForm({ ...form, cliente_id: event.target.value, sede_id: '', flota_id: '' })}>
              <option value="">Seleccione</option>
              {clientes.map((item) => <option key={item.id} value={item.id}>{item.nombre_comercial || item.razon_social}</option>)}
            </select>
          </label>
        )}
        <div className="split">
          <label>Sede
            <select value={form.sede_id} onChange={(event) => setField('sede_id', event.target.value)}>
              <option value="">Sin sede</option>
              {sedeActual && !sedes.some((item) => String(item.id) === String(sedeActual.id)) ? <option value={sedeActual.id}>{sedeActual.nombre}</option> : null}
              {sedes.map((item) => <option key={item.id} value={item.id}>{item.nombre}</option>)}
            </select>
          </label>
          <label>Flota
            <select value={form.flota_id} onChange={(event) => setField('flota_id', event.target.value)}>
              <option value="">Sin flota</option>
              {flotaActual && !flotas.some((item) => String(item.id) === String(flotaActual.id)) ? <option value={flotaActual.id}>{flotaActual.nombre}</option> : null}
              {flotas.map((item) => <option key={item.id} value={item.id}>{item.nombre}</option>)}
            </select>
          </label>
        </div>
        <div className="split">
          <label>Tipo
            <select value={form.tipo_unidad_id} required onChange={(event) => setField('tipo_unidad_id', event.target.value)}>
              <option value="">Seleccione</option>
              {tipos.map((item) => <option key={item.id} value={item.id}>{item.nombre}</option>)}
            </select>
          </label>
          <label>Configuración
            <select value={form.configuracion_id} onChange={(event) => setField('configuracion_id', event.target.value)}>
              <option value="">Sin configuración</option>
              {configs.map((item) => <option key={item.id} value={item.id}>{item.nombre}</option>)}
            </select>
          </label>
        </div>
        <p className="lede">Sin configuración, la unidad no podrá participar después en montajes ni inspecciones.</p>
        <div className="split">
          <label>Código<input value={form.codigo} required onChange={(event) => setField('codigo', event.target.value)} /></label>
          <label>Placa<input value={form.placa} onChange={(event) => setField('placa', event.target.value)} /></label>
        </div>
        <div className="split">
          <label>Marca<input value={form.marca} onChange={(event) => setField('marca', event.target.value)} /></label>
          <label>Modelo<input value={form.modelo} onChange={(event) => setField('modelo', event.target.value)} /></label>
        </div>
        <div className="split">
          <label>Año<input inputMode="numeric" value={form.anio} onChange={(event) => setField('anio', event.target.value)} /></label>
          <label>Número de serie<input value={form.numero_serie} onChange={(event) => setField('numero_serie', event.target.value)} /></label>
        </div>
        <div className="split">
          <label>Kilometraje<input inputMode="numeric" value={form.kilometraje_actual} onChange={(event) => setField('kilometraje_actual', event.target.value)} /></label>
          <label>Horómetro<input inputMode="decimal" value={form.horometro_actual} onChange={(event) => setField('horometro_actual', event.target.value)} /></label>
        </div>
        {!editing ? (
          <label>Estado
            <select value={form.estado} onChange={(event) => setField('estado', event.target.value)}>
              <option value="OPERATIVA">Operativa</option>
              <option value="INACTIVA">Inactiva</option>
              {admin ? <option value="BAJA">Baja</option> : null}
            </select>
          </label>
        ) : null}
        <label>Observación<textarea rows={3} value={form.observacion} onChange={(event) => setField('observacion', event.target.value)} /></label>
        {error ? <p className="form-error" role="alert">{error}</p> : null}
        <div className="actions">
          <button className="button button-primary" type="submit" disabled={pending}>{pending ? 'Guardando…' : 'Guardar'}</button>
          <Link to={editing ? `/unidades/${id}` : '/unidades'}>Cancelar</Link>
        </div>
      </form>
    </section>
  );
}
