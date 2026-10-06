import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { getAlerta } from '../../api/alertas';
import { crearOportunidadDesdeAlerta, createOportunidad, getOportunidad, listClientesComerciales, listResponsablesComerciales, updateOportunidad } from '../../api/comercial';
import { pagina } from '../../api/inspecciones';
import { listMedidas, listModelos } from '../../api/neumaticos';
import { asPage } from '../../api/neumaticos';
import { SearchSelect } from '../../components/SearchSelect';
import { clienteNombre, mensaje } from './texto';

const LINEA = { medida_neumatico_id: '', medida_label: '', modelo_neumatico_id: '', modelo_label: '', cantidad: '1', precio_estimado: '', observacion: '' };

export function OportunidadNuevaPage() {
  return <OportunidadForm modo="nueva" />;
}

export function OportunidadEditarPage() {
  return <OportunidadForm modo="editar" />;
}

export function OportunidadDesdeAlertaPage() {
  return <OportunidadForm modo="alerta" />;
}

function OportunidadForm({ modo }) {
  const { id, alertaId } = useParams();
  const navigate = useNavigate();
  const [clienteId, setClienteId] = useState('');
  const [clienteLabel, setClienteLabel] = useState('');
  const [responsableId, setResponsableId] = useState('');
  const [responsables, setResponsables] = useState([]);
  const [titulo, setTitulo] = useState('');
  const [descripcion, setDescripcion] = useState('');
  const [fechaDeteccion, setFechaDeteccion] = useState('');
  const [necesidad, setNecesidad] = useState('');
  const [valor, setValor] = useState('');
  const [moneda, setMoneda] = useState('PEN');
  const [detalles, setDetalles] = useState([]);
  const [error, setError] = useState('');
  const [enviando, setEnviando] = useState(false);
  const [listo, setListo] = useState(modo === 'nueva');

  useEffect(() => {
    if (modo === 'editar') {
      getOportunidad(id).then((result) => {
        if (!result.ok) {
          setError(mensaje(result));
          return;
        }
        const data = result.payload.data;
        if (data.estado.es_final) {
          navigate(`/comercial/oportunidades/${id}`, { replace: true });
          return;
        }
        setClienteId(String(data.cliente.id));
        setClienteLabel(clienteNombre(data.cliente));
        setResponsableId(String(data.responsable.id));
        setTitulo(data.titulo);
        setDescripcion(data.descripcion || '');
        setNecesidad(data.fecha_estimada_necesidad || '');
        setValor(data.valor_estimado || '');
        setMoneda(data.moneda || 'PEN');
        setDetalles((data.detalles || []).map((detalle) => ({
          medida_neumatico_id: detalle.medida ? String(detalle.medida.id) : '',
          medida_label: detalle.medida?.descripcion || '',
          modelo_neumatico_id: detalle.modelo ? String(detalle.modelo.id) : '',
          modelo_label: detalle.modelo?.nombre || '',
          cantidad: String(detalle.cantidad),
          precio_estimado: detalle.precio_estimado || '',
          observacion: detalle.observacion || '',
        })));
        setListo(true);
      });
    }
    if (modo === 'alerta') {
      getAlerta(alertaId).then((result) => {
        if (!result.ok) {
          setError(mensaje(result));
          return;
        }
        const alerta = result.payload.data;
        setClienteId(String(alerta.cliente.id));
        setClienteLabel(clienteNombre(alerta.cliente));
        setTitulo(alerta.titulo || '');
        setDescripcion(alerta.descripcion || '');
        setListo(true);
      });
    }
  }, [modo, id, alertaId, navigate]);

  useEffect(() => {
    if (!clienteId) {
      setResponsables([]);
      return;
    }
    listResponsablesComerciales(clienteId).then((result) => {
      const rows = result.ok ? (result.payload?.data ?? []) : [];
      setResponsables(rows);
      if (rows.length === 1) setResponsableId(String(rows[0].id));
    });
  }, [clienteId]);

  function actualizarLinea(indice, cambios) {
    setDetalles(detalles.map((linea, actual) => (actual === indice ? { ...linea, ...cambios } : linea)));
  }

  async function guardar(event) {
    event.preventDefault();
    setEnviando(true);
    setError('');
    const cuerpo = {
      titulo: titulo.trim(),
      descripcion: descripcion.trim() || null,
      fecha_estimada_necesidad: necesidad || null,
      valor_estimado: valor === '' ? null : valor,
      moneda: moneda.trim() || null,
      responsable_comercial_id: Number(responsableId),
      detalles: detalles.filter((linea) => linea.cantidad !== '').map((linea) => ({
        medida_neumatico_id: linea.medida_neumatico_id ? Number(linea.medida_neumatico_id) : null,
        modelo_neumatico_id: linea.modelo_neumatico_id ? Number(linea.modelo_neumatico_id) : null,
        cantidad: linea.cantidad,
        precio_estimado: linea.precio_estimado === '' ? null : linea.precio_estimado,
        observacion: linea.observacion.trim() || null,
      })),
    };
    let result;
    if (modo === 'editar') {
      result = await updateOportunidad(id, cuerpo);
    } else if (modo === 'alerta') {
      result = await crearOportunidadDesdeAlerta(alertaId, cuerpo);
    } else {
      result = await createOportunidad({
        ...cuerpo,
        cliente_id: Number(clienteId),
        fecha_deteccion: fechaDeteccion.replace('T', ' '),
      });
    }
    setEnviando(false);
    if (!result.ok) {
      setError(mensaje(result));
      return;
    }
    navigate(`/comercial/oportunidades/${result.payload.data.id}`);
  }

  if (!listo && !error) return <section><p>Cargando oportunidad…</p></section>;

  return (
    <section>
      <div className="page-heading">
        <p className="eyebrow">Comercial</p>
        <h1>{modo === 'editar' ? 'Editar oportunidad' : modo === 'alerta' ? 'Oportunidad desde alerta' : 'Nueva oportunidad'}</h1>
      </div>
      <form className="stack-form" onSubmit={guardar}>
        {modo === 'nueva' ? (
          <SearchSelect
            label="Cliente"
            value={clienteId}
            selectedLabel={clienteLabel}
            placeholder="Buscar cliente activo o potencial"
            scopeKey="alta-oportunidad"
            fetchPage={(params) => listClientesComerciales({ ...params, sort: 'razon_social', order: 'asc' }).then(pagina)}
            getLabel={(item) => item.nombre_comercial || item.razon_social}
            onChange={(value, item) => { setClienteId(value); setClienteLabel(item ? (item.nombre_comercial || item.razon_social) : ''); setResponsableId(''); }}
          />
        ) : <p><strong>Cliente</strong><br />{clienteLabel}</p>}
        <label>Responsable comercial
          <select value={responsableId} required onChange={(event) => setResponsableId(event.target.value)}>
            <option value="">Seleccionar</option>
            {responsables.map((persona) => <option key={persona.id} value={persona.id}>{persona.nombres} {persona.apellidos}</option>)}
          </select>
        </label>
        <label>Título
          <input value={titulo} required maxLength={180} onChange={(event) => setTitulo(event.target.value)} />
        </label>
        <label>Descripción
          <textarea value={descripcion} rows={3} onChange={(event) => setDescripcion(event.target.value)} />
        </label>
        {modo === 'nueva' ? (
          <label>Fecha de detección
            <input type="datetime-local" required value={fechaDeteccion} onChange={(event) => setFechaDeteccion(event.target.value)} />
          </label>
        ) : null}
        <label>Necesidad estimada
          <input type="date" value={necesidad} onChange={(event) => setNecesidad(event.target.value)} />
        </label>
        <label>Valor estimado
          <input inputMode="decimal" value={valor} placeholder="0.00" onChange={(event) => setValor(event.target.value)} />
        </label>
        <label>Moneda
          <input value={moneda} maxLength={3} onChange={(event) => setMoneda(event.target.value.toUpperCase())} />
        </label>
        <div className="line-editor">
          <div className="row-between">
            <h2>Detalles</h2>
            <button type="button" className="button button-quiet" onClick={() => setDetalles([...detalles, { ...LINEA }])}>Agregar detalle</button>
          </div>
          {detalles.map((linea, indice) => (
            <div className="line-card" key={indice}>
              <SearchSelect
                label="Modelo"
                value={linea.modelo_neumatico_id}
                selectedLabel={linea.modelo_label}
                placeholder="Opcional"
                emptyLabel="Sin modelo"
                scopeKey={`modelo-op-${indice}`}
                fetchPage={(params) => listModelos({ ...params, activo: 1, sort: 'nombre', order: 'asc' }).then(asPage)}
                getLabel={(item) => item.nombre}
                onChange={(value, item) => actualizarLinea(indice, { modelo_neumatico_id: value, modelo_label: item?.nombre || '' })}
              />
              <SearchSelect
                label="Medida"
                value={linea.medida_neumatico_id}
                selectedLabel={linea.medida_label}
                placeholder="Opcional"
                emptyLabel="Sin medida"
                scopeKey={`medida-op-${indice}`}
                fetchPage={(params) => listMedidas({ ...params, activo: 1, sort: 'descripcion', order: 'asc' }).then(asPage)}
                getLabel={(item) => item.descripcion}
                onChange={(value, item) => actualizarLinea(indice, { medida_neumatico_id: value, medida_label: item?.descripcion || '' })}
              />
              <label>Cantidad
                <input required inputMode="decimal" value={linea.cantidad} onChange={(event) => actualizarLinea(indice, { cantidad: event.target.value })} />
              </label>
              <label>Precio estimado
                <input inputMode="decimal" value={linea.precio_estimado} onChange={(event) => actualizarLinea(indice, { precio_estimado: event.target.value })} />
              </label>
              <label>Observación
                <input value={linea.observacion} onChange={(event) => actualizarLinea(indice, { observacion: event.target.value })} />
              </label>
              <button type="button" className="button button-quiet" onClick={() => setDetalles(detalles.filter((_, actual) => actual !== indice))}>Quitar</button>
            </div>
          ))}
        </div>
        {error ? <p className="form-error" role="alert">{error}</p> : null}
        <div className="actions">
          <button className="button button-primary" type="submit" disabled={enviando}>{enviando ? 'Guardando…' : 'Guardar'}</button>
          <Link className="button button-quiet" to="/comercial/oportunidades">Volver</Link>
        </div>
      </form>
    </section>
  );
}
