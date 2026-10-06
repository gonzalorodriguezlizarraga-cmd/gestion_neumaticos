import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { errorDetails, errorMessage, listClientes } from '../../api/clientes';
import { asPage, createNeumatico, getNeumatico, listMarcas, listMedidas, listModelos, updateNeumatico } from '../../api/neumaticos';
import { SearchSelect } from '../../components/SearchSelect';
import { useAuth } from '../../auth/AuthContext';
import { canManageNeumaticos, isAdmin } from '../../utils/access';

const EMPTY = {
  cliente_id: '', cliente_label: '', codigo: '', numero_serie: '',
  marca_id: '', marca_label: '', modelo_id: '', modelo_label: '', medida_id: '', medida_label: '',
  fecha_adquisicion: '', costo_adquisicion: '', moneda: '',
  profundidad_inicial_mm: '', profundidad_minima_mm: '', observacion: '',
};

export function NeumaticoFormPage() {
  const { id } = useParams();
  const editing = Boolean(id);
  const navigate = useNavigate();
  const { user } = useAuth();
  const admin = isAdmin(user);
  const propios = user?.clientes ?? [];
  const [form, setForm] = useState(EMPTY);
  const [error, setError] = useState('');
  const [pending, setPending] = useState(false);
  const unico = !admin && propios.length === 1;

  useEffect(() => {
    if (unico && !editing) {
      const cliente = propios[0];
      setForm((current) => ({
        ...current,
        cliente_id: String(cliente.id),
        cliente_label: cliente.nombre_comercial || cliente.razon_social,
      }));
    }
  }, [unico, editing, propios]);

  useEffect(() => {
    if (!editing) return undefined;
    let cancelled = false;
    getNeumatico(id).then((result) => {
      if (cancelled) return;
      if (!result.ok) {
        setError(errorMessage(result));
        return;
      }
      const data = result.payload.data;
      setForm({
        cliente_id: String(data.cliente.id),
        cliente_label: data.cliente.nombre_comercial || data.cliente.razon_social,
        codigo: data.codigo,
        numero_serie: data.numero_serie ?? '',
        marca_id: String(data.marca.id),
        marca_label: data.marca.nombre,
        modelo_id: String(data.modelo.id),
        modelo_label: data.modelo.nombre,
        medida_id: String(data.medida.id),
        medida_label: data.medida.descripcion,
        fecha_adquisicion: data.fecha_adquisicion ?? '',
        costo_adquisicion: data.costo_adquisicion ?? '',
        moneda: data.moneda ?? '',
        profundidad_inicial_mm: data.profundidad_inicial_mm ?? '',
        profundidad_minima_mm: data.profundidad_minima_mm ?? '',
        observacion: data.observacion ?? '',
      });
    });
    return () => { cancelled = true; };
  }, [editing, id]);

  if (!canManageNeumaticos(user)) {
    return <section className="status-card"><h1>Acceso denegado</h1><p>Su rol no administra neumáticos.</p><Link to="/neumaticos">Volver</Link></section>;
  }

  function setField(name, value) {
    setForm((current) => ({ ...current, [name]: value }));
  }

  async function onSubmit(event) {
    event.preventDefault();
    setPending(true);
    setError('');
    const payload = {
      numero_serie: form.numero_serie || null,
      modelo_id: Number(form.modelo_id),
      medida_id: Number(form.medida_id),
      fecha_adquisicion: form.fecha_adquisicion || null,
      costo_adquisicion: form.costo_adquisicion === '' ? null : form.costo_adquisicion,
      moneda: form.moneda ? form.moneda.toUpperCase() : null,
      profundidad_inicial_mm: form.profundidad_inicial_mm === '' ? null : form.profundidad_inicial_mm,
      profundidad_minima_mm: form.profundidad_minima_mm,
      observacion: form.observacion || null,
    };
    const result = editing
      ? await updateNeumatico(id, payload)
      : await createNeumatico({ ...payload, cliente_id: Number(form.cliente_id), codigo: form.codigo });
    setPending(false);
    if (!result.ok) {
      const details = errorDetails(result);
      const first = details ? Object.values(details).flat()[0] : '';
      setError(first || errorMessage(result));
      return;
    }
    navigate(`/neumaticos/${result.payload.data.id}`);
  }

  return (
    <section className="form-card">
      <p className="eyebrow">{editing ? 'Edición' : 'Alta'}</p>
      <h1>{editing ? 'Editar neumático' : 'Nuevo neumático'}</h1>
      <form className="stack-form" onSubmit={onSubmit}>
        {editing || unico ? <p>Cliente: {form.cliente_label || form.cliente_id}</p> : admin ? (
          <SearchSelect
            label="Cliente"
            value={form.cliente_id}
            selectedLabel={form.cliente_label}
            required
            allowEmpty={false}
            placeholder="Buscar cliente activo"
            onChange={(id, item) => setForm({ ...form, cliente_id: id, cliente_label: item ? (item.nombre_comercial || item.razon_social) : '' })}
            fetchPage={(params) => listClientes({ ...params, estado: 'ACTIVO', sort: 'razon_social', order: 'asc' }).then(asPage)}
            getLabel={(item) => item.nombre_comercial || item.razon_social}
          />
        ) : (
          <label>Cliente
            <select value={form.cliente_id} required onChange={(event) => {
              const cliente = propios.find((item) => String(item.id) === event.target.value);
              setForm({ ...form, cliente_id: event.target.value, cliente_label: cliente ? (cliente.nombre_comercial || cliente.razon_social) : '' });
            }}>
              <option value="">Seleccione</option>
              {propios.map((item) => <option key={item.id} value={item.id}>{item.nombre_comercial || item.razon_social}</option>)}
            </select>
          </label>
        )}
        {editing ? <p>Código: {form.codigo}</p> : (
          <label>Código<input value={form.codigo} required onChange={(event) => setField('codigo', event.target.value)} /></label>
        )}
        <label>Número de serie<input value={form.numero_serie} onChange={(event) => setField('numero_serie', event.target.value)} /></label>
        <div className="split">
          <SearchSelect
            label="Marca"
            value={form.marca_id}
            selectedLabel={form.marca_label}
            required
            allowEmpty={false}
            placeholder="Buscar marca activa"
            onChange={(id, item) => setForm({ ...form, marca_id: id, marca_label: item?.nombre ?? '', modelo_id: '', modelo_label: '' })}
            fetchPage={(params) => listMarcas({ ...params, activo: 1, sort: 'nombre', order: 'asc' }).then(asPage)}
            getLabel={(item) => item.nombre}
          />
          <SearchSelect
            label="Modelo"
            value={form.modelo_id}
            selectedLabel={form.modelo_label}
            required
            allowEmpty={false}
            disabled={!form.marca_id}
            placeholder={form.marca_id ? 'Buscar modelo activo' : 'Elija primero la marca'}
            scopeKey={form.marca_id}
            onChange={(id, item) => setForm({ ...form, modelo_id: id, modelo_label: item?.nombre ?? '' })}
            fetchPage={(params) => listModelos({ ...params, marca_id: form.marca_id, activo: 1, sort: 'nombre', order: 'asc' }).then(asPage)}
            getLabel={(item) => item.nombre}
          />
        </div>
        <SearchSelect
          label="Medida"
          value={form.medida_id}
          selectedLabel={form.medida_label}
          required
          allowEmpty={false}
          placeholder="Buscar medida activa"
          onChange={(id, item) => setForm({ ...form, medida_id: id, medida_label: item?.descripcion ?? '' })}
          fetchPage={(params) => listMedidas({ ...params, activo: 1, sort: 'descripcion', order: 'asc' }).then(asPage)}
          getLabel={(item) => item.descripcion}
        />
        <div className="split">
          <label>Fecha de adquisición<input type="date" value={form.fecha_adquisicion} onChange={(event) => setField('fecha_adquisicion', event.target.value)} /></label>
          <label>Costo<input inputMode="decimal" value={form.costo_adquisicion} onChange={(event) => setField('costo_adquisicion', event.target.value)} /></label>
        </div>
        <label>Moneda<input maxLength={3} value={form.moneda} placeholder="PEN" onChange={(event) => setField('moneda', event.target.value.toUpperCase())} /></label>
        <div className="split">
          <label>Profundidad inicial (mm)<input inputMode="decimal" value={form.profundidad_inicial_mm} onChange={(event) => setField('profundidad_inicial_mm', event.target.value)} /></label>
          <label>Profundidad mínima (mm)<input inputMode="decimal" required value={form.profundidad_minima_mm} onChange={(event) => setField('profundidad_minima_mm', event.target.value)} /></label>
        </div>
        <label>Observación<textarea rows={3} value={form.observacion} onChange={(event) => setField('observacion', event.target.value)} /></label>
        <p className="lock-note">{editing
          ? 'El estado y la vida los fija el sistema. No se editan en este formulario.'
          : 'Estado inicial: Disponible. Vida inicial: 1. El estado y la vida los fija el sistema; no se eligen en este formulario.'}</p>
        {error ? <p className="form-error" role="alert">{error}</p> : null}
        <div className="actions">
          <button className="button button-primary" type="submit" disabled={pending}>{pending ? 'Guardando…' : editing ? 'Guardar cambios' : 'Crear neumático'}</button>
          <Link to={editing ? `/neumaticos/${id}` : '/neumaticos'}>Cancelar</Link>
        </div>
      </form>
    </section>
  );
}
