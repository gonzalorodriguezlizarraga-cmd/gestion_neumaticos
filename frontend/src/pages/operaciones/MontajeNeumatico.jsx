import { useEffect, useState } from 'react';
import { asPage } from '../../api/neumaticos';
import { montarNeumatico } from '../../api/operaciones';
import { getConfiguracion, getUnidad, listUnidades } from '../../api/unidades';
import { listMontajesActivos } from '../../api/operaciones';
import { SearchSelect } from '../../components/SearchSelect';
import { fechaLocal, mensajeOperacion } from './texto';

export function MontajeNeumatico({ neumatico, onDone }) {
  const [form, setForm] = useState({ unidad_id: '', unidad_label: '', posicion_id: '', fecha: fechaLocal(), km: '', horometro: '', observacion: '' });
  const [posiciones, setPosiciones] = useState([]);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    async function load() {
      if (!form.unidad_id) {
        setPosiciones([]);
        return;
      }
      const [ficha, activos] = await Promise.all([getUnidad(form.unidad_id), listMontajesActivos(form.unidad_id)]);
      if (cancelled || !ficha.ok || !ficha.payload?.data?.configuracion?.id) {
        setPosiciones([]);
        return;
      }
      const config = await getConfiguracion(ficha.payload.data.configuracion.id);
      const ocupadas = new Set((activos.ok ? activos.payload?.data ?? [] : []).map((item) => item.posicion.id));
      setPosiciones((config.payload?.data?.ejes ?? []).flatMap((eje) => eje.posiciones).filter((item) => item.activo && !ocupadas.has(item.id)));
    }
    load();
    return () => { cancelled = true; };
  }, [form.unidad_id]);

  return (
    <form className="panel stack-form" onSubmit={async (event) => {
      event.preventDefault();
      const result = await montarNeumatico({
        neumatico_id: neumatico.id,
        unidad_id: Number(form.unidad_id),
        posicion_id: Number(form.posicion_id),
        fecha_montaje: form.fecha,
        km_montaje: form.km === '' ? null : Number(form.km),
        horometro_montaje: form.horometro === '' ? null : form.horometro,
        observacion: form.observacion || null,
      });
      if (!result.ok) {
        setError(mensajeOperacion(result));
        return;
      }
      setError('');
      onDone();
    }}>
      <h2>Montar {neumatico.codigo}</h2>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <SearchSelect
        label="Unidad"
        value={form.unidad_id}
        selectedLabel={form.unidad_label}
        placeholder="Buscar unidad operativa"
        allowEmpty={false}
        required
        scopeKey={String(neumatico.cliente.id)}
        getLabel={(row) => row.codigo}
        fetchPage={(page) => listUnidades({ ...page, cliente_id: neumatico.cliente.id, estado: 'OPERATIVA', sort: 'codigo' }).then(asPage)}
        onChange={(id, item) => setForm((current) => ({ ...current, unidad_id: id, unidad_label: item?.codigo || '', posicion_id: '' }))}
      />
      <label>Posición libre
        <select required value={form.posicion_id} onChange={(event) => setForm((current) => ({ ...current, posicion_id: event.target.value }))}>
          <option value="">Seleccione</option>
          {posiciones.map((item) => <option key={item.id} value={item.id}>{item.codigo}</option>)}
        </select>
      </label>
      <label>Fecha<input required type="datetime-local" value={form.fecha} onChange={(event) => setForm((current) => ({ ...current, fecha: event.target.value }))} /></label>
      <label>Km<input type="number" min="0" value={form.km} onChange={(event) => setForm((current) => ({ ...current, km: event.target.value }))} /></label>
      <label>Horómetro<input value={form.horometro} onChange={(event) => setForm((current) => ({ ...current, horometro: event.target.value }))} /></label>
      <label>Observación<input value={form.observacion} onChange={(event) => setForm((current) => ({ ...current, observacion: event.target.value }))} /></label>
      <button className="button button-primary" type="submit">Confirmar montaje</button>
    </form>
  );
}
