import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { createCotizacion, getCotizacion, getOportunidad, listClientesComerciales, updateCotizacion } from '../../api/comercial';
import { pagina } from '../../api/inspecciones';
import { asPage, listMedidas, listModelos } from '../../api/neumaticos';
import { SearchSelect } from '../../components/SearchSelect';
import { clienteNombre, dinero, mensaje, subtotalLinea } from './texto';

const LINEA = { descripcion: '', modelo_neumatico_id: '', modelo_label: '', medida_neumatico_id: '', medida_label: '', cantidad: '1', precio_unitario: '' };

export function CotizacionNuevaPage() {
  return <CotizacionForm modo="nueva" />;
}

export function CotizacionEditarPage() {
  return <CotizacionForm modo="editar" />;
}

function CotizacionForm({ modo }) {
  const { id } = useParams();
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const [clienteId, setClienteId] = useState('');
  const [clienteLabel, setClienteLabel] = useState('');
  const [oportunidadId, setOportunidadId] = useState(params.get('oportunidad') || '');
  const [oportunidadTitulo, setOportunidadTitulo] = useState('');
  const [fecha, setFecha] = useState('');
  const [moneda, setMoneda] = useState('PEN');
  const [observacion, setObservacion] = useState('');
  const [detalles, setDetalles] = useState([{ ...LINEA }]);
  const [error, setError] = useState('');
  const [enviando, setEnviando] = useState(false);

  useEffect(() => {
    if (modo === 'editar') {
      getCotizacion(id).then((result) => {
        if (!result.ok) {
          setError(mensaje(result));
          return;
        }
        const data = result.payload.data;
        if (data.estado !== 'BORRADOR') {
          navigate(`/comercial/cotizaciones/${id}`, { replace: true });
          return;
        }
        setClienteId(String(data.cliente.id));
        setClienteLabel(clienteNombre(data.cliente));
        setOportunidadId(data.oportunidad ? String(data.oportunidad.id) : '');
        setOportunidadTitulo(data.oportunidad?.titulo || '');
        setFecha(data.fecha);
        setMoneda(data.moneda);
        setObservacion(data.observacion || '');
        setDetalles((data.detalles || []).map((detalle) => ({
          descripcion: detalle.descripcion,
          modelo_neumatico_id: detalle.modelo ? String(detalle.modelo.id) : '',
          modelo_label: detalle.modelo?.nombre || '',
          medida_neumatico_id: detalle.medida ? String(detalle.medida.id) : '',
          medida_label: detalle.medida?.descripcion || '',
          cantidad: String(detalle.cantidad),
          precio_unitario: String(detalle.precio_unitario),
        })));
      });
    }
    if (modo === 'nueva' && params.get('oportunidad')) {
      getOportunidad(params.get('oportunidad')).then((result) => {
        if (!result.ok) return;
        const data = result.payload.data;
        setClienteId(String(data.cliente.id));
        setClienteLabel(clienteNombre(data.cliente));
        setOportunidadTitulo(data.titulo);
        setMoneda(data.moneda || 'PEN');
      });
    }
  }, [modo, id, navigate, params]);

  function actualizar(indice, cambios) {
    setDetalles(detalles.map((linea, actual) => (actual === indice ? { ...linea, ...cambios } : linea)));
  }

  const total = detalles.reduce((suma, linea) => suma + (subtotalLinea(linea.cantidad, linea.precio_unitario) || 0), 0);

  async function guardar(event) {
    event.preventDefault();
    setEnviando(true);
    setError('');
    const cuerpo = {
      fecha,
      moneda: moneda.trim(),
      observacion: observacion.trim() || null,
      detalles: detalles.map((linea) => ({
        descripcion: linea.descripcion.trim(),
        modelo_neumatico_id: linea.modelo_neumatico_id ? Number(linea.modelo_neumatico_id) : null,
        medida_neumatico_id: linea.medida_neumatico_id ? Number(linea.medida_neumatico_id) : null,
        cantidad: linea.cantidad,
        precio_unitario: linea.precio_unitario,
      })),
    };
    const result = modo === 'editar'
      ? await updateCotizacion(id, cuerpo)
      : await createCotizacion({
        ...cuerpo,
        cliente_id: Number(clienteId),
        oportunidad_id: oportunidadId ? Number(oportunidadId) : null,
      });
    setEnviando(false);
    if (!result.ok) {
      setError(mensaje(result));
      return;
    }
    navigate(`/comercial/cotizaciones/${result.payload.data.id}`);
  }

  return (
    <section>
      <div className="page-heading">
        <p className="eyebrow">Comercial</p>
        <h1>{modo === 'editar' ? 'Editar cotización' : 'Nueva cotización'}</h1>
      </div>
      <form className="stack-form" onSubmit={guardar}>
        {modo === 'nueva' && !oportunidadId ? (
          <SearchSelect
            label="Cliente"
            value={clienteId}
            selectedLabel={clienteLabel}
            placeholder="Buscar cliente"
            scopeKey="alta-cotizacion"
            fetchPage={(paramsPage) => listClientesComerciales({ ...paramsPage, sort: 'razon_social', order: 'asc' }).then(pagina)}
            getLabel={(item) => item.nombre_comercial || item.razon_social}
            onChange={(value, item) => { setClienteId(value); setClienteLabel(item ? (item.nombre_comercial || item.razon_social) : ''); }}
          />
        ) : <p><strong>Cliente</strong><br />{clienteLabel || '—'}</p>}
        <p><strong>Oportunidad</strong><br />{oportunidadTitulo || 'Sin oportunidad asociada'}</p>
        <label>Fecha
          <input type="date" required value={fecha} onChange={(event) => setFecha(event.target.value)} />
        </label>
        <label>Moneda
          <input required maxLength={3} value={moneda} onChange={(event) => setMoneda(event.target.value.toUpperCase())} />
        </label>
        <label>Observación
          <textarea rows={3} value={observacion} onChange={(event) => setObservacion(event.target.value)} />
        </label>
        <div className="line-editor">
          <div className="row-between">
            <h2>Detalles</h2>
            <button type="button" className="button button-quiet" onClick={() => setDetalles([...detalles, { ...LINEA }])}>Agregar detalle</button>
          </div>
          {detalles.map((linea, indice) => {
            const subtotal = subtotalLinea(linea.cantidad, linea.precio_unitario);
            return (
              <div className="line-card" key={indice}>
                <label>Descripción
                  <input required value={linea.descripcion} onChange={(event) => actualizar(indice, { descripcion: event.target.value })} />
                </label>
                <SearchSelect
                  label="Modelo"
                  value={linea.modelo_neumatico_id}
                  selectedLabel={linea.modelo_label}
                  placeholder="Opcional"
                  emptyLabel="Sin modelo"
                  scopeKey={`modelo-cot-${indice}`}
                  fetchPage={(paramsPage) => listModelos({ ...paramsPage, activo: 1, sort: 'nombre', order: 'asc' }).then(asPage)}
                  getLabel={(item) => item.nombre}
                  onChange={(value, item) => actualizar(indice, { modelo_neumatico_id: value, modelo_label: item?.nombre || '' })}
                />
                <SearchSelect
                  label="Medida"
                  value={linea.medida_neumatico_id}
                  selectedLabel={linea.medida_label}
                  placeholder="Opcional"
                  emptyLabel="Sin medida"
                  scopeKey={`medida-cot-${indice}`}
                  fetchPage={(paramsPage) => listMedidas({ ...paramsPage, activo: 1, sort: 'descripcion', order: 'asc' }).then(asPage)}
                  getLabel={(item) => item.descripcion}
                  onChange={(value, item) => actualizar(indice, { medida_neumatico_id: value, medida_label: item?.descripcion || '' })}
                />
                <label>Cantidad
                  <input required inputMode="decimal" value={linea.cantidad} onChange={(event) => actualizar(indice, { cantidad: event.target.value })} />
                </label>
                <label>Precio unitario
                  <input required inputMode="decimal" value={linea.precio_unitario} onChange={(event) => actualizar(indice, { precio_unitario: event.target.value })} />
                </label>
                <p><strong>Subtotal</strong><br />{subtotal === null ? '—' : dinero(subtotal.toFixed(2))}</p>
                {detalles.length > 1 ? <button type="button" className="button button-quiet" onClick={() => setDetalles(detalles.filter((_, actual) => actual !== indice))}>Quitar</button> : null}
              </div>
            );
          })}
        </div>
        <div className="money-box"><span>Subtotal</span><strong>{dinero(total.toFixed(2))} {moneda}</strong></div>
        <div className="money-box"><span>Total</span><strong>{dinero(total.toFixed(2))} {moneda}</strong></div>
        <p>El total se muestra como referencia. El servidor lo vuelve a calcular al guardar.</p>
        {error ? <p className="form-error" role="alert">{error}</p> : null}
        <div className="actions">
          <button className="button button-primary" type="submit" disabled={enviando}>{enviando ? 'Guardando…' : 'Guardar borrador'}</button>
          <Link className="button button-quiet" to="/comercial/cotizaciones">Volver</Link>
        </div>
      </form>
    </section>
  );
}
