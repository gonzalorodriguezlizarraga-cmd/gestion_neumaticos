import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { errorMessage } from '../../api/clientes';
import {
  actualizarDano,
  actualizarDetalle,
  agregarDano,
  bajarArchivo,
  crearDetalle,
  eliminarArchivo,
  finalizarInspeccion,
  getInspeccion,
  listTiposDano,
  quitarDano,
  subirFotoDetalle,
  subirFotoInspeccion,
  updateInspeccion,
} from '../../api/inspecciones';
import { getConfiguracion, getUnidad } from '../../api/unidades';
import { listMontajesActivos } from '../../api/operaciones';
import { useAuth } from '../../auth/AuthContext';
import { EstadoBadge } from '../../components/EstadoBadge';
import { canEditInspeccion } from '../../utils/access';
import { diferenciaCanales, fechaApi, fechaLocal, ladoTexto, lectura, numeroONull, ubicacionTexto } from './texto';

const VACIO = { interior: '', centro: '', exterior: '', presion: '', condicion: 'NORMAL', observacion: '' };

export function InspeccionDetallePage() {
  const { id } = useParams();
  const { user } = useAuth();
  const [inspeccion, setInspeccion] = useState(null);
  const [ejes, setEjes] = useState([]);
  const [ocupados, setOcupados] = useState([]);
  const [tipos, setTipos] = useState([]);
  const [cabecera, setCabecera] = useState({ fecha: '', km: '', horometro: '', observacion: '' });
  const [error, setError] = useState('');
  const [confirming, setConfirming] = useState(false);
  const [saving, setSaving] = useState(false);

  async function load() {
    const result = await getInspeccion(id);
    if (!result.ok) {
      setError(errorMessage(result));
      setInspeccion(null);
      return;
    }
    const data = result.payload.data;
    setInspeccion(data);
    setCabecera({
      fecha: fechaLocal(data.fecha_inspeccion),
      km: data.kilometraje ?? '',
      horometro: data.horometro ?? '',
      observacion: data.observacion_general || '',
    });
    setError('');
    if (data.estado !== 'BORRADOR') {
      setEjes([]);
      setOcupados([]);
      return;
    }
    const unidad = await getUnidad(data.unidad.id);
    if (!unidad.ok || !unidad.payload?.data?.configuracion?.id) {
      setEjes([]);
      setOcupados([]);
      return;
    }
    const [config, montajes] = await Promise.all([
      getConfiguracion(unidad.payload.data.configuracion.id),
      listMontajesActivos(data.unidad.id),
    ]);
    setEjes(config.ok ? config.payload?.data?.ejes ?? [] : []);
    setOcupados(montajes.ok ? montajes.payload?.data ?? [] : []);
  }

  useEffect(() => { load(); }, [id]);
  useEffect(() => {
    listTiposDano().then((result) => setTipos(result.ok ? result.payload?.data ?? [] : []));
  }, []);

  if (!inspeccion && error) return <section><p className="form-error">{error}</p><Link to="/inspecciones">Volver</Link></section>;
  if (!inspeccion) return <p>Cargando inspección…</p>;

  const editable = canEditInspeccion(user, inspeccion);
  const porPosicion = new Map(inspeccion.detalles.map((detalle) => [detalle.posicion.id, detalle]));
  const porMontaje = new Map(ocupados.map((item) => [item.posicion.id, item]));
  const fotos = inspeccion.archivos.length + inspeccion.detalles.reduce((total, detalle) => total + detalle.archivos.length, 0);
  const danos = inspeccion.detalles.reduce((total, detalle) => total + detalle.danos.length, 0);
  const criticos = inspeccion.detalles.filter((detalle) => detalle.condicion === 'CRITICO').length;
  const atencion = inspeccion.detalles.filter((detalle) => detalle.condicion === 'ATENCION').length;

  async function guardarCabecera(event) {
    event.preventDefault();
    setSaving(true);
    const result = await updateInspeccion(id, {
      fecha_inspeccion: fechaApi(cabecera.fecha),
      kilometraje: cabecera.km === '' ? null : Number(cabecera.km),
      horometro: cabecera.horometro === '' ? null : String(cabecera.horometro),
      observacion_general: cabecera.observacion || null,
    });
    setSaving(false);
    if (!result.ok) { setError(errorMessage(result)); return; }
    setInspeccion(result.payload.data);
    setError('');
  }

  async function finalizar() {
    setSaving(true);
    const result = await finalizarInspeccion(id);
    setSaving(false);
    setConfirming(false);
    if (!result.ok) { setError(errorMessage(result)); return; }
    setInspeccion(result.payload.data);
    setEjes([]);
    setOcupados([]);
    setError('');
  }

  return (
    <section>
      <div className="page-heading row-between">
        <div>
          <p className="eyebrow">Inspección</p>
          <h1>{inspeccion.unidad.codigo}</h1>
          <p className="lede">{lectura(inspeccion.fecha_inspeccion)} · {inspeccion.cliente.nombre_comercial || inspeccion.cliente.razon_social} · {inspeccion.tecnico.nombre}</p>
          <EstadoBadge estado={inspeccion.estado} />
        </div>
        <Link className="button button-quiet" to="/inspecciones">Volver al listado</Link>
      </div>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      {!editable ? <p className="lede">Esta inspección está en solo lectura. Las mediciones, daños y fotografías no se modifican.</p> : null}
      <article className="panel">
        {editable ? (
          <form className="stack-form" onSubmit={guardarCabecera}>
            <div className="measure-grid">
              <label>Fecha
                <input type="datetime-local" required value={cabecera.fecha} onChange={(event) => setCabecera({ ...cabecera, fecha: event.target.value })} />
              </label>
              <label>Kilometraje
                <input type="number" min="0" step="1" value={cabecera.km} onChange={(event) => setCabecera({ ...cabecera, km: event.target.value })} />
              </label>
              <label>Horómetro
                <input type="number" min="0" step="0.01" value={cabecera.horometro} onChange={(event) => setCabecera({ ...cabecera, horometro: event.target.value })} />
              </label>
            </div>
            <label>Observación general
              <textarea value={cabecera.observacion} maxLength={2000} onChange={(event) => setCabecera({ ...cabecera, observacion: event.target.value })} />
            </label>
            <button className="button button-primary" type="submit" disabled={saving}>Guardar cabecera</button>
          </form>
        ) : (
          <div className="summary-grid">
            <p><strong>Fecha</strong><span>{lectura(inspeccion.fecha_inspeccion)}</span></p>
            <p><strong>Kilometraje</strong><span>{inspeccion.kilometraje ?? 'Sin km'}</span></p>
            <p><strong>Horómetro</strong><span>{inspeccion.horometro ?? 'Sin horómetro'}</span></p>
            <p><strong>Observación</strong><span>{inspeccion.observacion_general || '—'}</span></p>
            <p><strong>Finalizada</strong><span>{lectura(inspeccion.finalizada_en)}</span></p>
          </div>
        )}
        <Fotos archivos={inspeccion.archivos} editable={editable} onUpload={(file) => subirFotoInspeccion(id, file)} onChanged={load} onError={setError} />
      </article>

      <div className="axle-list">
        {ejes.length > 0 ? ejes.map((eje) => (
          <details key={eje.id} className="axle-card panel" open>
            <summary>Eje {eje.numero_eje} · {eje.nombre}</summary>
            <div className="position-grid">
              {eje.posiciones.filter((posicion) => posicion.activo || porPosicion.has(posicion.id)).map((posicion) => (
                <Posicion
                  key={posicion.id}
                  inspeccionId={id}
                  posicion={posicion}
                  detalle={porPosicion.get(posicion.id)}
                  montaje={porMontaje.get(posicion.id)}
                  editable={editable}
                  tipos={tipos}
                  onChanged={load}
                  onError={setError}
                />
              ))}
            </div>
          </details>
        )) : agrupar(inspeccion.detalles).map((grupo) => (
          <details key={grupo.numero} className="axle-card panel" open>
            <summary>Eje {grupo.numero} · {grupo.nombre}</summary>
            <div className="position-grid">
              {grupo.detalles.map((detalle) => (
                <Posicion
                  key={detalle.id}
                  inspeccionId={id}
                  posicion={detalle.posicion}
                  detalle={detalle}
                  montaje={null}
                  editable={false}
                  tipos={tipos}
                  onChanged={load}
                  onError={setError}
                />
              ))}
            </div>
          </details>
        ))}
      </div>

      <article className="panel">
        <h2>Revisión</h2>
        <div className="summary-grid">
          <p><strong>Posiciones inspeccionadas</strong><span>{inspeccion.detalles.length}</span></p>
          <p><strong>Críticos</strong><span>{criticos}</span></p>
          <p><strong>Atención</strong><span>{atencion}</span></p>
          <p><strong>Daños</strong><span>{danos}</span></p>
          <p><strong>Fotos</strong><span>{fotos}</span></p>
        </div>
        {editable ? (
          confirming ? (
            <div className="confirm-box">
              <p>Al finalizar, la inspección queda en solo lectura y se generan las alertas de profundidad crítica y daño relevante.</p>
              <button type="button" className="button button-primary" disabled={saving || inspeccion.detalles.length === 0} onClick={finalizar}>Confirmar finalización</button>
              <button type="button" className="button button-quiet" onClick={() => setConfirming(false)}>Cancelar</button>
            </div>
          ) : (
            <button type="button" className="button button-primary" disabled={inspeccion.detalles.length === 0} onClick={() => setConfirming(true)}>
              {inspeccion.detalles.length === 0 ? 'Agregue al menos una medición' : 'Finalizar inspección'}
            </button>
          )
        ) : null}
      </article>
    </section>
  );
}

