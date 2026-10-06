import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { aceptarCotizacion, anularCotizacion, enviarCotizacion, getCotizacion, rechazarCotizacion } from '../../api/comercial';
import { useAuth } from '../../auth/AuthContext';
import { EstadoBadge } from '../../components/EstadoBadge';
import { canWriteComercial } from '../../utils/access';
import { clienteNombre, dinero, mensaje } from './texto';

export function CotizacionDetallePage() {
  const { id } = useParams();
  const { user } = useAuth();
  const escribir = canWriteComercial(user);
  const [cotizacion, setCotizacion] = useState(null);
  const [error, setError] = useState('');
  const [enviando, setEnviando] = useState(false);

  async function cargar() {
    const result = await getCotizacion(id);
    if (!result.ok) {
      setError(mensaje(result));
      setCotizacion(null);
      return;
    }
    setError('');
    setCotizacion(result.payload.data);
  }

  useEffect(() => { cargar(); }, [id]);

  if (!cotizacion && error) return <section><p className="form-error">{error}</p><Link to="/comercial/cotizaciones">Volver</Link></section>;
  if (!cotizacion) return <section><p>Cargando cotización…</p></section>;

  const estado = cotizacion.estado;
  const borrador = estado === 'BORRADOR';
  const enviada = estado === 'ENVIADA';

  async function accion(fn) {
    setEnviando(true);
    setError('');
    const result = await fn(id);
    setEnviando(false);
    if (!result.ok) {
      setError(mensaje(result));
      return;
    }
    setCotizacion(result.payload.data);
  }

  return (
    <section>
      <div className="page-heading row-between">
        <div>
          <p className="eyebrow">Cotización</p>
          <h1>{cotizacion.numero}</h1>
          <p className="lede">{clienteNombre(cotizacion.cliente)}</p>
        </div>
        <EstadoBadge estado={estado} />
      </div>
      <article className="panel">
        <div className="summary-grid">
          <p><strong>Número</strong><span>{cotizacion.numero}</span></p>
          <p><strong>Cliente</strong><span>{clienteNombre(cotizacion.cliente)}</span></p>
          <p><strong>Oportunidad</strong><span>{cotizacion.oportunidad ? <Link to={`/comercial/oportunidades/${cotizacion.oportunidad.id}`}>{cotizacion.oportunidad.titulo}</Link> : '—'}</span></p>
          <p><strong>Estado</strong><span><EstadoBadge estado={estado} /></span></p>
          <p><strong>Fecha</strong><span>{cotizacion.fecha}</span></p>
          <p><strong>Moneda</strong><span>{cotizacion.moneda}</span></p>
          {cotizacion.oportunidad?.valor_estimado ? <p><strong>Valor estimado de la oportunidad</strong><span>{dinero(cotizacion.oportunidad.valor_estimado)}</span></p> : null}
        </div>
        <p><strong>Observación</strong><br />{cotizacion.observacion || '—'}</p>
      </article>
      {escribir && (borrador || enviada) ? (
        <div className="actions">
          {borrador ? <Link className="button button-quiet" to={`/comercial/cotizaciones/${id}/editar`}>Editar</Link> : null}
          {borrador ? <button type="button" className="button button-primary" disabled={enviando} onClick={() => accion(enviarCotizacion)}>Enviar</button> : null}
          {enviada ? <button type="button" className="button button-primary" disabled={enviando} onClick={() => accion(aceptarCotizacion)}>Aceptar</button> : null}
          {enviada ? <button type="button" className="button button-quiet" disabled={enviando} onClick={() => accion(rechazarCotizacion)}>Rechazar</button> : null}
          {borrador || enviada ? <button type="button" className="button button-quiet" disabled={enviando} onClick={() => accion(anularCotizacion)}>Anular</button> : null}
        </div>
      ) : null}
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <h2>Detalles</h2>
      <div className="table-wrap">
        <table className="data-table">
          <thead><tr><th>Descripción</th><th>Modelo</th><th>Medida</th><th>Cantidad</th><th>Precio unitario</th><th>Subtotal</th></tr></thead>
          <tbody>
            {cotizacion.detalles.map((detalle) => (
              <tr key={detalle.id}>
                <td data-label="Descripción">{detalle.descripcion}</td>
                <td data-label="Modelo">{detalle.modelo?.nombre || '—'}</td>
                <td data-label="Medida">{detalle.medida?.descripcion || '—'}</td>
                <td data-label="Cantidad">{detalle.cantidad}</td>
                <td data-label="Precio unitario">{dinero(detalle.precio_unitario)}</td>
                <td data-label="Subtotal">{dinero(detalle.subtotal)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="money-box"><span>Subtotal</span><strong>{dinero(cotizacion.subtotal)} {cotizacion.moneda}</strong></div>
      <div className="money-box"><span>Total</span><strong>{dinero(cotizacion.total)} {cotizacion.moneda}</strong></div>
      <Link to="/comercial/cotizaciones">Volver al listado</Link>
    </section>
  );
}
