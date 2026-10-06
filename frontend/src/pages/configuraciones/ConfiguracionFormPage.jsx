import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { errorMessage } from '../../api/clientes';
import { createConfiguracion, getConfiguracion, updateConfiguracion } from '../../api/unidades';
import { useAuth } from '../../auth/AuthContext';
import { canManageConfiguraciones } from '../../utils/access';

const POSICION = { codigo: '', lado: 'IZQUIERDO', ubicacion: 'SIMPLE', orden: 1, activo: true };

function ejeVacio(numero) {
  return {
    numero_eje: numero,
    nombre: `Eje ${numero}`,
    orden: numero,
    posiciones: [{ ...POSICION, codigo: `E${numero}-I`, orden: 1 }, { ...POSICION, codigo: `E${numero}-D`, lado: 'DERECHO', orden: 2 }],
  };
}

export function ConfiguracionFormPage() {
  const { id } = useParams();
  const editing = Boolean(id);
  const navigate = useNavigate();
  const { user } = useAuth();
  const [nombre, setNombre] = useState('');
  const [descripcion, setDescripcion] = useState('');
  const [ejes, setEjes] = useState([ejeVacio(1)]);
  const [bloqueada, setBloqueada] = useState(false);
  const [error, setError] = useState('');
  const [pending, setPending] = useState(false);

  useEffect(() => {
    if (!editing) return undefined;
    let cancelled = false;
    getConfiguracion(id).then((result) => {
      if (cancelled || !result.ok) {
        if (!cancelled && !result.ok) setError(errorMessage(result));
        return;
      }
      const data = result.payload.data;
      setNombre(data.nombre);
      setDescripcion(data.descripcion ?? '');
      setBloqueada(Boolean(data.estructura_bloqueada));
      setEjes(data.ejes.map((eje) => ({
        numero_eje: eje.numero_eje,
        nombre: eje.nombre,
        orden: eje.orden,
        posiciones: eje.posiciones.map((posicion) => ({
          codigo: posicion.codigo,
          lado: posicion.lado,
          ubicacion: posicion.ubicacion,
          orden: posicion.orden,
          activo: posicion.activo,
        })),
      })));
    });
    return () => { cancelled = true; };
  }, [editing, id]);

  if (!canManageConfiguraciones(user)) {
    return <section className="status-card"><h1>Acceso denegado</h1><Link to="/configuraciones-unidad">Volver</Link></section>;
  }

  function updateEje(index, patch) {
    setEjes((current) => current.map((eje, ejeIndex) => ejeIndex === index ? { ...eje, ...patch } : eje));
  }

  function updatePosicion(ejeIndex, posicionIndex, patch) {
    setEjes((current) => current.map((eje, index) => index !== ejeIndex ? eje : {
      ...eje,
      posiciones: eje.posiciones.map((posicion, inner) => inner === posicionIndex ? { ...posicion, ...patch } : posicion),
    }));
  }

  async function onSubmit(event) {
    event.preventDefault();
    setPending(true);
    setError('');
    const payload = bloqueada
      ? { nombre, descripcion: descripcion || null }
      : {
          nombre,
          descripcion: descripcion || null,
          ejes: ejes.map((eje) => ({
            ...eje,
            numero_eje: Number(eje.numero_eje),
            orden: Number(eje.orden),
            posiciones: eje.posiciones.map((posicion) => ({ ...posicion, orden: Number(posicion.orden) })),
          })),
        };
    const result = editing ? await updateConfiguracion(id, payload) : await createConfiguracion(payload);
    setPending(false);
    if (!result.ok) {
      setError(errorMessage(result));
      return;
    }
    navigate(`/configuraciones-unidad/${result.payload.data.id}`);
  }

  return (
    <section className="form-card">
      <p className="eyebrow">{editing ? 'Edición' : 'Alta'}</p>
      <h1>{editing ? 'Editar configuración' : 'Nueva configuración'}</h1>
      {bloqueada ? <p className="lock-note">Configuración en uso. La estructura de ejes y posiciones está bloqueada para preservar el histórico. Puede duplicarla para crear una variante.</p> : null}
      <form className="stack-form" onSubmit={onSubmit}>
        <label>Nombre<input value={nombre} required onChange={(event) => setNombre(event.target.value)} /></label>
        <label>Descripción<input value={descripcion} onChange={(event) => setDescripcion(event.target.value)} /></label>
        {!bloqueada ? ejes.map((eje, ejeIndex) => (
          <article className="axis" key={ejeIndex}>
            <h2>Eje {ejeIndex + 1}</h2>
            <div className="split">
              <label>Número<input inputMode="numeric" value={eje.numero_eje} onChange={(event) => updateEje(ejeIndex, { numero_eje: event.target.value })} /></label>
              <label>Orden<input inputMode="numeric" value={eje.orden} onChange={(event) => updateEje(ejeIndex, { orden: event.target.value })} /></label>
            </div>
            <label>Nombre<input value={eje.nombre} onChange={(event) => updateEje(ejeIndex, { nombre: event.target.value })} /></label>
            {eje.posiciones.map((posicion, posicionIndex) => (
              <div className="split" key={posicionIndex}>
                <label>Código<input value={posicion.codigo} onChange={(event) => updatePosicion(ejeIndex, posicionIndex, { codigo: event.target.value })} /></label>
                <label>Lado
                  <select value={posicion.lado} onChange={(event) => updatePosicion(ejeIndex, posicionIndex, { lado: event.target.value })}>
                    <option value="IZQUIERDO">Izquierdo</option>
                    <option value="DERECHO">Derecho</option>
                    <option value="CENTRO">Centro</option>
                  </select>
                </label>
                <label>Ubicación
                  <select value={posicion.ubicacion} onChange={(event) => updatePosicion(ejeIndex, posicionIndex, { ubicacion: event.target.value })}>
                    <option value="SIMPLE">Simple</option>
                    <option value="INTERIOR">Interior</option>
                    <option value="EXTERIOR">Exterior</option>
                    <option value="CENTRAL">Central</option>
                  </select>
                </label>
                <label>Orden<input inputMode="numeric" value={posicion.orden} onChange={(event) => updatePosicion(ejeIndex, posicionIndex, { orden: event.target.value })} /></label>
              </div>
            ))}
            <button type="button" className="button button-quiet" onClick={() => updateEje(ejeIndex, { posiciones: [...eje.posiciones, { ...POSICION, orden: eje.posiciones.length + 1 }] })}>Agregar posición</button>
          </article>
        )) : null}
        {!bloqueada ? <button type="button" className="button button-quiet" onClick={() => setEjes((current) => [...current, ejeVacio(current.length + 1)])}>Agregar eje</button> : null}
        {error ? <p className="form-error" role="alert">{error}</p> : null}
        <div className="actions">
          <button className="button button-primary" disabled={pending} type="submit">{pending ? 'Guardando…' : 'Guardar'}</button>
          <Link to={editing ? `/configuraciones-unidad/${id}` : '/configuraciones-unidad'}>Cancelar</Link>
        </div>
      </form>
    </section>
  );
}
