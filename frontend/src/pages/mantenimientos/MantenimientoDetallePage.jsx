import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { errorMessage } from '../../api/clientes';
import { bajarArchivo, eliminarArchivo } from '../../api/inspecciones';
import {
  cancelarMantenimiento,
  enviarMantenimiento,
  finalizarMantenimiento,
  getMantenimiento,
  iniciarMantenimiento,
  subirArchivoMantenimiento,
} from '../../api/mantenimientos';
import { useAuth } from '../../auth/AuthContext';
import { EstadoBadge } from '../../components/EstadoBadge';
import { canManageNeumaticos } from '../../utils/access';
import { aFecha, ahoraLocal, costoTexto, lectura, mensaje } from './texto';

export function MantenimientoDetallePage() {
  const { id } = useParams();
  const { user } = useAuth();
  const operate = canManageNeumaticos(user);
  const [ficha, setFicha] = useState(null);
  const [error, setError] = useState('');
  const [panel, setPanel] = useState('');
  const [cierre, setCierre] = useState(null);
  const [envio, setEnvio] = useState({ fecha_envio: ahoraLocal(), observacion: '' });
  const [fin, setFin] = useState({ fecha_retorno: ahoraLocal(), costo: '', moneda: 'PEN', profundidad_despues_mm: '', observacion: '' });
  const [cancelacion, setCancelacion] = useState('');

  const cargar = useCallback(async () => {
    const result = await getMantenimiento(id);
    if (!result.ok) {
      setError(errorMessage(result));
      setFicha(null);
      return;
    }
    setError('');
    setFicha(result.payload.data);
  }, [id]);

  useEffect(() => { cargar(); }, [cargar]);

  if (!ficha && error) return <section><p className="form-error">{error}</p><Link to="/mantenimientos">Volver</Link></section>;
  if (!ficha) return <p>Cargando mantenimiento…</p>;

  const estado = ficha.estado.codigo;
  const abierto = !ficha.estado.es_final;
  const reencauche = ficha.tipo.codigo === 'REENCAUCHE';

  async function ejecutar(accion, body) {
    setError('');
    const result = await accion(id, body);
    if (!result.ok) {
      setError(mensaje(result));
      return;
    }
    setPanel('');
    if (result.payload?.data?.vida_nueva) setCierre(result.payload.data);
    await cargar();
  }

  return (
    <section>
      <div className="page-heading row-between">
        <div>
          <p className="eyebrow">Mantenimiento</p>
          <h1>{ficha.tipo.nombre}</h1>
          <p className="lede">
            <Link to={`/neumaticos/${ficha.neumatico.id}`}>{ficha.neumatico.codigo}</Link>
            {' · '}{ficha.cliente.nombre_comercial || ficha.cliente.razon_social}
          </p>
          <EstadoBadge estado={estado} />
          <span className="badge badge-activo">Vida {ficha.neumatico.vida_actual}</span>
        </div>
        <Link className="button button-quiet" to="/mantenimientos">Volver al listado</Link>
      </div>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      {cierre?.vida_nueva ? (
        <p className="lock-note">
          Vida {cierre.vida_anterior} cerrada. Nueva Vida {cierre.vida_nueva}. Profundidad nueva {cierre.profundidad_despues_mm ?? '—'} mm.
        </p>
      ) : null}
      {reencauche && abierto ? <p className="lock-note">Al finalizar se cerrará la Vida actual y se abrirá una nueva Vida.</p> : null}
      <article className="panel">
        <div className="summary-grid">
          <p><strong>Neumático</strong><span>{ficha.neumatico.codigo} · {ficha.neumatico.estado}</span></p>
          <p><strong>Tipo</strong><span>{ficha.tipo.nombre}</span></p>
          <p><strong>Estado</strong><span>{ficha.estado.nombre}</span></p>
          <p><strong>Solicitud</strong><span>{lectura(ficha.fecha_solicitud)}</span></p>
          <p><strong>Envío</strong><span>{lectura(ficha.fecha_envio)}</span></p>
          <p><strong>Retorno</strong><span>{lectura(ficha.fecha_retorno)}</span></p>
          <p><strong>Tercero</strong><span>{ficha.tercero_nombre || '—'}</span></p>
          <p><strong>Costo</strong><span>{costoTexto(ficha.costo, ficha.moneda)}</span></p>
          <p><strong>Profundidad</strong><span>{ficha.profundidad_antes_mm ?? '—'} mm antes · {ficha.profundidad_despues_mm ?? '—'} mm después</span></p>
          <p><strong>Observación</strong><span>{ficha.observacion || '—'}</span></p>
        </div>
      </article>
      {operate && estado === 'SOLICITADO' ? (
        <div className="actions">
          <button type="button" className="button button-primary" onClick={() => setPanel(panel === 'enviar' ? '' : 'enviar')}>Enviar</button>
          <button type="button" className="button button-quiet" onClick={() => setPanel(panel === 'cancelar' ? '' : 'cancelar')}>Cancelar</button>
        </div>
      ) : null}
      {operate && estado === 'ENVIADO' ? (
        <div className="actions">
          <button type="button" className="button button-primary" onClick={() => ejecutar(iniciarMantenimiento, { observacion: 'Inicio de proceso' })}>Iniciar</button>
          <button type="button" className="button button-primary" onClick={() => setPanel(panel === 'finalizar' ? '' : 'finalizar')}>Finalizar</button>
          <button type="button" className="button button-quiet" onClick={() => setPanel(panel === 'cancelar' ? '' : 'cancelar')}>Cancelar</button>
        </div>
      ) : null}
      {operate && estado === 'EN_PROCESO' ? (
        <div className="actions">
          <button type="button" className="button button-primary" onClick={() => setPanel(panel === 'finalizar' ? '' : 'finalizar')}>Finalizar</button>
          <button type="button" className="button button-quiet" onClick={() => setPanel(panel === 'cancelar' ? '' : 'cancelar')}>Cancelar</button>
        </div>
      ) : null}
      {panel === 'enviar' ? (
        <form className="stack-form" onSubmit={(event) => { event.preventDefault(); ejecutar(enviarMantenimiento, { fecha_envio: aFecha(envio.fecha_envio), observacion: envio.observacion || null }); }}>
          <label>Fecha de envío<input type="datetime-local" required value={envio.fecha_envio} onChange={(event) => setEnvio({ ...envio, fecha_envio: event.target.value })} /></label>
          <label>Observación<input value={envio.observacion} maxLength={500} onChange={(event) => setEnvio({ ...envio, observacion: event.target.value })} /></label>
          <button className="button button-primary" type="submit">Confirmar envío</button>
        </form>
      ) : null}
      {panel === 'finalizar' ? (
        <form className="stack-form" onSubmit={(event) => {
          event.preventDefault();
          const body = { fecha_retorno: aFecha(fin.fecha_retorno), observacion: fin.observacion || null };
          if (fin.costo !== '') { body.costo = fin.costo; body.moneda = fin.moneda.trim().toUpperCase(); }
          if (fin.profundidad_despues_mm !== '') body.profundidad_despues_mm = fin.profundidad_despues_mm;
          ejecutar(finalizarMantenimiento, body);
        }}>
          <label>Fecha de retorno<input type="datetime-local" required value={fin.fecha_retorno} onChange={(event) => setFin({ ...fin, fecha_retorno: event.target.value })} /></label>
          <label>Costo<input inputMode="decimal" value={fin.costo} onChange={(event) => setFin({ ...fin, costo: event.target.value })} /></label>
          <label>Moneda<input maxLength={3} value={fin.moneda} onChange={(event) => setFin({ ...fin, moneda: event.target.value })} /></label>
          <label>Profundidad después (mm)<input inputMode="decimal" value={fin.profundidad_despues_mm} onChange={(event) => setFin({ ...fin, profundidad_despues_mm: event.target.value })} /></label>
          <label>Observación<textarea maxLength={2000} value={fin.observacion} onChange={(event) => setFin({ ...fin, observacion: event.target.value })} /></label>
          <button className="button button-primary" type="submit">Confirmar finalización</button>
        </form>
      ) : null}
      {panel === 'cancelar' ? (
        <div className="confirm-box">
          <p>La cancelación cierra el mantenimiento. Si el neumático ya fue enviado, vuelve a quedar disponible y no se abre una vida nueva.</p>
          <label className="field">Observación<input value={cancelacion} maxLength={500} onChange={(event) => setCancelacion(event.target.value)} /></label>
          <button type="button" className="button button-primary" onClick={() => ejecutar(cancelarMantenimiento, { observacion: cancelacion || null })}>Confirmar cancelación</button>
          <button type="button" className="button button-quiet" onClick={() => setPanel('')}>Volver</button>
        </div>
      ) : null}
      <h2>Historial de estados</h2>
      <ul className="record-list">
        {(ficha.historial ?? []).length === 0 ? <li>No hay cambios de estado.</li> : ficha.historial.map((item) => (
          <li key={item.id}>
            <div>
              <strong>{item.anterior ? `${item.anterior} → ${item.nuevo}` : item.nuevo}</strong>
              <span>{item.observacion || 'Sin observación'}</span>
            </div>
            <span>{lectura(item.fecha)} · {item.usuario}</span>
          </li>
        ))}
      </ul>
      <h2>Archivos</h2>
      <Archivos ficha={ficha} editable={operate && abierto} onChanged={cargar} onError={setError} />
    </section>
  );
}

