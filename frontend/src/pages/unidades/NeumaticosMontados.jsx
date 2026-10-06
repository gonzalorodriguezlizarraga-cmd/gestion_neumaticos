import { useEffect, useState } from 'react';
import { asPage, listEstadosNeumatico, listNeumaticos } from '../../api/neumaticos';
import { getConfiguracion, getUnidad, listUnidades } from '../../api/unidades';
import {
  desmontarNeumatico,
  listMontajesActivos,
  listMovimientosUnidad,
  montarNeumatico,
  rotarNeumaticos,
  transferirNeumatico,
} from '../../api/operaciones';
import { SearchSelect } from '../../components/SearchSelect';
import { MovimientosTabla } from '../operaciones/MovimientosTabla';
import { fechaLocal, mensajeOperacion } from '../operaciones/texto';

export function NeumaticosMontados({ unidad, operate, onChanged }) {
  const [ejes, setEjes] = useState([]);
  const [activos, setActivos] = useState([]);
  const [movimientos, setMovimientos] = useState([]);
  const [disponibleId, setDisponibleId] = useState('');
  const [error, setError] = useState('');
  const [panel, setPanel] = useState('');
  const [montaje, setMontaje] = useState({ neumatico_id: '', neumatico_label: '', posicion_id: '', fecha: fechaLocal(), km: '', horometro: '', observacion: '' });
  const [baja, setBaja] = useState(null);
  const [cambios, setCambios] = useState({});
  const [rotacionFecha, setRotacionFecha] = useState(fechaLocal());
  const [transferencia, setTransferencia] = useState({ neumatico_id: '', unidad_id: '', unidad_label: '', posicion_id: '', fecha: fechaLocal(), km: '', horometro: '', observacion: '' });
  const [destinos, setDestinos] = useState([]);

  async function load() {
    if (!unidad?.configuracion?.id) {
      setEjes([]);
      setActivos([]);
      return;
    }
    const [config, mounted, history, estados] = await Promise.all([
      getConfiguracion(unidad.configuracion.id),
      listMontajesActivos(unidad.id),
      listMovimientosUnidad(unidad.id),
      listEstadosNeumatico({ limit: 20, sort: 'orden' }),
    ]);
    setEjes(config.ok ? config.payload?.data?.ejes ?? [] : []);
    setActivos(mounted.ok ? mounted.payload?.data ?? [] : []);
    setMovimientos(history.ok ? history.payload?.data ?? [] : []);
    const disponible = (estados.payload?.data ?? []).find((item) => item.codigo === 'DISPONIBLE');
    setDisponibleId(disponible ? String(disponible.id) : '');
    if (!config.ok) setError(mensajeOperacion(config));
  }

  useEffect(() => { load(); }, [unidad.id, unidad.configuracion?.id]);

  const ocupados = new Map(activos.map((item) => [item.posicion.id, item]));
  const libres = ejes.flatMap((eje) => eje.posiciones.filter((posicion) => posicion.activo && !ocupados.has(posicion.id)));

  async function despues(result) {
    if (!result.ok) {
      setError(mensajeOperacion(result));
      await load();
      return;
    }
    setError('');
    setPanel('');
    setBaja(null);
    await load();
    onChanged();
  }

  async function confirmarMontaje(event) {
    event.preventDefault();
    await despues(await montarNeumatico({
      neumatico_id: Number(montaje.neumatico_id),
      unidad_id: unidad.id,
      posicion_id: Number(montaje.posicion_id),
      fecha_montaje: montaje.fecha,
      km_montaje: montaje.km === '' ? null : Number(montaje.km),
      horometro_montaje: montaje.horometro === '' ? null : montaje.horometro,
      observacion: montaje.observacion || null,
    }));
  }

  async function confirmarBaja(event) {
    event.preventDefault();
    await despues(await desmontarNeumatico(baja.montaje_id, {
      fecha_desmontaje: baja.fecha,
      km_desmontaje: baja.km === '' ? null : Number(baja.km),
      horometro_desmontaje: baja.horometro === '' ? null : baja.horometro,
      motivo_desmontaje: baja.motivo,
    }));
  }

  function abrirRotacion() {
    const inicial = {};
    activos.forEach((item) => { inicial[item.neumatico.id] = String(item.posicion.id); });
    setCambios(inicial);
    setRotacionFecha(fechaLocal());
    setPanel('rotar');
    setError('');
  }

  function vistaRotacion() {
    return activos
      .filter((item) => cambios[item.neumatico.id] && cambios[item.neumatico.id] !== String(item.posicion.id))
      .map((item) => {
        const destino = ejes.flatMap((eje) => eje.posiciones).find((posicion) => String(posicion.id) === cambios[item.neumatico.id]);
        return { neumatico: item.neumatico.codigo, origen: item.posicion.codigo, destino: destino?.codigo || '—' };
      });
  }

  async function confirmarRotacion(event) {
    event.preventDefault();
    const seleccion = vistaRotacion();
    if (seleccion.length === 0) {
      setError('Selecciona al menos una posición distinta de la actual.');
      return;
    }
    const incluidos = activos.filter((item) => cambios[item.neumatico.id] && cambios[item.neumatico.id] !== String(item.posicion.id));
    const destinosElegidos = incluidos.map((item) => cambios[item.neumatico.id]);
    if (new Set(destinosElegidos).size !== destinosElegidos.length) {
      setError('Hay dos neumáticos con la misma posición destino.');
      return;
    }
    await despues(await rotarNeumaticos({
      unidad_id: unidad.id,
      fecha: rotacionFecha,
      km_unidad: null,
      horometro_unidad: null,
      observacion: 'Rotación desde la ficha de unidad',
      cambios: incluidos.map((item) => ({ neumatico_id: item.neumatico.id, posicion_destino_id: Number(cambios[item.neumatico.id]) })),
    }));
  }

  async function cargarDestinos(unidadId) {
    if (!unidadId) {
      setDestinos([]);
      return;
    }
    const [ficha, mounted] = await Promise.all([getUnidad(unidadId), listMontajesActivos(unidadId)]);
    if (!ficha.ok || !ficha.payload?.data?.configuracion?.id) {
      setDestinos([]);
      return;
    }
    const config = await getConfiguracion(ficha.payload.data.configuracion.id);
    const ocupadas = new Set((mounted.ok ? mounted.payload?.data ?? [] : []).map((item) => item.posicion.id));
    setDestinos((config.payload?.data?.ejes ?? []).flatMap((eje) => eje.posiciones).filter((posicion) => posicion.activo && !ocupadas.has(posicion.id)));
  }

  async function confirmarTransferencia(event) {
    event.preventDefault();
    await despues(await transferirNeumatico({
      neumatico_id: Number(transferencia.neumatico_id),
      unidad_destino_id: Number(transferencia.unidad_id),
      posicion_destino_id: Number(transferencia.posicion_id),
      fecha: transferencia.fecha,
      km_unidad: transferencia.km === '' ? null : Number(transferencia.km),
      horometro_unidad: transferencia.horometro === '' ? null : transferencia.horometro,
      observacion: transferencia.observacion || null,
    }));
  }

  return (
    <article className="panel">
      <div className="row-between">
        <h2>Neumáticos montados</h2>
        {operate && unidad.configuracion ? (
          <div className="actions">
            <button type="button" className="button button-primary" onClick={() => { setMontaje({ neumatico_id: '', neumatico_label: '', posicion_id: '', fecha: fechaLocal(), km: '', horometro: '', observacion: '' }); setPanel('montar'); setError(''); }}>Montar</button>
            <button type="button" className="button button-quiet" onClick={abrirRotacion} disabled={activos.length === 0}>Rotar</button>
            <button type="button" className="button button-quiet" onClick={() => { setTransferencia({ neumatico_id: '', unidad_id: '', unidad_label: '', posicion_id: '', fecha: fechaLocal(), km: '', horometro: '', observacion: '' }); setDestinos([]); setPanel('transferir'); setError(''); }} disabled={activos.length === 0}>Transferir</button>
          </div>
        ) : null}
      </div>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      {!unidad.configuracion ? <p>Esta unidad no tiene configuración. No hay posiciones donde montar.</p> : null}
      <div className="axle-list">
        {ejes.map((eje) => (
          <section key={eje.id} className="axle-card">
            <h3>Eje {eje.numero_eje} · {eje.nombre}</h3>
            <div className="position-grid">
              {eje.posiciones.map((posicion) => {
                const item = ocupados.get(posicion.id);
                return (
                  <article key={posicion.id} className={item ? 'position-card' : 'position-card libre'}>
                    <strong>{posicion.codigo}</strong>
                    <span>{posicion.lado} · {posicion.ubicacion}</span>
                    {item ? (
                      <>
                        <b>{item.neumatico.codigo}</b>
                        <span>{item.neumatico.medida}</span>
                        <span>Profundidad inicial {item.neumatico.profundidad_inicial_mm ?? '—'} mm</span>
                        <span>Montado {item.fecha_montaje}</span>
                        {operate ? <button type="button" className="button button-quiet" onClick={() => setBaja({ montaje_id: item.montaje_id, codigo: item.neumatico.codigo, fecha: fechaLocal(), km: '', horometro: '', motivo: '' })}>Desmontar</button> : null}
                      </>
                    ) : <span>{posicion.activo ? 'Libre' : 'Inactiva'}</span>}
                  </article>
                );
              })}
            </div>
          </section>
        ))}
      </div>

      {panel === 'montar' ? (
        <form className="stack-form" onSubmit={confirmarMontaje}>
          <h3>Montar en {unidad.codigo}</h3>
          <SearchSelect
            label="Neumático disponible"
            value={montaje.neumatico_id}
            selectedLabel={montaje.neumatico_label}
            placeholder="Buscar por código o serie"
            allowEmpty={false}
            required
            scopeKey={disponibleId}
            getLabel={(row) => row.codigo}
            fetchPage={(page) => listNeumaticos({ ...page, cliente_id: unidad.cliente.id, estado_id: disponibleId, sort: 'codigo' }).then(asPage)}
            onChange={(id, item) => setMontaje((current) => ({ ...current, neumatico_id: id, neumatico_label: item?.codigo || '' }))}
          />
          <label>Posición libre
            <select required value={montaje.posicion_id} onChange={(event) => setMontaje((current) => ({ ...current, posicion_id: event.target.value }))}>
              <option value="">Seleccione</option>
              {libres.map((posicion) => <option key={posicion.id} value={posicion.id}>{posicion.codigo}</option>)}
            </select>
          </label>
          <label>Fecha<input required type="datetime-local" value={montaje.fecha} onChange={(event) => setMontaje((current) => ({ ...current, fecha: event.target.value }))} /></label>
          <label>Km<input type="number" min="0" value={montaje.km} onChange={(event) => setMontaje((current) => ({ ...current, km: event.target.value }))} /></label>
          <label>Horómetro<input value={montaje.horometro} onChange={(event) => setMontaje((current) => ({ ...current, horometro: event.target.value }))} /></label>
          <label>Observación<input value={montaje.observacion} onChange={(event) => setMontaje((current) => ({ ...current, observacion: event.target.value }))} /></label>
          <div className="actions">
            <button className="button button-primary" type="submit">Confirmar montaje</button>
            <button className="button button-quiet" type="button" onClick={() => setPanel('')}>Cancelar</button>
          </div>
        </form>
      ) : null}

      {baja ? (
        <form className="stack-form" onSubmit={confirmarBaja}>
          <h3>Desmontar {baja.codigo}</h3>
          <label>Fecha<input required type="datetime-local" value={baja.fecha} onChange={(event) => setBaja((current) => ({ ...current, fecha: event.target.value }))} /></label>
          <label>Km<input type="number" min="0" value={baja.km} onChange={(event) => setBaja((current) => ({ ...current, km: event.target.value }))} /></label>
          <label>Horómetro<input value={baja.horometro} onChange={(event) => setBaja((current) => ({ ...current, horometro: event.target.value }))} /></label>
          <label>Motivo<input required value={baja.motivo} onChange={(event) => setBaja((current) => ({ ...current, motivo: event.target.value }))} /></label>
          <div className="actions">
            <button className="button button-primary" type="submit">Confirmar desmontaje</button>
            <button className="button button-quiet" type="button" onClick={() => setBaja(null)}>Cancelar</button>
          </div>
        </form>
      ) : null}

      {panel === 'rotar' ? (
        <form className="stack-form" onSubmit={confirmarRotacion}>
          <h3>Rotación</h3>
          {activos.map((item) => (
            <label key={item.neumatico.id}>{item.neumatico.codigo} · ahora en {item.posicion.codigo}
              <select value={cambios[item.neumatico.id] || ''} onChange={(event) => setCambios((current) => ({ ...current, [item.neumatico.id]: event.target.value }))}>
                {ejes.flatMap((eje) => eje.posiciones).filter((posicion) => posicion.activo).map((posicion) => (
                  <option key={posicion.id} value={posicion.id}>{posicion.codigo}</option>
                ))}
              </select>
            </label>
          ))}
          <label>Fecha<input required type="datetime-local" value={rotacionFecha} onChange={(event) => setRotacionFecha(event.target.value)} /></label>
          <ul className="op-preview">
            {vistaRotacion().length === 0 ? <li>Sin cambios respecto de la ubicación actual.</li> : vistaRotacion().map((item) => (
              <li key={item.neumatico}>{item.neumatico}: {item.origen} → {item.destino}</li>
            ))}
          </ul>
          <div className="actions">
            <button className="button button-primary" type="submit">Confirmar rotación</button>
            <button className="button button-quiet" type="button" onClick={() => setPanel('')}>Cancelar</button>
          </div>
        </form>
      ) : null}

      {panel === 'transferir' ? (
        <form className="stack-form" onSubmit={confirmarTransferencia}>
          <h3>Transferencia</h3>
          <p>Origen: {unidad.codigo}</p>
          <label>Neumático
            <select required value={transferencia.neumatico_id} onChange={(event) => setTransferencia((current) => ({ ...current, neumatico_id: event.target.value, posicion_id: '' }))}>
              <option value="">Seleccione</option>
              {activos.map((item) => <option key={item.neumatico.id} value={item.neumatico.id}>{item.neumatico.codigo} · {item.posicion.codigo}</option>)}
            </select>
          </label>
          <SearchSelect
            label="Unidad destino"
            value={transferencia.unidad_id}
            selectedLabel={transferencia.unidad_label}
            placeholder="Buscar unidad operativa"
            allowEmpty={false}
            required
            scopeKey={String(unidad.cliente.id)}
            getLabel={(row) => row.codigo}
            fetchPage={(page) => listUnidades({ ...page, cliente_id: unidad.cliente.id, estado: 'OPERATIVA', sort: 'codigo' }).then(asPage)}
            onChange={(id, item) => {
              setTransferencia((current) => ({ ...current, unidad_id: id, unidad_label: item?.codigo || '', posicion_id: '' }));
              cargarDestinos(id);
            }}
          />
          <label>Posición destino
            <select required value={transferencia.posicion_id} onChange={(event) => setTransferencia((current) => ({ ...current, posicion_id: event.target.value }))}>
              <option value="">Seleccione</option>
              {destinos.map((posicion) => <option key={posicion.id} value={posicion.id}>{posicion.codigo}</option>)}
            </select>
          </label>
          <label>Fecha<input required type="datetime-local" value={transferencia.fecha} onChange={(event) => setTransferencia((current) => ({ ...current, fecha: event.target.value }))} /></label>
          <label>Km<input type="number" min="0" value={transferencia.km} onChange={(event) => setTransferencia((current) => ({ ...current, km: event.target.value }))} /></label>
          <label>Horómetro<input value={transferencia.horometro} onChange={(event) => setTransferencia((current) => ({ ...current, horometro: event.target.value }))} /></label>
          <label>Observación<input value={transferencia.observacion} onChange={(event) => setTransferencia((current) => ({ ...current, observacion: event.target.value }))} /></label>
          <div className="actions">
            <button className="button button-primary" type="submit">Confirmar transferencia</button>
            <button className="button button-quiet" type="button" onClick={() => setPanel('')}>Cancelar</button>
          </div>
        </form>
      ) : null}

      <h3>Movimientos de la unidad</h3>
      <MovimientosTabla rows={movimientos} />
    </article>
  );
}
