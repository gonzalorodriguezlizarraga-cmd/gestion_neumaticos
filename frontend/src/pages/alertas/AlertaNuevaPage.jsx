import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { listClientes } from '../../api/clientes';
import { pagina } from '../../api/inspecciones';
import { createAlerta, listTiposAlerta } from '../../api/alertas';
import { asPage, listNeumaticos } from '../../api/neumaticos';
import { listUnidades } from '../../api/unidades';
import { useAuth } from '../../auth/AuthContext';
import { SearchSelect } from '../../components/SearchSelect';
import { canManageNeumaticos, isAdmin } from '../../utils/access';
import { mensaje } from './texto';

const INICIAL = {
  cliente_id: '',
  tipo_alerta_id: '',
  nivel: 'ATENCION',
  unidad_id: '',
  neumatico_id: '',
  titulo: '',
  descripcion: '',
  recomendacion: '',
};

export function AlertaNuevaPage() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const admin = isAdmin(user);
  const propios = user?.clientes ?? [];
  const [form, setForm] = useState(INICIAL);
  const [tipos, setTipos] = useState([]);
  const [clienteLabel, setClienteLabel] = useState('');
  const [unidadLabel, setUnidadLabel] = useState('');
  const [neumaticoLabel, setNeumaticoLabel] = useState('');
  const [error, setError] = useState('');
  const [enviando, setEnviando] = useState(false);

  useEffect(() => {
    if (!canManageNeumaticos(user)) return;
    listTiposAlerta().then((result) => {
      if (result.ok) setTipos(result.payload?.data ?? []);
    });
  }, [user]);

  useEffect(() => {
    if (!admin && propios.length === 1 && !form.cliente_id) {
      setForm((current) => ({ ...current, cliente_id: String(propios[0].id) }));
    }
  }, [admin, propios, form.cliente_id]);

  if (!canManageNeumaticos(user)) {
    return <section><p className="form-error">No puede registrar alertas.</p><Link to="/alertas">Volver</Link></section>;
  }

  function cambiar(event) {
    setForm({ ...form, [event.target.name]: event.target.value });
  }

  async function guardar(event) {
    event.preventDefault();
    setEnviando(true);
    setError('');
    const body = {
      cliente_id: Number(form.cliente_id),
      tipo_alerta_id: Number(form.tipo_alerta_id),
      nivel: form.nivel,
      titulo: form.titulo.trim(),
      descripcion: form.descripcion.trim(),
    };
    if (form.unidad_id) body.unidad_id = Number(form.unidad_id);
    if (form.neumatico_id) body.neumatico_id = Number(form.neumatico_id);
    if (form.recomendacion.trim()) body.recomendacion = form.recomendacion.trim();
    const result = await createAlerta(body);
    setEnviando(false);
    if (!result.ok) {
      setError(mensaje(result));
      return;
    }
    navigate(`/alertas/${result.payload.data.id}`);
  }

  return (
    <section>
      <div className="page-heading">
        <p className="eyebrow">Alertas</p>
        <h1>Nueva alerta manual</h1>
        <p className="lede">Queda abierta. El sistema no cambia el estado del neumático por registrar una alerta.</p>
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
            scopeKey="alerta-cliente"
            fetchPage={(params) => listClientes({ ...params, sort: 'razon_social', order: 'asc' }).then(pagina)}
            getLabel={(item) => item.nombre_comercial || item.razon_social}
            onChange={(value, item) => {
              setForm({ ...form, cliente_id: value, unidad_id: '', neumatico_id: '' });
              setClienteLabel(item ? (item.nombre_comercial || item.razon_social) : '');
              setUnidadLabel('');
              setNeumaticoLabel('');
            }}
          />
        ) : (
          <label>Cliente
            <select name="cliente_id" value={form.cliente_id} required onChange={(event) => setForm({ ...form, cliente_id: event.target.value, unidad_id: '', neumatico_id: '' })}>
              <option value="">Seleccione</option>
              {propios.map((cliente) => <option key={cliente.id} value={cliente.id}>{cliente.nombre_comercial || cliente.razon_social}</option>)}
            </select>
          </label>
        )}
        <label>Tipo
          <select name="tipo_alerta_id" value={form.tipo_alerta_id} required onChange={cambiar}>
            <option value="">Seleccione</option>
            {tipos.map((tipo) => <option key={tipo.id} value={tipo.id}>{tipo.nombre}</option>)}
          </select>
        </label>
        <label>Nivel
          <select name="nivel" value={form.nivel} onChange={cambiar}>
            <option value="INFORMATIVA">Informativa</option>
            <option value="ATENCION">Atención</option>
            <option value="CRITICA">Crítica</option>
          </select>
        </label>
        <SearchSelect
          label="Unidad"
          value={form.unidad_id}
          selectedLabel={unidadLabel}
          placeholder="Opcional"
          emptyLabel="Sin unidad"
          disabled={!form.cliente_id}
          scopeKey={form.cliente_id || 'sin-cliente'}
          fetchPage={(params) => listUnidades({ ...params, cliente_id: form.cliente_id }).then(asPage)}
          getLabel={(item) => item.codigo}
          onChange={(value, item) => { setForm({ ...form, unidad_id: value }); setUnidadLabel(item?.codigo || ''); }}
        />
        <SearchSelect
          label="Neumático"
          value={form.neumatico_id}
          selectedLabel={neumaticoLabel}
          placeholder="Opcional"
          emptyLabel="Sin neumático"
          disabled={!form.cliente_id}
          scopeKey={`neu-${form.cliente_id || '0'}`}
          fetchPage={(params) => listNeumaticos({ ...params, cliente_id: form.cliente_id, sort: 'codigo', order: 'asc' }).then(asPage)}
          getLabel={(item) => item.codigo}
          onChange={(value, item) => { setForm({ ...form, neumatico_id: value }); setNeumaticoLabel(item?.codigo || ''); }}
        />
        <label>Título
          <input name="titulo" value={form.titulo} required maxLength={180} onChange={cambiar} />
        </label>
        <label>Descripción
          <textarea name="descripcion" value={form.descripcion} required rows={4} onChange={cambiar} />
        </label>
        <label>Recomendación
          <textarea name="recomendacion" value={form.recomendacion} rows={3} onChange={cambiar} />
        </label>
        {error ? <p className="form-error" role="alert">{error}</p> : null}
        <div className="actions">
          <button className="button button-primary" type="submit" disabled={enviando}>Registrar alerta</button>
          <Link className="button button-quiet" to="/alertas">Volver</Link>
        </div>
      </form>
    </section>
  );
}
