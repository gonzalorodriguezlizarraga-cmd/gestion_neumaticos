import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { errorMessage } from '../../api/clientes';
import { deleteNeumatico, getNeumatico, listHistorialEstados, listVidas } from '../../api/neumaticos';
import { indicadoresNeumatico } from '../../api/alertas';
import { costosTexto, medida } from '../alertas/texto';
import { descartarNeumatico, getDescarte, listMantenimientosNeumatico, listMotivosDescarte } from '../../api/mantenimientos';
import { listInspeccionesNeumatico } from '../../api/inspecciones';
import { aFecha, ahoraLocal, costoTexto, lectura, mensaje } from '../mantenimientos/texto';
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
  const [mantenimientos, setMantenimientos] = useState([]);
  const [indicadores, setIndicadores] = useState(null);
  const [descarte, setDescarte] = useState(null);
  const [motivos, setMotivos] = useState([]);
  const [version, setVersion] = useState(0);
  const [tab, setTab] = useState('resumen');
  const [error, setError] = useState('');
  const [confirming, setConfirming] = useState(false);
  const [descartando, setDescartando] = useState(false);
  const [descarteForm, setDescarteForm] = useState({ motivo_descarte_id: '', fecha_descarte: ahoraLocal(), profundidad_final_mm: '', observacion: '' });

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
      const [hist, life, mounts, moves, inspections, works, scrap, reasons, metrics] = await Promise.all([
        listHistorialEstados(id),
        listVidas(id),
        listMontajesNeumatico(id),
        listMovimientosNeumatico(id),
        listInspeccionesNeumatico(id, { limit: 20, sort: 'fecha_inspeccion', order: 'desc' }),
        listMantenimientosNeumatico(id, { limit: 20, sort: 'fecha_solicitud', order: 'desc' }),
        getDescarte(id),
        listMotivosDescarte(),
        indicadoresNeumatico(id),
      ]);
      if (cancelled) return;
      setHistorial(hist.ok ? hist.payload?.data ?? [] : []);
      setVidas(life.ok ? life.payload?.data ?? [] : []);
      setMontajes(mounts.ok ? mounts.payload?.data ?? [] : []);
      setMovimientos(moves.ok ? moves.payload?.data ?? [] : []);
      setInspecciones(inspections.ok ? inspections.payload?.data ?? [] : []);
      setMantenimientos(works.ok ? works.payload?.data ?? [] : []);
      setDescarte(scrap.ok ? scrap.payload?.data ?? null : null);
      setMotivos(reasons.ok ? reasons.payload?.data ?? [] : []);
      setIndicadores(metrics.ok ? metrics.payload?.data ?? null : null);
    }
    load();
    return () => { cancelled = true; };
  }, [id, version]);

  if (!neumatico && error) return <section><p className="form-error">{error}</p><Link to="/neumaticos">Volver</Link></section>;
  if (!neumatico) return <p>Cargando neumático…</p>;

  const disponible = neumatico.estado.codigo === 'DISPONIBLE';
  const descartado = neumatico.estado.codigo === 'DESCARTADO';
  const procesoActivo = mantenimientos.some((item) => ['SOLICITADO', 'ENVIADO', 'EN_PROCESO'].includes(item.estado.codigo))
    || montajes.some((item) => item.activo);
  const puedeDescartar = operate && disponible && !procesoActivo;

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
            {puedeDescartar ? <button type="button" className="button button-quiet" onClick={() => { setDescartando(true); setConfirming(false); }}>Descartar</button> : null}
            {disponible ? <button type="button" className="button button-quiet" onClick={() => { setConfirming(true); setDescartando(false); }}>Eliminar</button> : null}
          </div>
        ) : null}
      </div>
      {descartado ? (
        <article className="panel lock-note">
          <strong>Estado final: Descartado</strong>
          <p>Fecha {lectura(descarte?.fecha_descarte)}. Motivo {descarte?.motivo?.nombre || '—'}.</p>
          {descarte?.observacion ? <p>{descarte.observacion}</p> : null}
        </article>
      ) : null}
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      {descartando ? (
        <form className="confirm-box stack-form" onSubmit={async (event) => {
          event.preventDefault();
          const body = {
            motivo_descarte_id: Number(descarteForm.motivo_descarte_id),
            fecha_descarte: aFecha(descarteForm.fecha_descarte),
          };
          if (descarteForm.profundidad_final_mm !== '') body.profundidad_final_mm = descarteForm.profundidad_final_mm;
          if (descarteForm.observacion.trim()) body.observacion = descarteForm.observacion.trim();
          const result = await descartarNeumatico(id, body);
          if (!result.ok) { setError(mensaje(result)); return; }
          setDescartando(false);
          setVersion((current) => current + 1);
        }}>
          <p>El descarte finaliza el ciclo operativo del neumático y no permitirá nuevos montajes.</p>
          <label>Motivo
            <select required value={descarteForm.motivo_descarte_id} onChange={(event) => setDescarteForm({ ...descarteForm, motivo_descarte_id: event.target.value })}>
              <option value="">Seleccione</option>
              {motivos.map((item) => <option key={item.id} value={item.id}>{item.nombre}</option>)}
            </select>
          </label>
          <label>Fecha<input type="datetime-local" required value={descarteForm.fecha_descarte} onChange={(event) => setDescarteForm({ ...descarteForm, fecha_descarte: event.target.value })} /></label>
          <label>Profundidad final (mm)<input inputMode="decimal" value={descarteForm.profundidad_final_mm} onChange={(event) => setDescarteForm({ ...descarteForm, profundidad_final_mm: event.target.value })} /></label>
          <label>Observación<textarea maxLength={2000} value={descarteForm.observacion} onChange={(event) => setDescarteForm({ ...descarteForm, observacion: event.target.value })} /></label>
          <div className="actions">
            <button type="submit" className="button button-primary">Confirmar descarte</button>
            <button type="button" className="button button-quiet" onClick={() => setDescartando(false)}>Cancelar</button>
          </div>
        </form>
      ) : null}
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
        <button type="button" className={tab === 'mantenimiento' ? 'tab active' : 'tab'} onClick={() => setTab('mantenimiento')}>Mantenimiento</button>
        <button type="button" className={tab === 'indicadores' ? 'tab active' : 'tab'} onClick={() => setTab('indicadores')}>Indicadores</button>
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
      {tab === 'indicadores' && indicadores ? (
        <article className="panel">
          <div className="summary-grid">
            <p><strong>Estado</strong><span><EstadoBadge estado={indicadores.estado.codigo} /></span></p>
            <p><strong>Criticidad</strong><span><EstadoBadge estado={indicadores.criticidad} /></span></p>
            <p><strong>Vida actual</strong><span>Vida {indicadores.vida_actual}</span></p>
            <p><strong>Reencauches</strong><span>{indicadores.cantidad_reencauches}</span></p>
            <p><strong>Profundidad actual</strong><span>{medida(indicadores.profundidad_actual_mm, 'mm')}</span></p>
            <p><strong>Profundidad mínima</strong><span>{medida(indicadores.profundidad_minima_mm, 'mm')}</span></p>
            <p><strong>Margen</strong><span>{medida(indicadores.margen_profundidad_mm, 'mm')}</span></p>
            <p><strong>Desgaste de la vida</strong><span>{medida(indicadores.desgaste_vida_mm, 'mm')}</span></p>
            <p><strong>Desgaste utilizado</strong><span>{medida(indicadores.porcentaje_desgaste_utilizado, '%')}</span></p>
            <p><strong>Última presión</strong><span>{medida(indicadores.ultima_presion_psi, 'psi')}</span></p>
            <p><strong>Última inspección</strong><span>{indicadores.ultima_inspeccion ? `${indicadores.ultima_inspeccion.fecha} · ${indicadores.ultima_inspeccion.condicion}` : 'No disponible'}</span></p>
            <p><strong>Alertas activas</strong><span>{indicadores.alertas_abiertas + indicadores.alertas_en_atencion} abiertas o en atención · {indicadores.alertas_criticas_no_finales} críticas</span></p>
            <p><strong>Montaje actual</strong><span>{indicadores.montado ? `${indicadores.unidad_actual.codigo} · ${indicadores.posicion_actual.codigo}` : 'No disponible'}</span></p>
            <p><strong>Mantenimientos</strong><span>{indicadores.mantenimientos_total} en total · {indicadores.mantenimientos_activos} activos · {indicadores.mantenimientos_finalizados} finalizados</span></p>
            <p><strong>Costo de mantenimiento</strong><span>{costosTexto(indicadores.costo_mantenimiento_total)}</span></p>
            <p><strong>Adquisición</strong><span>{indicadores.costo_adquisicion ? `${indicadores.costo_adquisicion} ${indicadores.moneda_adquisicion || ''}` : 'No disponible'}</span></p>
            <p><strong>Kilómetros de la vida</strong><span>No disponible</span></p>
            <p><strong>Costo por kilómetro</strong><span>No disponible</span></p>
            <p><strong>Proyección</strong><span>No disponible</span></p>
          </div>
          <p>{indicadores.rendimiento.motivo}</p>
        </article>
      ) : null}
      {tab === 'mantenimiento' ? (
        <table className="data-table">
          <thead>
            <tr><th>Tipo</th><th>Estado</th><th>Fecha</th><th>Costo</th><th>Tercero</th><th>Acciones</th></tr>
          </thead>
          <tbody>
            {mantenimientos.length === 0 ? <tr><td colSpan={6}>Este neumático todavía no tiene mantenimientos.</td></tr> : mantenimientos.map((item) => (
              <tr key={item.id}>
                <td data-label="Tipo">{item.tipo.nombre}</td>
                <td data-label="Estado"><EstadoBadge estado={item.estado.codigo} /></td>
                <td data-label="Fecha">{lectura(item.fecha_solicitud)}</td>
                <td data-label="Costo">{costoTexto(item.costo, item.moneda)}</td>
                <td data-label="Tercero">{item.tercero_nombre || '—'}</td>
                <td data-label="Acciones"><Link to={`/mantenimientos/${item.id}`}>Abrir</Link></td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : null}
    </section>
  );
}
