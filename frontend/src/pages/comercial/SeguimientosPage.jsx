import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { createSeguimiento, listClientesComerciales, listSeguimientos } from '../../api/comercial';
import { pagina } from '../../api/inspecciones';
import { useAuth } from '../../auth/AuthContext';
import { Pager } from '../../components/Pager';
import { SearchSelect } from '../../components/SearchSelect';
import { canWriteComercial } from '../../utils/access';
import { clienteNombre, mensaje, TIPOS_SEGUIMIENTO } from './texto';

const EMPTY = { cliente_id: '', tipo: '', oportunidad_id: '' };

export function SeguimientosPage() {
  const { user } = useAuth();
  const escribir = canWriteComercial(user);
  const [draft, setDraft] = useState(EMPTY);
  const [clienteLabel, setClienteLabel] = useState('');
  const [filters, setFilters] = useState(EMPTY);
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState('');
  const [form, setForm] = useState({ cliente_id: '', cliente_label: '', tipo: 'LLAMADA', fecha: '', resultado: '', proximo_seguimiento: '', observacion: '' });
  const [enviando, setEnviando] = useState(false);

  useEffect(() => {
    let cancelled = false;
    listSeguimientos({ ...filters, page, limit: 20, sort: 'fecha', order: 'desc' }).then((result) => {
      if (cancelled) return;
      if (!result.ok) {
        setError(mensaje(result));
        setRows([]);
        setTotal(0);
        return;
      }
      setError('');
      setRows(result.payload?.data ?? []);
      setTotal(result.payload?.total ?? 0);
    });
    return () => { cancelled = true; };
  }, [filters, page]);

  async function guardar(event) {
    event.preventDefault();
    setEnviando(true);
    const result = await createSeguimiento({
      cliente_id: Number(form.cliente_id),
      tipo: form.tipo,
      fecha: form.fecha.replace('T', ' '),
      resultado: form.resultado.trim() || null,
      proximo_seguimiento: form.proximo_seguimiento ? form.proximo_seguimiento.replace('T', ' ') : null,
      observacion: form.observacion.trim() || null,
    });
    setEnviando(false);
    if (!result.ok) {
      setError(mensaje(result));
      return;
    }
    setForm({ cliente_id: '', cliente_label: '', tipo: 'LLAMADA', fecha: '', resultado: '', proximo_seguimiento: '', observacion: '' });
    setFilters({ ...filters });
  }

  return (
    <section>
      <div className="page-heading">
        <p className="eyebrow">Comercial</p>
        <h1>Seguimientos</h1>
        <p className="lede">Los seguimientos registrados no se editan ni se eliminan. Una corrección se anota como un seguimiento nuevo.</p>
      </div>
      <form className="filters" onSubmit={(event) => { event.preventDefault(); setPage(1); setFilters(draft); }}>
        <SearchSelect
          label="Cliente"
          value={draft.cliente_id}
          selectedLabel={clienteLabel}
          placeholder="Buscar cliente"
          emptyLabel="Todos"
          scopeKey="seguimientos-cliente"
          fetchPage={(params) => listClientesComerciales({ ...params, sort: 'razon_social', order: 'asc' }).then(pagina)}
          getLabel={(item) => item.nombre_comercial || item.razon_social}
          onChange={(value, item) => { setDraft({ ...draft, cliente_id: value }); setClienteLabel(item ? (item.nombre_comercial || item.razon_social) : ''); }}
        />
        <label>Tipo
          <select value={draft.tipo} onChange={(event) => setDraft({ ...draft, tipo: event.target.value })}>
            <option value="">Todos</option>
            {TIPOS_SEGUIMIENTO.map(([codigo, etiqueta]) => <option key={codigo} value={codigo}>{etiqueta}</option>)}
          </select>
        </label>
        <button className="button button-primary" type="submit">Filtrar</button>
      </form>
      {escribir ? (
        <form className="stack-form" onSubmit={guardar}>
          <h2>Seguimiento general</h2>
          <SearchSelect
            label="Cliente"
            value={form.cliente_id}
            selectedLabel={form.cliente_label}
            placeholder="Buscar cliente"
            scopeKey="alta-seguimiento"
            fetchPage={(params) => listClientesComerciales({ ...params, sort: 'razon_social', order: 'asc' }).then(pagina)}
            getLabel={(item) => item.nombre_comercial || item.razon_social}
            onChange={(value, item) => setForm({ ...form, cliente_id: value, cliente_label: item ? (item.nombre_comercial || item.razon_social) : '' })}
          />
          <label>Tipo
            <select value={form.tipo} onChange={(event) => setForm({ ...form, tipo: event.target.value })}>
              {TIPOS_SEGUIMIENTO.map(([codigo, etiqueta]) => <option key={codigo} value={codigo}>{etiqueta}</option>)}
            </select>
          </label>
          <label>Fecha
            <input type="datetime-local" required value={form.fecha} onChange={(event) => setForm({ ...form, fecha: event.target.value })} />
          </label>
          <label>Resultado
            <input value={form.resultado} onChange={(event) => setForm({ ...form, resultado: event.target.value })} />
          </label>
          <label>Próximo seguimiento
            <input type="datetime-local" value={form.proximo_seguimiento} onChange={(event) => setForm({ ...form, proximo_seguimiento: event.target.value })} />
          </label>
          <label>Observación
            <textarea rows={3} value={form.observacion} onChange={(event) => setForm({ ...form, observacion: event.target.value })} />
          </label>
          <button className="button button-primary" type="submit" disabled={enviando}>Registrar</button>
        </form>
      ) : null}
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <ol className="record-list">
        {rows.length === 0 ? <li>No hay seguimientos.</li> : rows.map((row) => (
          <li key={row.id}>
            <div>
              <strong>{TIPOS_SEGUIMIENTO.find(([codigo]) => codigo === row.tipo)?.[1] || row.tipo} · {clienteNombre(row.cliente)}</strong>
              <span>{row.resultado || row.observacion || 'Sin resultado'}</span>
              {row.oportunidad ? <span>Oportunidad: <Link to={`/comercial/oportunidades/${row.oportunidad.id}`}>{row.oportunidad.titulo}</Link></span> : <span>Seguimiento general</span>}
              {row.proximo_seguimiento ? <span>Próximo: {row.proximo_seguimiento}</span> : null}
            </div>
            <span>{row.fecha} · {row.usuario}</span>
          </li>
        ))}
      </ol>
      <Pager page={page} total={total} limit={20} onPage={setPage} />
    </section>
  );
}
