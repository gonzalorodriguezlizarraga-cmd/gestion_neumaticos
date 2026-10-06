import { Link } from 'react-router-dom';
import { EstadoBadge } from '../../components/EstadoBadge';
import { lectura, resumenTexto } from './texto';

export function InspeccionesTabla({ rows, vacio, mostrarUnidad = true }) {
  return (
    <div className="table-wrap">
      <table className="data-table">
        <thead>
          <tr>
            <th>Fecha</th>
            {mostrarUnidad ? <th>Unidad</th> : null}
            <th>Técnico</th>
            <th>Estado</th>
            <th>Km/Horómetro</th>
            <th>Resultado</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr><td colSpan={mostrarUnidad ? 7 : 6}>{vacio}</td></tr>
          ) : rows.map((row) => (
            <tr key={row.id}>
              <td data-label="Fecha">{lectura(row.fecha_inspeccion)}</td>
              {mostrarUnidad ? <td data-label="Unidad">{row.unidad.codigo}</td> : null}
              <td data-label="Técnico">{row.tecnico.nombre}</td>
              <td data-label="Estado"><EstadoBadge estado={row.estado} /></td>
              <td data-label="Km/Horómetro">{row.kilometraje ?? 'Sin km'} · {row.horometro ?? 'Sin horómetro'}</td>
              <td data-label="Resultado">{resumenTexto(row.resumen)}</td>
              <td data-label="Acciones"><Link to={`/inspecciones/${row.id}`}>Abrir</Link></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
