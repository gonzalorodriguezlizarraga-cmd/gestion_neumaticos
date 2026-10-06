import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { errorMessage } from '../../api/clientes';
import { deleteNeumatico, getNeumatico, listHistorialEstados, listVidas } from '../../api/neumaticos';
import { listInspeccionesNeumatico } from '../../api/inspecciones';
import { listMontajesNeumatico, listMovimientosNeumatico } from '../../api/operaciones';
import { InspeccionesTabla } from '../inspecciones/InspeccionesTabla';
import { EstadoBadge } from '../../components/EstadoBadge';
import { useAuth } from '../../auth/AuthContext';
import { canManageNeumaticos } from '../../utils/access';
import { MontajeNeumatico } from '../operaciones/MontajeNeumatico';
import { MovimientosTabla } from '../operaciones/MovimientosTabla';

export function NeumaticoDetallePage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const operate = canManageNeumaticos(user);
  const [neumatico, setNeumatico] = useState(null);
  const [historial, setHistorial] = useState([]);
  const [vidas, setVidas] = useState([]);
  const [montajes, setMontajes] = useState([]);
  const [movimientos, setMovimientos] = useState([]);
  const [inspecciones, setInspecciones] = useState([]);
  const [version, setVersion] = useState(0);
  const [tab, setTab] = useState('resumen');
  const [error, setError] = useState('');
  const [confirming, setConfirming] = useState(false);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      const result = await getNeumatico(id);
      if (cancelled) return;
      if (!result.ok) {
        setError(errorMessage(result));
        setNeumatico(null);
        return;
      }
      setNeumatico(result.payload.data);
      setError('');
      const [hist, life, mounts, moves, inspections] = await Promise.all([
        listHistorialEstados(id),
        listVidas(id),
        listMontajesNeumatico(id),
        listMovimientosNeumatico(id),
        listInspeccionesNeumatico(id, { limit: 20, sort: 'fecha_inspeccion', order: 'desc' }),
      ]);
      if (cancelled) return;
      setHistorial(hist.ok ? hist.payload?.data ?? [] : []);
      setVidas(life.ok ? life.payload?.data ?? [] : []);
      setMontajes(mounts.ok ? mounts.payload?.data ?? [] : []);
      setMovimientos(moves.ok ? moves.payload?.data ?? [] : []);
      setInspecciones(inspections.ok ? inspections.payload?.data ?? [] : []);
    }
    load();
    return () => { cancelled = true; };
  }, [id, version]);

  if (!neumatico && error) return <section><p className="form-error">{error}</p><Link to="/neumaticos">Volver</Link></section>;
  if (!neumatico) return <p>Cargando neumático…</p>;

  const disponible = neumatico.estado.codigo === 'DISPONIBLE';

  return (
    <section>
      <div className="page-heading row-between">
        <div>
          <p className="eyebrow">Ficha de neumático</p>
          <h1>{neumatico.codigo}</h1>
          <p className="lede">{neumatico.numero_serie || 'Sin serie'} · {neumatico.cliente.nombre_comercial || neumatico.cliente.razon_social}</p>
          <EstadoBadge estado={neumatico.estado.codigo} />
          <span className="badge badge-activo">Vida {neumatico.vida_actual}</span>
        </div>
        {operate ? (
          <div className="actions">
            <Link className="button button-primary" to={`/neumaticos/${id}/editar`}>Editar</Link>
            {disponible ? <button type="button" className="button button-quiet" onClick={() => setConfirming(true)}>Eliminar</button> : null}
          </div>
        ) : null}
      </div>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      {confirming ? (
        <div className="confirm-box">
          <p>El neumático dejará de aparecer en el listado. Su código, historial y vidas se conservan.</p>
          <button type="button" className="button button-primary" onClick={async () => {
            const result = await deleteNeumatico(id);
            if (!result.ok) { setError(errorMessage(result)); setConfirming(false); return; }
            navigate('/neumaticos');
          }}>Confirmar eliminación</button>
          <button type="button" className="button button-quiet" onClick={() => setConfirming(false)}>Cancelar</button>
        </div>
      ) : null}
      <div className="tabs" role="tablist">
        <button type="button" className={tab === 'resumen' ? 'tab active' : 'tab'} onClick={() => setTab('resumen')}>Resumen</button>
        <button type="button" className={tab === 'historial' ? 'tab active' : 'tab'} onClick={() => setTab('historial')}>Historial de estados</button>
        <button type="button" className={tab === 'vidas' ? 'tab active' : 'tab'} onClick={() => setTab('vidas')}>Vidas</button>
        <button type="button" className={tab === 'montajes' ? 'tab active' : 'tab'} onClick={() => setTab('montajes')}>Montajes</button>
        <button type="button" className={tab === 'movimientos' ? 'tab active' : 'tab'} onClick={() => setTab('movimientos')}>Movimientos</button>
        <button type="button" className={tab === 'inspecciones' ? 'tab active' : 'tab'} onClick={() => setTab('inspecciones')}>Inspecciones</button>
      </div>
      {operate && disponible ? <MontajeNeumatico neumatico={neumatico} onDone={() => setVersion((current) => current + 1)} /> : null}
      {tab === 'resumen' ? (
        <article className="panel">
          <div className="summary-grid">
            <p><strong>Cliente</strong><span>{neumatico.cliente.nombre_comercial || neumatico.cliente.razon_social}</span></p>
            <p><strong>Marca</strong><span>{neumatico.marca.nombre}</span></p>
            <p><strong>Modelo</strong><span>{neumatico.modelo.nombre}</span></p>
            <p><strong>Medida</strong><span>{neumatico.medida.descripcion}</span></p>
            <p><strong>Estado</strong><span>{neumatico.estado.nombre}</span></p>
            <p><strong>Vida actual</strong><span>Vida {neumatico.vida_actual}</span></p>
            <p><strong>Profundidad</strong><span>{neumatico.profundidad_inicial_mm ?? '—'} mm inicial · {neumatico.profundidad_minima_mm} mm mínima</span></p>
            <p><strong>Adquisición</strong><span>{neumatico.fecha_adquisicion || 'Sin fecha'}{neumatico.costo_adquisicion ? ` · ${neumatico.costo_adquisicion} ${neumatico.moneda || ''}` : ''}</span></p>
            <p><strong>Observación</strong><span>{neumatico.observacion || '—'}</span></p>
          </div>
        </article>
      ) : null}
      {tab === 'historial' ? (
        <ul className="record-list">
          {historial.length === 0 ? <li>No hay cambios de estado.</li> : historial.map((item, index) => (
            <li key={`${item.fecha_cambio}-${index}`}>
              <div>
                <strong>{item.estado_anterior ? `${item.estado_anterior.nombre} → ${item.estado_nuevo.nombre}` : item.estado_nuevo.nombre}</strong>
                <span>{item.motivo || 'Sin motivo'}</span>
              </div>
              <span>{item.fecha_cambio} · {item.usuario}</span>
            </li>
          ))}
        </ul>
      ) : null}
      {tab === 'vidas' ? (
        <ul className="record-list">
          {vidas.length === 0 ? <li>No hay vidas registradas.</li> : vidas.map((item) => (
            <li key={item.numero_vida}>
              <div>
                <strong>Vida {item.numero_vida}</strong>
                <span>{item.fecha_fin ? `Cerrada · ${item.motivo_fin || 'Sin motivo'}` : 'Vigente'}</span>
              </div>
              <span>
                {item.fecha_inicio}{item.fecha_fin ? ` → ${item.fecha_fin}` : ''}
                {' · '}{item.profundidad_inicial_mm ?? '—'} mm
                {item.profundidad_final_mm ? ` → ${item.profundidad_final_mm} mm` : ''}
              </span>
            </li>
          ))}
        </ul>
      ) : null}
      {tab === 'montajes' ? (
        <table className="data-table">
          <thead>
            <tr>
              <th>Unidad</th><th>Posición</th><th>Montaje</th><th>Desmontaje</th><th>Km</th><th>Horómetro</th><th>Motivo</th><th>Estado</th>
            </tr>
          </thead>
          <tbody>
            {montajes.length === 0 ? <tr><td colSpan={8}>No hay montajes.</td></tr> : montajes.map((item) => (
              <tr key={item.id}>
                <td data-label="Unidad"><Link to={`/unidades/${item.unidad.id}`}>{item.unidad.codigo}</Link></td>
                <td data-label="Posición">{item.posicion.codigo}</td>
                <td data-label="Montaje">{item.fecha_montaje}</td>
                <td data-label="Desmontaje">{item.fecha_desmontaje || '—'}</td>
                <td data-label="Km">{item.km_montaje ?? '—'} / {item.km_desmontaje ?? '—'}</td>
                <td data-label="Horómetro">{item.horometro_montaje ?? '—'} / {item.horometro_desmontaje ?? '—'}</td>
                <td data-label="Motivo">{item.motivo_desmontaje || '—'}</td>
                <td data-label="Estado">{item.activo ? 'Activo' : 'Cerrado'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : null}
      {tab === 'movimientos' ? <MovimientosTabla rows={movimientos} conNeumatico={false} /> : null}
      {tab === 'inspecciones' ? <InspeccionesTabla rows={inspecciones} vacio="Este neumático todavía no tiene inspecciones." /> : null}
    </section>
  );
}