function agrupar(detalles) {
  const grupos = new Map();
  detalles.forEach((detalle) => {
    const numero = detalle.posicion.eje_numero;
    if (!grupos.has(numero)) {
      grupos.set(numero, { numero, nombre: detalle.posicion.eje_nombre, detalles: [] });
    }
    grupos.get(numero).detalles.push(detalle);
  });
  return [...grupos.values()];
}

function Posicion({ inspeccionId, posicion, detalle, montaje, editable, tipos, onChanged, onError }) {
  const [form, setForm] = useState(() => desdeDetalle(detalle));
  const [dano, setDano] = useState({ tipo: '', severidad: '', observacion: '' });
  const [saving, setSaving] = useState(false);
  const neumatico = detalle?.neumatico || montaje?.neumatico;

  useEffect(() => { setForm(desdeDetalle(detalle)); }, [detalle]);

  if (!detalle && !montaje) {
    return (
      <article className="position-card libre">
        <strong>{posicion.codigo}</strong>
        <span>{ladoTexto(posicion.lado)} · {ubicacionTexto(posicion.ubicacion)}</span>
        <span>Sin neumático</span>
      </article>
    );
  }

  async function guardar(event) {
    event.preventDefault();
    setSaving(true);
    const body = {
      profundidad_interior_mm: numeroONull(form.interior),
      profundidad_centro_mm: numeroONull(form.centro),
      profundidad_exterior_mm: numeroONull(form.exterior),
      presion_psi: numeroONull(form.presion),
      condicion: form.condicion,
      observacion: form.observacion || null,
    };
    const result = detalle
      ? await actualizarDetalle(inspeccionId, detalle.id, body)
      : await crearDetalle(inspeccionId, { ...body, posicion_id: posicion.id });
    setSaving(false);
    if (!result.ok) { onError(errorMessage(result)); return; }
    onError('');
    onChanged();
  }

  async function sumarDano(event) {
    event.preventDefault();
    const result = await agregarDano(inspeccionId, detalle.id, {
      tipo_dano_id: Number(dano.tipo),
      severidad: dano.severidad || null,
      observacion: dano.observacion || null,
    });
    if (!result.ok) { onError(errorMessage(result)); return; }
    setDano({ tipo: '', severidad: '', observacion: '' });
    onError('');
    onChanged();
  }

  const diferencia = detalle ? diferenciaCanales(detalle) : null;

  return (
    <article className="position-card">
      <strong>{posicion.codigo}</strong>
      <span>{ladoTexto(posicion.lado)} · {ubicacionTexto(posicion.ubicacion)}</span>
      <span>{neumatico ? `${neumatico.codigo} · ${neumatico.medida}` : 'Sin neumático'}</span>
      {detalle ? <EstadoBadge estado={detalle.condicion} /> : <span className="badge badge-borrador">Sin inspeccionar</span>}
      {editable ? (
        <form className="stack-form" onSubmit={guardar}>
          <div className="measure-grid">
            <label>Interior mm<input type="number" min="0" step="0.01" value={form.interior} onChange={(event) => setForm({ ...form, interior: event.target.value })} /></label>
            <label>Centro mm<input type="number" min="0" step="0.01" value={form.centro} onChange={(event) => setForm({ ...form, centro: event.target.value })} /></label>
            <label>Exterior mm<input type="number" min="0" step="0.01" value={form.exterior} onChange={(event) => setForm({ ...form, exterior: event.target.value })} /></label>
            <label>Presión psi<input type="number" min="0" step="0.01" value={form.presion} onChange={(event) => setForm({ ...form, presion: event.target.value })} /></label>
          </div>
          <label>Condición
            <select value={form.condicion} onChange={(event) => setForm({ ...form, condicion: event.target.value })}>
              <option value="NORMAL">Normal</option>
              <option value="ATENCION">Atención</option>
              <option value="CRITICO">Crítico</option>
            </select>
          </label>
          <label>Observación
            <textarea value={form.observacion} maxLength={2000} onChange={(event) => setForm({ ...form, observacion: event.target.value })} />
          </label>
          <button className="button button-primary" type="submit" disabled={saving}>{detalle ? 'Actualizar medición' : 'Guardar medición'}</button>
        </form>
      ) : detalle ? (
        <div className="summary-grid">
          <p><strong>Interior</strong><span>{detalle.profundidad_interior_mm ?? '—'}</span></p>
          <p><strong>Centro</strong><span>{detalle.profundidad_centro_mm ?? '—'}</span></p>
          <p><strong>Exterior</strong><span>{detalle.profundidad_exterior_mm ?? '—'}</span></p>
          <p><strong>Presión</strong><span>{detalle.presion_psi ?? '—'}</span></p>
          <p><strong>Observación</strong><span>{detalle.observacion || '—'}</span></p>
        </div>
      ) : null}
      {diferencia !== null ? <span>Diferencia I/C/E: {diferencia} mm</span> : null}
      {detalle ? (
        <>
          <ul className="record-list">
            {detalle.danos.length === 0 ? <li>Sin daños registrados.</li> : detalle.danos.map((item) => (
              <li key={item.tipo_dano_id}>
                <div>
                  <strong>{item.nombre}</strong>
                  <span>{item.severidad || 'Sin severidad'}{item.observacion ? ` · ${item.observacion}` : ''}</span>
                </div>
                {editable ? (
                  <div className="actions">
                    <select aria-label={`Severidad de ${item.nombre}`} value={item.severidad || ''} onChange={async (event) => {
                      const result = await actualizarDano(inspeccionId, detalle.id, item.tipo_dano_id, {
                        tipo_dano_id: item.tipo_dano_id,
                        severidad: event.target.value || null,
                        observacion: item.observacion,
                      });
                      if (!result.ok) { onError(errorMessage(result)); return; }
                      onChanged();
                    }}>
                      <option value="">Sin informar</option>
                      <option value="LEVE">Leve</option>
                      <option value="MEDIA">Media</option>
                      <option value="ALTA">Alta</option>
                      <option value="CRITICA">Crítica</option>
                    </select>
                    <button type="button" className="button button-quiet" onClick={async () => {
                      const result = await quitarDano(inspeccionId, detalle.id, item.tipo_dano_id);
                      if (!result.ok) { onError(errorMessage(result)); return; }
                      onChanged();
                    }}>Quitar</button>
                  </div>
                ) : null}
              </li>
            ))}
          </ul>
          {editable ? (
            <form className="stack-form" onSubmit={sumarDano}>
              <label>Daño
                <select required value={dano.tipo} onChange={(event) => setDano({ ...dano, tipo: event.target.value })}>
                  <option value="">Seleccione</option>
                  {tipos.map((tipo) => <option key={tipo.id} value={tipo.id}>{tipo.nombre}</option>)}
                </select>
              </label>
              <label>Severidad
                <select value={dano.severidad} onChange={(event) => setDano({ ...dano, severidad: event.target.value })}>
                  <option value="">Sin informar</option>
                  <option value="LEVE">Leve</option>
                  <option value="MEDIA">Media</option>
                  <option value="ALTA">Alta</option>
                  <option value="CRITICA">Crítica</option>
                </select>
              </label>
              <label>Nota
                <input value={dano.observacion} maxLength={500} onChange={(event) => setDano({ ...dano, observacion: event.target.value })} />
              </label>
              <button className="button button-primary" type="submit">Agregar daño</button>
            </form>
          ) : null}
          <Fotos
            archivos={detalle.archivos}
            editable={editable}
            onUpload={(file) => subirFotoDetalle(inspeccionId, detalle.id, file)}
            onChanged={onChanged}
            onError={onError}
          />
        </>
      ) : null}
    </article>
  );
}