function Archivos({ ficha, editable, onChanged, onError }) {
  async function subir(event, tipo) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    if (file.size > 5242880) {
      onError('El archivo supera el tamaño permitido.');
      return;
    }
    const result = await subirArchivoMantenimiento(ficha.id, file, tipo);
    if (!result.ok) { onError(mensaje(result)); return; }
    onError('');
    onChanged();
  }

  return (
    <div>
      <ul className="record-list">
        {(ficha.archivos ?? []).length === 0 ? <li>No hay archivos.</li> : ficha.archivos.map((archivo) => (
          <Archivo key={archivo.id} archivo={archivo} editable={editable} onChanged={onChanged} onError={onError} />
        ))}
      </ul>
      {editable ? (
        <div className="filters">
          <label>Fotografía<input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => subir(event, 'FOTO')} /></label>
          <label>Documento PDF<input type="file" accept="application/pdf" onChange={(event) => subir(event, 'DOCUMENTO')} /></label>
        </div>
      ) : null}
    </div>
  );
}

function Archivo({ archivo, editable, onChanged, onError }) {
  const [url, setUrl] = useState('');
  const esFoto = String(archivo.mime_type || '').startsWith('image/');

  useEffect(() => {
    if (!esFoto) return undefined;
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
  }, [archivo.id, esFoto]);

  async function descargar() {
    const result = await bajarArchivo(archivo.id);
    if (!result.ok || !result.blob) { onError('No se pudo descargar el archivo.'); return; }
    const objeto = URL.createObjectURL(result.blob);
    const enlace = document.createElement('a');
    enlace.href = objeto;
    enlace.download = archivo.nombre_original || 'archivo';
    enlace.click();
    URL.revokeObjectURL(objeto);
  }

  return (
    <li>
      <div>
        <strong>{archivo.nombre_original}</strong>
        {esFoto && url ? <img src={url} alt={archivo.nombre_original} /> : null}
      </div>
      <span>
        <button type="button" className="button button-quiet" onClick={descargar}>Descargar</button>
        {editable ? (
          <button type="button" className="button button-quiet" onClick={async () => {
            const result = await eliminarArchivo(archivo.id);
            if (!result.ok) { onError(mensaje(result)); return; }
            onChanged();
          }}>Quitar</button>
        ) : null}
      </span>
    </li>
  );
}
