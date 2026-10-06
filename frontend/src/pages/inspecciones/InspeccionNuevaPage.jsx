import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { errorMessage, listClientes } from '../../api/clientes';
import { createInspeccion, pagina } from '../../api/inspecciones';
import { listUnidades } from '../../api/unidades';
import { useAuth } from '../../auth/AuthContext';
import { SearchSelect } from '../../components/SearchSelect';
import { canInspect, isAdmin } from '../../utils/access';
import { ahoraLocal, fechaApi } from './texto';

export function InspeccionNuevaPage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const admin = isAdmin(user);
  const propios = user?.clientes ?? [];
  const [clienteId, setClienteId] = useState('');
  const [clienteLabel, setClienteLabel] = useState('');
  const [unidadId, setUnidadId] = useState('');
  const [unidadLabel, setUnidadLabel] = useState('');
  const [fecha, setFecha] = useState(ahoraLocal);
  const [km, setKm] = useState('');
  const [horometro, setHorometro] = useState('');
  const [observacion, setObservacion] = useState('');
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!admin && propios.length === 1) {
      setClienteId(String(propios[0].id));
      setClienteLabel(propios[0].nombre_comercial || propios[0].razon_social);
    }
  }, [admin, propios]);

  if (!canInspect(user)) {
    return <section><p className="form-error">No puede crear inspecciones.</p><Link to="/inspecciones">Volver</Link></section>;
  }

  async function guardar(event) {
    event.preventDefault();
    setSaving(true);
    const result = await createInspeccion({
      unidad_id: Number(unidadId),
      fecha_inspeccion: fechaApi(fecha),
      kilometraje: km === '' ? null : Number(km),
      horometro: horometro === '' ? null : horometro,
      observacion_general: observacion || null,
    });
    setSaving(false);
    if (!result.ok) {
      setError(errorMessage(result));
      return;
    }
    navigate(`/inspecciones/${result.payload.data.id}`);
  }

  return (
    <section>
      <div className="page-heading">
        <p className="eyebrow">Nueva inspección</p>
        <h1>Abrir borrador</h1>
        <p className="lede">La inspección queda en borrador. Las mediciones se completan después, posición por posición.</p>
      </div>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <form className="stack-form panel" onSubmit={guardar}>
        {admin ? (
          <SearchSelect
            label="Cliente"
            value={clienteId}
            selectedLabel={clienteLabel}
            placeholder="Buscar cliente"
            emptyLabel="Seleccione un cliente"
            allowEmpty={false}
            required
            fetchPage={(params) => listClientes({ ...params, sort: 'razon_social', order: 'asc' }).then(pagina)}
            getLabel={(item) => item.nombre_comercial || item.razon_social}
            onChange={(id, item) => {
              setClienteId(id);
              setClienteLabel(item ? (item.nombre_comercial || item.razon_social) : '');
              setUnidadId('');
              setUnidadLabel('');
            }}
          />
        ) : propios.length > 1 ? (
          <label>Cliente
            <select required value={clienteId} onChange={(event) => { setClienteId(event.target.value); setUnidadId(''); setUnidadLabel(''); }}>
              <option value="">Seleccione</option>
              {propios.map((item) => <option key={item.id} value={item.id}>{item.nombre_comercial || item.razon_social}</option>)}
            </select>
          </label>
        ) : null}
        <SearchSelect
          label="Unidad"
          value={unidadId}
          selectedLabel={unidadLabel}
          placeholder="Buscar unidad"
          emptyLabel="Seleccione una unidad"
          allowEmpty={false}
          required
          disabled={!clienteId}
          scopeKey={clienteId || 'sin-cliente'}
          fetchPage={(params) => listUnidades({ ...params, cliente_id: clienteId, sort: 'codigo', order: 'asc' }).then(pagina)}
          getLabel={(item) => item.codigo}
          onChange={(id, item) => {
            setUnidadId(id);
            setUnidadLabel(item?.codigo || '');
          }}
        />
        <label>Fecha
          <input type="datetime-local" required value={fecha} onChange={(event) => setFecha(event.target.value)} />
        </label>
        <div className="measure-grid">
          <label>Kilometraje
            <input type="number" min="0" step="1" value={km} onChange={(event) => setKm(event.target.value)} />
          </label>
          <label>Horómetro
            <input type="number" min="0" step="0.01" value={horometro} onChange={(event) => setHorometro(event.target.value)} />
          </label>
        </div>
        <label>Observación general
          <textarea value={observacion} maxLength={2000} onChange={(event) => setObservacion(event.target.value)} />
        </label>
        <p className="lede">Una unidad en baja no admite inspección. Las posiciones vacías se verán como “Sin neumático”.</p>
        <div className="actions">
          <button className="button button-primary" type="submit" disabled={saving || !unidadId}>Crear borrador</button>
          <Link className="button button-quiet" to="/inspecciones">Cancelar</Link>
        </div>
      </form>
    </section>
  );
}
