import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { errorMessage } from '../../api/clientes';
import { changeUnidadEstado, deleteUnidad, getUnidad } from '../../api/unidades';
import { NeumaticosMontados } from './NeumaticosMontados';
import { EstadoBadge } from '../../components/EstadoBadge';
import { useAuth } from '../../auth/AuthContext';
import { canManageUnidades, isAdmin } from '../../utils/access';

export function UnidadDetallePage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const [unidad, setUnidad] = useState(null);
  const [estado, setEstado] = useState('OPERATIVA');
  const [error, setError] = useState('');
  const [confirming, setConfirming] = useState(false);
  const admin = isAdmin(user);
  const operate = canManageUnidades(user);

  async function load() {
    const result = await getUnidad(id);
    if (!result.ok) {
      setError(errorMessage(result));
      setUnidad(null);
      return;
    }
    setUnidad(result.payload.data);
    setEstado(result.payload.data.estado);
    setError('');
  }

  useEffect(() => { load(); }, [id]);

  if (!unidad && error) return <section><p className="form-error">{error}</p><Link to="/unidades">Volver</Link></section>;
  if (!unidad) return <p>Cargando unidad…</p>;

  const opciones = admin
    ? ['OPERATIVA', 'INACTIVA', 'BAJA']
    : unidad.estado === 'BAJA' ? ['BAJA'] : ['OPERATIVA', 'INACTIVA'];

  return (
    <section>
      <div className="page-heading row-between">
        <div>
          <p className="eyebrow">Ficha de unidad</p>
          <h1>{unidad.codigo}</h1>
          <p className="lede">{unidad.placa || 'Sin placa'} · {unidad.cliente.nombre_comercial || unidad.cliente.razon_social}</p>
          <EstadoBadge estado={unidad.estado} />
        </div>
        {operate ? (
          <div className="actions">
            <Link className="button button-primary" to={`/unidades/${id}/editar`}>Editar</Link>
            <button type="button" className="button button-quiet" onClick={() => setConfirming(true)}>Eliminar</button>
          </div>
        ) : null}
      </div>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      {confirming ? (
        <div className="confirm-box">
          <p>La unidad dejará de aparecer en el listado. Su histórico se conserva.</p>
          <button type="button" className="button button-primary" onClick={async () => {
            const result = await deleteUnidad(id);
            if (!result.ok) { setError(errorMessage(result)); return; }
            navigate('/unidades');
          }}>Confirmar eliminación</button>
          <button type="button" className="button button-quiet" onClick={() => setConfirming(false)}>Cancelar</button>
        </div>
      ) : null}
      <article className="panel">
        <div className="summary-grid">
          <p><strong>Tipo</strong><span>{unidad.tipo.nombre}</span></p>
          <p><strong>Sede</strong><span>{unidad.sede?.nombre || 'Sin sede'}</span></p>
          <p><strong>Flota</strong><span>{unidad.flota?.nombre || 'Sin flota'}</span></p>
          <p><strong>Configuración</strong><span>{unidad.configuracion ? `${unidad.configuracion.nombre} · ${unidad.configuracion.cantidad_ejes} ejes / ${unidad.configuracion.cantidad_posiciones} posiciones` : 'Sin configuración'}</span></p>
          <p><strong>Marca y modelo</strong><span>{[unidad.marca, unidad.modelo].filter(Boolean).join(' ') || '—'}</span></p>
          <p><strong>Año</strong><span>{unidad.anio || '—'}</span></p>
          <p><strong>Serie</strong><span>{unidad.numero_serie || '—'}</span></p>
          <p><strong>Lecturas</strong><span>{unidad.kilometraje_actual ?? 'Sin km'} · {unidad.horometro_actual ?? 'Sin horómetro'}</span></p>
          <p><strong>Observación</strong><span>{unidad.observacion || '—'}</span></p>
        </div>
        {operate ? (
          <form className="estado-form" onSubmit={async (event) => {
            event.preventDefault();
            const result = await changeUnidadEstado(id, estado);
            if (!result.ok) { setError(errorMessage(result)); return; }
            setUnidad(result.payload.data);
          }}>
            <label>Cambiar estado
              <select value={estado} onChange={(event) => setEstado(event.target.value)}>
                {opciones.map((item) => <option key={item} value={item}>{item === 'OPERATIVA' ? 'Operativa' : item === 'INACTIVA' ? 'Inactiva' : 'Baja'}</option>)}
              </select>
            </label>
            <button className="button button-primary" type="submit">Actualizar estado</button>
          </form>
        ) : null}
      </article>
      <NeumaticosMontados unidad={unidad} operate={operate} onChanged={load} />
    </section>
  );
}
