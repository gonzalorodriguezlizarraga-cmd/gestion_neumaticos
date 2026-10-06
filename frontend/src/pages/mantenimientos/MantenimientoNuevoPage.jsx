import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { listClientes } from '../../api/clientes';
import { pagina } from '../../api/inspecciones';
import { createMantenimiento, listTiposMantenimiento } from '../../api/mantenimientos';
import { asPage, listNeumaticos } from '../../api/neumaticos';
import { useAuth } from '../../auth/AuthContext';
import { SearchSelect } from '../../components/SearchSelect';
import { canManageNeumaticos, isAdmin } from '../../utils/access';
import { aFecha, ahoraLocal, mensaje } from './texto';

const INICIAL = {
  cliente_id: '',
  neumatico_id: '',
  tipo_mantenimiento_id: '',
  fecha_solicitud: ahoraLocal(),
  tercero_nombre: '',
  costo: '',
  moneda: 'PEN',
  profundidad_antes_mm: '',
  observacion: '',
};

export function MantenimientoNuevoPage() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const admin = isAdmin(user);
  const propios = user?.clientes ?? [];
  const [form, setForm] = useState(INICIAL);
  const [tipos, setTipos] = useState([]);
  const [clienteLabel, setClienteLabel] = useState('');
  const [neumaticoLabel, setNeumaticoLabel] = useState('');
  const [error, setError] = useState('');
  const [enviando, setEnviando] = useState(false);

  useEffect(() => {
    if (!canManageNeumaticos(user)) return;
    listTiposMantenimiento().then((result) => {
      if (result.ok) setTipos(result.payload?.data ?? []);
    });
  }, [user]);

  useEffect(() => {
    if (!admin && propios.length === 1 && !form.cliente_id) {
      setForm((current) => ({ ...current, cliente_id: String(propios[0].id) }));
    }
  }, [admin, propios, form.cliente_id]);

  if (!canManageNeumaticos(user)) {
    return <section><p className="form-error">No puede registrar mantenimientos.</p><Link to="/mantenimientos">Volver</Link></section>;
  }

  const tipo = tipos.find((item) => String(item.id) === String(form.tipo_mantenimiento_id));

  function cambiar(event) {
    setForm({ ...form, [event.target.name]: event.target.value });
  }

  async function guardar(event) {
    event.preventDefault();
    setEnviando(true);
    setError('');
    const body = {
      neumatico_id: Number(form.neumatico_id),
      tipo_mantenimiento_id: Number(form.tipo_mantenimiento_id),
      fecha_solicitud: aFecha(form.fecha_solicitud),
    };
    if (form.tercero_nombre.trim()) body.tercero_nombre = form.tercero_nombre.trim();
    if (form.costo !== '') {
      body.costo = form.costo;
      body.moneda = form.moneda.trim().toUpperCase();
    }
    if (form.profundidad_antes_mm !== '') body.profundidad_antes_mm = form.profundidad_antes_mm;
    if (form.observacion.trim()) body.observacion = form.observacion.trim();
    const result = await createMantenimiento(body);
    setEnviando(false);
    if (!result.ok) {
      setError(mensaje(result));
      return;
    }
    navigate(`/mantenimientos/${result.payload.data.id}`);
  }

  return (
    <section>
      <div className="page-heading">
        <p className="eyebrow">Mantenimiento</p>
        <h1>Nueva solicitud</h1>
        <p className="lede">La solicitud queda en Solicitado. El neumático sigue disponible hasta el envío.</p>
      </div>
      <form className="stack-form" onSubmit={guardar}>
        {admin ? (
          <SearchSelect
            label="Cliente"
            value={form.cliente_id}
            selectedLabel={clienteLabel}
            placeholder="Buscar cliente"
            emptyLabel="Seleccione un cliente"
            required
            scopeKey="clientes"
            fetchPage={(params) => listClientes({ ...params, sort: 'razon_social', order: 'asc' }).then(pagina)}
            getLabel={(item) => item.nombre_comercial || item.razon_social}
            onChange={(id, item) => {
              setForm({ ...form, cliente_id: id, neumatico_id: '' });
              setClienteLabel(item ? (item.nombre_comercial || item.razon_social) : '');
              setNeumaticoLabel('');
            }}
          />
        ) : null}
        {!admin && propios.length > 1 ? (
          <label>Cliente
            <select name="cliente_id" value={form.cliente_id} required onChange={(event) => { setForm({ ...form, cliente_id: event.target.value, neumatico_id: '' }); setNeumaticoLabel(''); }}>
              <option value="">Seleccione</option>
              {propios.map((item) => <option key={item.id} value={item.id}>{item.nombre_comercial || item.razon_social}</option>)}
            </select>
          </label>
        ) : null}
        <SearchSelect
          label="Neumático"
          value={form.neumatico_id}
          selectedLabel={neumaticoLabel}
          placeholder="Buscar por código"
          emptyLabel="Seleccione un neumático"
          required
          disabled={!form.cliente_id}
          scopeKey={form.cliente_id || 'sin-cliente'}
          fetchPage={(params) => listNeumaticos({ ...params, cliente_id: form.cliente_id, sort: 'codigo', order: 'asc' }).then(asPage)}
          getLabel={(item) => item.codigo}
          onChange={(id, item) => {
            setForm({ ...form, neumatico_id: id });
            setNeumaticoLabel(item?.codigo || '');
          }}
        />
        <label>Tipo
          <select name="tipo_mantenimiento_id" value={form.tipo_mantenimiento_id} required onChange={cambiar}>
            <option value="">Seleccione</option>
            {tipos.map((item) => <option key={item.id} value={item.id}>{item.nombre}</option>)}
          </select>
        </label>
        {tipo?.codigo === 'REENCAUCHE' ? (
          <p className="lock-note">Al finalizar se cerrará la Vida actual y se abrirá una nueva Vida.</p>
        ) : null}
        <label>Fecha de solicitud
          <input type="datetime-local" name="fecha_solicitud" required value={form.fecha_solicitud} onChange={cambiar} />
        </label>
        <label>Tercero
          <input name="tercero_nombre" maxLength={180} value={form.tercero_nombre} onChange={cambiar} />
        </label>
        <label>Costo
          <input name="costo" inputMode="decimal" value={form.costo} onChange={cambiar} />
        </label>
        <label>Moneda
          <input name="moneda" maxLength={3} value={form.moneda} onChange={cambiar} />
        </label>
        <label>Profundidad antes (mm)
          <input name="profundidad_antes_mm" inputMode="decimal" value={form.profundidad_antes_mm} onChange={cambiar} />
        </label>
        <label>Observación
          <textarea name="observacion" maxLength={2000} value={form.observacion} onChange={cambiar} />
        </label>
        {error ? <p className="form-error" role="alert">{error}</p> : null}
        <div className="actions">
          <button className="button button-primary" type="submit" disabled={enviando}>{enviando ? 'Guardando…' : 'Crear solicitud'}</button>
          <Link className="button button-quiet" to="/mantenimientos">Cancelar</Link>
        </div>
      </form>
    </section>
  );
}
