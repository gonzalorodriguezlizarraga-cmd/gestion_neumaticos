export function MovimientosTabla({ rows, conNeumatico = true }) {
  if (rows.length === 0) {
    return <p>No hay movimientos registrados.</p>;
  }

  return (
    <table className="data-table">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Tipo</th>
          {conNeumatico ? <th>Neumático</th> : null}
          <th>Unidad origen</th>
          <th>Posición origen</th>
          <th>Unidad destino</th>
          <th>Posición destino</th>
          <th>Usuario</th>
          <th>Observación</th>
        </tr>
      </thead>
      <tbody>
        {rows.map((row) => (
          <tr key={row.id}>
            <td data-label="Fecha">{row.fecha}</td>
            <td data-label="Tipo">{row.tipo.nombre}</td>
            {conNeumatico ? <td data-label="Neumático">{row.neumatico.codigo}</td> : null}
            <td data-label="Unidad origen">{row.unidad_origen?.codigo || '—'}</td>
            <td data-label="Posición origen">{row.posicion_origen?.codigo || '—'}</td>
            <td data-label="Unidad destino">{row.unidad_destino?.codigo || '—'}</td>
            <td data-label="Posición destino">{row.posicion_destino?.codigo || '—'}</td>
            <td data-label="Usuario">{row.usuario}</td>
            <td data-label="Observación">{row.observacion || '—'}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
