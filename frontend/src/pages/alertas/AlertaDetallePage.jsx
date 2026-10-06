import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { atenderAlerta, descartarAlerta, getAlerta, tomarAtencionAlerta } from '../../api/alertas';
import { useAuth } from '../../auth/AuthContext';
import { EstadoBadge } from '../../components/EstadoBadge';
import { canManageNeumaticos } from '../../utils/access';
import { mensaje, origenTexto } from './texto';

export function AlertaDetallePage() {
  const { id } = useParams();
  const { user } = useAuth();
  const operate = canManageNeumaticos(user);
  const [alerta, setAlerta] = useState(null);
  const [error, setError] = useState('');
  const [panel, setPanel] = useState('');
  const [observacion, setObservacion] = useState('');
  const [enviando, setEnviando] = useState(false);

  async function cargar() {
    const result = await getAlerta(id);
    if (!result.ok) {
      setError(mensaje(result));
      setAlerta(null);
      return;
    }
    setError('');
    setAlerta(result.payload.data);
    setPanel('');
    setObservacion('');
  }

  useEffect(() => { cargar(); }, [id]);

  if (!alerta && error) return <section><p className="form-error">{error}</p><Link to="/alertas">Volver</Link></section>;
  if (!alerta) return <section><p>Cargando alerta…</p></section>;

  const codigo = alerta.estado.codigo;
  const abierta = codigo === 'ABIERTA';
  const enAtencion = codigo === 'EN_ATENCION';
  const cerrada = codigo === 'ATENDIDA' || codigo === 'DESCARTADA';

  async function ejecutar(accion) {
    setEnviando(true);
    setError('');
    const body = observacion.trim() ? { observacion: observacion.trim() } : {};
    const result = await accion(id, body);
    setEnviando(false);
    if (!result.ok) {
      setError(mensaje(result));
      return;
    }
    setAlerta(result.payload.data);
    setPanel('');
    setObservacion('');
  }

  return (
    <section>
      <div className="page-heading">
        <p className="eyebrow">Alerta · {origenTexto(alerta.origen)}</p>
        <h1>{alerta.titulo}</h1>
        <p className="lede">{alerta.cliente.nombre_comercial || alerta.cliente.razon_social}</p>
      </div>
      <article className="panel">
        <div className="summary-grid">
          <p><strong>Nivel</strong><span><EstadoBadge estado={alerta.nivel} /></span></p>
          <p><strong>Estado</strong><span><EstadoBadge estado={alerta.estado.codigo} /></span></p>
          <p><strong>Tipo</strong><span>{alerta.tipo.nombre}</span></p>
          <p><strong>Origen</strong><span>{origenTexto(alerta.origen)}</span></p>
          <p><strong>Fecha</strong><span>{alerta.fecha_generacion}</span></p>
          <p><strong>Cliente</strong><span>{alerta.cliente.nombre_comercial || alerta.cliente.razon_social}</span></p>
          <p><strong>Unidad</strong><span>{alerta.unidad ? `${alerta.unidad.codigo}${alerta.unidad.placa ? ` · ${alerta.unidad.placa}` : ''}` : '—'}</span></p>
          <p><strong>Neumático</strong><span>{alerta.neumatico ? <Link to={`/neumaticos/${alerta.neumatico.id}`}>{alerta.neumatico.codigo}</Link> : '—'}</span></p>
          <p><strong>Inspección</strong><span>{alerta.inspeccion ? <Link to={`/inspecciones/${alerta.inspeccion.id}`}>{alerta.inspeccion.fecha}</Link> : '—'}</span></p>
          <p><strong>Posición</strong><span>{alerta.detalle?.posicion || '—'}</span></p>
        </div>
        <p><strong>Descripción</strong><br />{alerta.descripcion}</p>
        <p><strong>Recomendación</strong><br />{alerta.recomendacion || '—'}</p>
      </article>
      {operate && !cerrada ? (
        <div className="actions">
          {abierta ? <button type="button" className="button button-primary" onClick={() => setPanel('atencion')}>Tomar atención</button> : null}
          {abierta || enAtencion ? <button type="button" className="button button-primary" onClick={() => setPanel('atender')}>Atender</button> : null}
          {abierta || enAtencion ? <button type="button" className="button button-quiet" onClick={() => setPanel('descartar')}>Descartar</button> : null}
        </div>
      ) : null}
      {panel ? (
        <form className="stack-form" onSubmit={(event) => {
          event.preventDefault();
          if (panel === 'atencion') ejecutar(tomarAtencionAlerta);
          if (panel === 'atender') ejecutar(atenderAlerta);
          if (panel === 'descartar') ejecutar(descartarAlerta);
        }}>
          <p>{panel === 'atencion' ? 'La alerta pasa a En atención. La observación es opcional.' : 'Debe quedar constancia de la resolución. La observación es obligatoria.'}</p>
          <label>Observación
            <textarea name="observacion" value={observacion} required={panel !== 'atencion'} rows={3} onChange={(event) => setObservacion(event.target.value)} />
          </label>
          {error ? <p className="form-error" role="alert">{error}</p> : null}
          <div className="actions">
            <button className="button button-primary" type="submit" disabled={enviando}>{panel === 'descartar' ? 'Confirmar descarte' : 'Confirmar'}</button>
            <button className="button button-quiet" type="button" onClick={() => setPanel('')}>Volver</button>
          </div>
        </form>
      ) : null}
      {error && !panel ? <p className="form-error" role="alert">{error}</p> : null}
      <h2>Historial de atención</h2>
      <ul className="record-list">
        {(alerta.historial ?? []).length === 0 ? <li>No hay transiciones.</li> : alerta.historial.map((item) => (
          <li key={item.id}>
            <div>
              <strong>{item.anterior ? `${item.anterior.nombre} → ${item.nuevo.nombre}` : item.nuevo.nombre}</strong>
              <span>{item.observacion || 'Sin observación'}</span>
            </div>
            <span>{item.fecha} · {item.usuario}</span>
          </li>
        ))}
      </ul>
      <Link to="/alertas">Volver al listado</Link>
    </section>
  );
}
