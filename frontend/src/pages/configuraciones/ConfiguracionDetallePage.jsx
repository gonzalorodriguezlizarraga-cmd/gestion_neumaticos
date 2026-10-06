import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { errorMessage } from '../../api/clientes';
import { changeConfiguracionActivo, duplicateConfiguracion, getConfiguracion } from '../../api/unidades';
import { useAuth } from '../../auth/AuthContext';
import { canManageConfiguraciones } from '../../utils/access';

export function ConfiguracionDetallePage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { user } = useAuth();
  const [item, setItem] = useState(null);
  const [nombre, setNombre] = useState('');
  const [error, setError] = useState('');
  const canWrite = canManageConfiguraciones(user);

  async function load() {
    const result = await getConfiguracion(id);
    if (!result.ok) {
      setError(errorMessage(result));
      setItem(null);
      return;
    }
    setItem(result.payload.data);
    setNombre(`${result.payload.data.nombre} copia`);
    setError('');
  }

  useEffect(() => { load(); }, [id]);
  if (!item && error) return <section><p className="form-error">{error}</p><Link to="/configuraciones-unidad">Volver</Link></section>;
  if (!item) return <p>Cargando configuración…</p>;

  return (
    <section>
      <div className="page-heading row-between">
        <div>
          <p className="eyebrow">Configuración</p>
          <h1>{item.nombre}</h1>
          <p className="lede">{item.descripcion || 'Sin descripción'} · {item.activo ? 'Activa' : 'Inactiva'}</p>
        </div>
        {canWrite ? <Link className="button button-primary" to={`/configuraciones-unidad/${id}/editar`}>Editar</Link> : null}
      </div>
      {item.estructura_bloqueada ? (
        <p className="lock-note">Configuración en uso. La estructura de ejes y posiciones está bloqueada para preservar el histórico.</p>
      ) : null}
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      {(item.ejes ?? []).map((eje) => (
        <article className="axis" key={eje.id}>
          <h3>Eje {eje.numero_eje}. {eje.nombre}</h3>
          <ul className="record-list">
            {eje.posiciones.map((posicion) => (
              <li key={posicion.id}>
                <div>
                  <strong>{posicion.codigo}</strong>
                  <span>{lado(posicion.lado)} · {ubicacion(posicion.ubicacion)} · {posicion.activo ? 'Activa' : 'Inactiva'}</span>
                </div>
              </li>
            ))}
          </ul>
        </article>
      ))}
      {canWrite ? (
        <form className="stack-form" onSubmit={async (event) => {
          event.preventDefault();
          const result = await duplicateConfiguracion(id, nombre);
          if (!result.ok) { setError(errorMessage(result)); return; }
          navigate(`/configuraciones-unidad/${result.payload.data.id}`);
        }}>
          <h2>Duplicar configuración</h2>
          <label>Nuevo nombre<input value={nombre} onChange={(event) => setNombre(event.target.value)} required /></label>
          <button className="button button-primary" type="submit">Duplicar configuración</button>
        </form>
      ) : null}
      {canWrite ? (
        <button type="button" className="button button-quiet" onClick={async () => {
          const result = await changeConfiguracionActivo(id, !item.activo);
          if (!result.ok) { setError(errorMessage(result)); return; }
          setItem(result.payload.data);
        }}>{item.activo ? 'Desactivar' : 'Activar'}</button>
      ) : null}
    </section>
  );
}

function lado(value) {
  return { IZQUIERDO: 'Izquierdo', DERECHO: 'Derecho', CENTRO: 'Centro' }[value] || value;
}

function ubicacion(value) {
  return { INTERIOR: 'Interior', EXTERIOR: 'Exterior', SIMPLE: 'Simple', CENTRAL: 'Central' }[value] || value;
}
