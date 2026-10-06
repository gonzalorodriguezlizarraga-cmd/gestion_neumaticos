import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import {
  cancelarOportunidad, createSeguimiento, ganarOportunidad, getOportunidad,
  iniciarSeguimientoOportunidad, marcarCotizadaOportunidad, perderOportunidad,
} from '../../api/comercial';
import { useAuth } from '../../auth/AuthContext';
import { EstadoBadge } from '../../components/EstadoBadge';
import { canWriteComercial } from '../../utils/access';
import { clienteNombre, dinero, mensaje, nombre, ORIGENES, proximoTexto, TIPOS_SEGUIMIENTO } from './texto';

export function OportunidadDetallePage() {
  const { id } = useParams();
  const { user } = useAuth();
  const escribir = canWriteComercial(user);
  const [oportunidad, setOportunidad] = useState(null);
  const [error, setError] = useState('');
  const [panel, setPanel] = useState('');
  const [motivo, setMotivo] = useState('');
  const [seguimiento, setSeguimiento] = useState({ tipo: 'LLAMADA', fecha: '', resultado: '', proximo_seguimiento: '', observacion: '' });
  const [enviando, setEnviando] = useState(false);

  async function cargar() {
    const result = await getOportunidad(id);
    if (!result.ok) {
      setError(mensaje(result));
      setOportunidad(null);
      return;
    }
    setError('');
    setOportunidad(result.payload.data);
    setPanel('');
    setMotivo('');
  }

  useEffect(() => { cargar(); }, [id]);

  if (!oportunidad && error) return <section><p className="form-error">{error}</p><Link to="/comercial/oportunidades">Volver</Link></section>;
  if (!oportunidad) return <section><p>Cargando oportunidad…</p></section>;

  const codigo = oportunidad.estado.codigo;
  const abierta = codigo === 'ABIERTA';
  const seguimientoEstado = codigo === 'EN_SEGUIMIENTO';
  const cotizada = codigo === 'COTIZADA';
  const final = oportunidad.estado.es_final;
  const origen = ORIGENES.find(([item]) => item === oportunidad.origen)?.[1] || oportunidad.origen;

  async function transicion(accion, body) {
    setEnviando(true);
    setError('');
    const result = await accion(id, body);
    setEnviando(false);
    if (!result.ok) {
      setError(mensaje(result));
      return;
    }
    setOportunidad(result.payload.data);
    setPanel('');
    setMotivo('');
  }

  async function registrarSeguimiento(event) {
    event.preventDefault();
    setEnviando(true);
    setError('');
    const result = await createSeguimiento({
      cliente_id: oportunidad.cliente.id,
      oportunidad_id: oportunidad.id,
      tipo: seguimiento.tipo,
      fecha: seguimiento.fecha.replace('T', ' '),
      resultado: seguimiento.resultado.trim() || null,
      proximo_seguimiento: seguimiento.proximo_seguimiento ? seguimiento.proximo_seguimiento.replace('T', ' ') : null,
      observacion: seguimiento.observacion.trim() || null,
    });
    setEnviando(false);
    if (!result.ok) {
      setError(mensaje(result));
      return;
    }
    setSeguimiento({ tipo: 'LLAMADA', fecha: '', resultado: '', proximo_seguimiento: '', observacion: '' });
    cargar();
  }

  return (
    <section>
      <div className="page-heading row-between">
        <div>
          <p className="eyebrow">Oportunidad · {origen}</p>
          <h1>{oportunidad.titulo}</h1>
          <p className="lede">{clienteNombre(oportunidad.cliente)}</p>
        </div>
        <EstadoBadge estado={codigo} />
      </div>
      <h2>Resumen</h2>
      <article className="panel">
        <div className="summary-grid">
          <p><strong>Cliente</strong><span><Link to={`/clientes/${oportunidad.cliente.id}`}>{clienteNombre(oportunidad.cliente)}</Link></span></p>
          <p><strong>Responsable</strong><span>{nombre(oportunidad.responsable)}</span></p>
          <p><strong>Origen</strong><span>{origen}</span></p>
          <p><strong>Alerta origen</strong><span>{oportunidad.alerta ? <Link to={`/alertas/${oportunidad.alerta.id}`}>{oportunidad.alerta.titulo}</Link> : '—'}</span></p>
          <p><strong>Estado</strong><span><EstadoBadge estado={codigo} /></span></p>
          <p><strong>Detección</strong><span>{oportunidad.fecha_deteccion}</span></p>
          <p><strong>Necesidad estimada</strong><span>{oportunidad.fecha_estimada_necesidad || '—'}</span></p>
          <p><strong>Valor estimado</strong><span>{dinero(oportunidad.valor_estimado)} {oportunidad.moneda || ''}</span></p>
          <p><strong>Próximo seguimiento</strong><span>{oportunidad.proximo_seguimiento ? <span className={`seguimiento-${oportunidad.proximo_seguimiento.indicador}`}>{proximoTexto(oportunidad.proximo_seguimiento)}</span> : 'Sin fecha'}</span></p>
        </div>
        <p><strong>Descripción</strong><br />{oportunidad.descripcion || '—'}</p>
      </article>
      {escribir && !final ? (
        <div className="actions">
          <Link className="button button-quiet" to={`/comercial/oportunidades/${id}/editar`}>Editar</Link>
          {abierta ? <button type="button" className="button button-primary" onClick={() => transicion(iniciarSeguimientoOportunidad, {})}>Iniciar seguimiento</button> : null}
          {abierta || seguimientoEstado ? <button type="button" className="button button-primary" onClick={() => transicion(marcarCotizadaOportunidad, {})}>Marcar cotizada</button> : null}
          {seguimientoEstado || cotizada ? <button type="button" className="button button-primary" onClick={() => setPanel('ganar')}>Ganar</button> : null}
          <button type="button" className="button button-quiet" onClick={() => setPanel('perder')}>Perder</button>
          <button type="button" className="button button-quiet" onClick={() => setPanel('cancelar')}>Cancelar</button>
          <Link className="button button-primary" to={`/comercial/cotizaciones/nueva?oportunidad=${id}`}>Nueva cotización</Link>
        </div>
      ) : null}
      {panel ? (
        <form className="stack-form" onSubmit={(event) => {
          event.preventDefault();
          const body = motivo.trim() ? { motivo: motivo.trim() } : {};
          if (panel === 'ganar') transicion(ganarOportunidad, body);
          if (panel === 'perder') transicion(perderOportunidad, body);
          if (panel === 'cancelar') transicion(cancelarOportunidad, body);
        }}>
          <label>{panel === 'ganar' ? 'Observación' : 'Motivo'}
            <textarea value={motivo} required={panel !== 'ganar'} rows={3} onChange={(event) => setMotivo(event.target.value)} />
          </label>
          <div className="actions">
            <button className="button button-primary" type="submit" disabled={enviando}>Confirmar</button>
            <button className="button button-quiet" type="button" onClick={() => setPanel('')}>Volver</button>
          </div>
        </form>
      ) : null}
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <h2>Detalles</h2>
      <div className="table-wrap">
        <table className="data-table">
          <thead><tr><th>Modelo</th><th>Medida</th><th>Cantidad</th><th>Precio estimado</th><th>Observación</th></tr></thead>
          <tbody>
            {(oportunidad.detalles ?? []).length === 0 ? <tr><td colSpan={5}>Sin detalles comerciales.</td></tr> : oportunidad.detalles.map((detalle) => (
              <tr key={detalle.id}>
                <td data-label="Modelo">{detalle.modelo?.nombre || '—'}</td>
                <td data-label="Medida">{detalle.medida?.descripcion || '—'}</td>
                <td data-label="Cantidad">{detalle.cantidad}</td>
                <td data-label="Precio estimado">{dinero(detalle.precio_estimado)}</td>
                <td data-label="Observación">{detalle.observacion || '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <h2>Seguimientos</h2>
      {escribir && !final ? (
        <form className="stack-form" onSubmit={registrarSeguimiento}>
          <h3>Registrar seguimiento</h3>
          <label>Tipo
            <select value={seguimiento.tipo} onChange={(event) => setSeguimiento({ ...seguimiento, tipo: event.target.value })}>
              {TIPOS_SEGUIMIENTO.map(([codigoItem, etiqueta]) => <option key={codigoItem} value={codigoItem}>{etiqueta}</option>)}
            </select>
          </label>
          <label>Fecha
            <input type="datetime-local" required value={seguimiento.fecha} onChange={(event) => setSeguimiento({ ...seguimiento, fecha: event.target.value })} />
          </label>
          <label>Resultado
            <input value={seguimiento.resultado} onChange={(event) => setSeguimiento({ ...seguimiento, resultado: event.target.value })} />
          </label>
          <label>Próximo seguimiento
            <input type="datetime-local" value={seguimiento.proximo_seguimiento} onChange={(event) => setSeguimiento({ ...seguimiento, proximo_seguimiento: event.target.value })} />
          </label>
          <label>Observación
            <textarea rows={3} value={seguimiento.observacion} onChange={(event) => setSeguimiento({ ...seguimiento, observacion: event.target.value })} />
          </label>
          <button className="button button-primary" type="submit" disabled={enviando}>Registrar</button>
        </form>
      ) : null}
      <ol className="record-list">
        {(oportunidad.seguimientos ?? []).length === 0 ? <li>No hay seguimientos.</li> : oportunidad.seguimientos.map((item) => (
          <li key={item.id}>
            <div>
              <strong>{TIPOS_SEGUIMIENTO.find(([codigoItem]) => codigoItem === item.tipo)?.[1] || item.tipo}</strong>
              <span>{item.resultado || item.observacion || 'Sin resultado'}</span>
              {item.proximo_seguimiento ? <span>Próximo: {item.proximo_seguimiento}</span> : null}
            </div>
            <span>{item.fecha} · {item.usuario}</span>
          </li>
        ))}
      </ol>
      <h2>Cotizaciones</h2>
      <div className="table-wrap">
        <table className="data-table">
          <thead><tr><th>Número</th><th>Fecha</th><th>Estado</th><th>Moneda</th><th>Total</th><th></th></tr></thead>
          <tbody>
            {(oportunidad.cotizaciones ?? []).length === 0 ? <tr><td colSpan={6}>Sin cotizaciones.</td></tr> : oportunidad.cotizaciones.map((item) => (
              <tr key={item.id}>
                <td data-label="Número">{item.numero}</td>
                <td data-label="Fecha">{item.fecha}</td>
                <td data-label="Estado"><EstadoBadge estado={item.estado} /></td>
                <td data-label="Moneda">{item.moneda}</td>
                <td data-label="Total">{dinero(item.total)}</td>
                <td data-label="Acciones"><Link to={`/comercial/cotizaciones/${item.id}`}>Abrir</Link></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <h2>Historial</h2>
      <ul className="record-list">
        {(oportunidad.historial ?? []).map((item) => (
          <li key={item.id}>
            <div>
              <strong>{item.anterior ? `${item.anterior.nombre} → ${item.nuevo.nombre}` : item.nuevo.nombre}</strong>
              <span>{item.motivo || 'Sin motivo'}</span>
            </div>
            <span>{item.fecha} · {item.usuario}</span>
          </li>
        ))}
      </ul>
      <Link to="/comercial/oportunidades">Volver al listado</Link>
    </section>
  );
}
