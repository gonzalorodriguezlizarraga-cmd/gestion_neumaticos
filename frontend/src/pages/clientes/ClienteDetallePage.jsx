import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { changeClienteEstado, deleteCliente, errorMessage, getCliente } from '../../api/clientes';
import { EstadoBadge } from '../../components/EstadoBadge';
import { useAuth } from '../../auth/AuthContext';
import { canManageResponsables, canOperateCliente, isAdmin } from '../../utils/access';
import { ContactosPanel } from './ContactosPanel';
import { EstructuraPanel } from './EstructuraPanel';
import { ResponsablesPanel } from './ResponsablesPanel';

const TABS = [
  ['resumen', 'Resumen'],
  ['contactos', 'Contactos'],
  ['responsables', 'Responsables'],
  ['sedes', 'Sedes'],
  ['flotas', 'Flotas'],
];

export function ClienteDetallePage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const { user } = useAuth();
  const [cliente, setCliente] = useState(null);
  const [error, setError] = useState('');
  const [estado, setEstado] = useState('ACTIVO');
  const [confirming, setConfirming] = useState(false);
  const tab = params.get('seccion') || 'resumen';
  const admin = isAdmin(user);
  const operate = canOperateCliente(user);

  async function load() {
    const result = await getCliente(id);
    if (!result.ok) {
      setError(errorMessage(result, 'No se pudo abrir el cliente.'));
      setCliente(null);
      return;
    }
    setCliente(result.payload.data);
    setEstado(result.payload.data.estado);
    setError('');
  }

  useEffect(() => {
    load();
  }, [id]);

  async function saveEstado() {
    const result = await changeClienteEstado(id, estado);
    if (!result.ok) {
      setError(errorMessage(result));
      return;
    }
    setCliente(result.payload.data);
  }

  async function remove() {
    const result = await deleteCliente(id);
    if (!result.ok) {
      setError(errorMessage(result));
      return;
    }
    navigate('/clientes');
  }

  if (!cliente && error) {
    return <section><p className="form-error" role="alert">{error}</p><Link to="/clientes">Volver</Link></section>;
  }
  if (!cliente) return <p>Cargando ficha…</p>;

  return (
    <section>
      <div className="page-heading row-between">
        <div>
          <p className="eyebrow">Ficha de cliente</p>
          <h1>{cliente.razon_social}</h1>
          <p className="lede">{cliente.nombre_comercial || 'Sin nombre comercial'}</p>
          <EstadoBadge estado={cliente.estado} />
        </div>
        <div className="actions">
          {operate ? <Link className="button button-primary" to={`/clientes/${id}/editar`}>Editar</Link> : null}
          {admin ? <button type="button" className="button button-quiet" onClick={() => setConfirming(true)}>Eliminar</button> : null}
        </div>
      </div>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      {confirming ? (
        <div className="confirm-box">
          <p>El cliente dejará de aparecer en el listado. Su histórico se conserva.</p>
          <button type="button" className="button button-primary" onClick={remove}>Confirmar eliminación</button>
          <button type="button" className="button button-quiet" onClick={() => setConfirming(false)}>Cancelar</button>
        </div>
      ) : null}
      <div className="tabs" role="tablist">
        {TABS.map(([key, label]) => (
          <button key={key} type="button" role="tab" aria-selected={tab === key} className={tab === key ? 'tab active' : 'tab'} onClick={() => setParams({ seccion: key })}>
            {label}
          </button>
        ))}
      </div>
      <article className="panel">
        {tab === 'resumen' ? (
          <div className="summary-grid">
            <p><strong>Documento</strong><span>{cliente.ruc_documento || '—'}</span></p>
            <p><strong>Teléfono</strong><span>{cliente.telefono || '—'}</span></p>
            <p><strong>Email</strong><span>{cliente.email || '—'}</span></p>
            <p><strong>Dirección</strong><span>{cliente.direccion || '—'}</span></p>
            <p><strong>Inicio del servicio</strong><span>{cliente.fecha_inicio_servicio || 'Sin fecha'}</span></p>
            <p><strong>Observación</strong><span>{cliente.observacion || '—'}</span></p>
            {admin ? (
              <form className="estado-form" onSubmit={(event) => { event.preventDefault(); saveEstado(); }}>
                <label>Cambiar estado
                  <select value={estado} onChange={(event) => setEstado(event.target.value)}>
                    <option value="POTENCIAL">Potencial</option>
                    <option value="ACTIVO">Activo</option>
                    <option value="INACTIVO">Inactivo</option>
                  </select>
                </label>
                <button className="button button-primary" type="submit">Actualizar estado</button>
              </form>
            ) : null}
          </div>
        ) : null}
        {tab === 'contactos' ? <ContactosPanel clienteId={id} canWrite={operate} /> : null}
        {tab === 'responsables' ? <ResponsablesPanel clienteId={id} canWrite={canManageResponsables(user)} estado={cliente.estado} /> : null}
        {tab === 'sedes' ? <EstructuraPanel clienteId={id} tipo="sede" canWrite={operate} estado={cliente.estado} /> : null}
        {tab === 'flotas' ? <EstructuraPanel clienteId={id} tipo="flota" canWrite={operate} estado={cliente.estado} /> : null}
      </article>
    </section>
  );
}