function desdeDetalle(detalle) {
  if (!detalle) return VACIO;
  return {
    interior: detalle.profundidad_interior_mm ?? '',
    centro: detalle.profundidad_centro_mm ?? '',
    exterior: detalle.profundidad_exterior_mm ?? '',
    presion: detalle.presion_psi ?? '',
    condicion: detalle.condicion,
    observacion: detalle.observacion || '',
  };
}

function Fotos({ archivos, editable, onUpload, onChanged, onError }) {
  async function subir(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
      onError('Solo se permiten fotografías JPEG, PNG o WEBP.');
      return;
    }
    if (file.size > 5242880) {
      onError('La fotografía supera el tamaño permitido.');
      return;
    }
    const result = await onUpload(file);
    if (!result.ok) { onError(errorMessage(result)); return; }
    onError('');
    onChanged();
  }

  return (
    <div>
      <div className="photo-strip">
        {archivos.map((archivo) => <Foto key={archivo.id} archivo={archivo} editable={editable} onChanged={onChanged} onError={onError} />)}
      </div>
      {editable ? <label className="field">Adjuntar fotografía<input type="file" accept="image/jpeg,image/png,image/webp" onChange={subir} /></label> : null}
    </div>
  );
}

function Foto({ archivo, editable, onChanged, onError }) {
  const [url, setUrl] = useState('');

  useEffect(() => {
    let activo = true;
    let objeto = '';
    bajarArchivo(archivo.id).then((result) => {
      if (!activo || !result.ok || !result.blob) return;
      objeto = URL.createObjectURL(result.blob);
      setUrl(objeto);
    });
    return () => {
      activo = false;
      if (objeto) URL.revokeObjectURL(objeto);
    };
  }, [archivo.id]);

  return (
    <figure>
      {url ? <img src={url} alt={archivo.nombre_original} /> : <span>Cargando foto…</span>}
      <figcaption>{archivo.nombre_original}</figcaption>
      {editable ? (
        <button type="button" className="button button-quiet" onClick={async () => {
          const result = await eliminarArchivo(archivo.id);
          if (!result.ok) { onError(errorMessage(result)); return; }
          onChanged();
        }}>Quitar foto</button>
      ) : null}
    </figure>
  );
}
