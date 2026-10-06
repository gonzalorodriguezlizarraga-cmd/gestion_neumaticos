import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { errorDetails, errorMessage } from '../../api/clientes';
import {
  asPage,
  changeMarcaActivo, changeMedidaActivo, changeModeloActivo,
  createMarca, createMedida, createModelo,
  listMarcas, listMedidas, listModelos,
  updateMarca, updateMedida, updateModelo,
} from '../../api/neumaticos';
import { Pager } from '../../components/Pager';
import { SearchSelect } from '../../components/SearchSelect';
import { useAuth } from '../../auth/AuthContext';
import { canManageNeumaticos } from '../../utils/access';

export function CatalogoNeumaticosPage() {
  const { user } = useAuth();
  const operate = canManageNeumaticos(user);

  return (
    <section>
      <div className="page-heading">
        <p className="eyebrow">Catálogo técnico</p>
        <h1>Marcas, modelos y medidas</h1>
        <p className="lede">Datos maestros del neumático. Desactivar un ítem no altera los activos ya registrados. <Link to="/neumaticos">Volver a neumáticos</Link></p>
      </div>
      <Marcas operate={operate} />
      <Modelos operate={operate} />
      <Medidas operate={operate} />
    </section>
  );
}

function Marcas({ operate }) {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [applied, setApplied] = useState('');
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [form, setForm] = useState({ id: null, nombre: '' });
  const [error, setError] = useState('');

  async function load(nextPage = page, nextSearch = applied) {
    const result = await listMarcas({ page: nextPage, limit: 10, search: nextSearch, sort: 'nombre', order: 'asc' });
    if (!result.ok) {
      setError(errorMessage(result));
      return;
    }
    setRows(result.payload?.data ?? []);
    setTotal(result.payload?.total ?? 0);
    setError('');
  }

  useEffect(() => { load(page, applied); }, [page, applied]);

  async function onSubmit(event) {
    event.preventDefault();
    const result = form.id ? await updateMarca(form.id, { nombre: form.nombre }) : await createMarca({ nombre: form.nombre });
    if (!result.ok) {
      setError(firstError(result));
      return;
    }
    setForm({ id: null, nombre: '' });
    load();
  }

  return (
    <article className="panel">
      <h2>Marcas</h2>
      <form className="filters" onSubmit={(event) => { event.preventDefault(); setPage(1); setApplied(search); }}>
        <label>Buscar marca<input value={search} onChange={(event) => setSearch(event.target.value)} /></label>
        <button className="button button-primary" type="submit">Buscar</button>
      </form>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <div className="table-wrap">
        <table className="data-table">
          <thead><tr><th>Nombre</th><th>Estado</th>{operate ? <th>Acciones</th> : null}</tr></thead>
          <tbody>
            {rows.length === 0 ? <tr><td colSpan={operate ? 3 : 2}>No hay marcas.</td></tr> : rows.map((row) => (
              <tr key={row.id}>
                <td data-label="Nombre">{row.nombre}</td>
                <td data-label="Estado">{row.activo ? 'Activa' : 'Inactiva'}</td>
                {operate ? (
                  <td data-label="Acciones">
                    <button type="button" className="button button-quiet" onClick={() => setForm({ id: row.id, nombre: row.nombre })}>Editar</button>
                    <button type="button" className="button button-quiet" onClick={() => changeMarcaActivo(row.id, !row.activo).then(() => load())}>{row.activo ? 'Desactivar' : 'Activar'}</button>
                  </td>
                ) : null}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Pager page={page} limit={10} total={total} onPage={setPage} />
      {operate ? (
        <form className="stack-form" onSubmit={onSubmit}>
          <h3>{form.id ? 'Editar marca' : 'Nueva marca'}</h3>
          <label>Nombre<input value={form.nombre} required onChange={(event) => setForm({ ...form, nombre: event.target.value })} /></label>
          <div className="actions">
            <button className="button button-primary" type="submit">{form.id ? 'Guardar marca' : 'Crear marca'}</button>
            {form.id ? <button type="button" className="button button-quiet" onClick={() => setForm({ id: null, nombre: '' })}>Cancelar</button> : null}
          </div>
        </form>
      ) : null}
    </article>
  );
}

function Modelos({ operate }) {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [applied, setApplied] = useState('');
  const [marcaId, setMarcaId] = useState('');
  const [marcaLabel, setMarcaLabel] = useState('');
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [form, setForm] = useState({ id: null, marca_id: '', marca_label: '', nombre: '', descripcion: '' });
  const [error, setError] = useState('');

  async function load(nextPage = page, nextSearch = applied, nextMarca = marcaId) {
    const result = await listModelos({ page: nextPage, limit: 10, search: nextSearch, marca_id: nextMarca, sort: 'nombre', order: 'asc' });
    if (!result.ok) {
      setError(errorMessage(result));
      return;
    }
    setRows(result.payload?.data ?? []);
    setTotal(result.payload?.total ?? 0);
    setError('');
  }

  useEffect(() => { load(page, applied, marcaId); }, [page, applied, marcaId]);

  async function onSubmit(event) {
    event.preventDefault();
    const payload = { marca_id: Number(form.marca_id), nombre: form.nombre, descripcion: form.descripcion || null };
    const result = form.id ? await updateModelo(form.id, payload) : await createModelo(payload);
    if (!result.ok) {
      setError(firstError(result));
      return;
    }
    setForm({ id: null, marca_id: '', marca_label: '', nombre: '', descripcion: '' });
    load();
  }

  return (
    <article className="panel">
      <h2>Modelos</h2>
      <form className="filters" onSubmit={(event) => { event.preventDefault(); setPage(1); setApplied(search); }}>
        <SearchSelect
          label="Marca"
          value={marcaId}
          selectedLabel={marcaLabel}
          emptyLabel="Todas"
          placeholder="Buscar marca"
          onChange={(id, item) => { setPage(1); setMarcaId(id); setMarcaLabel(item?.nombre ?? ''); }}
          fetchPage={(params) => listMarcas({ ...params, sort: 'nombre', order: 'asc' }).then(asPage)}
          getLabel={(item) => item.nombre}
        />
        <label>Buscar modelo<input value={search} onChange={(event) => setSearch(event.target.value)} /></label>
        <button className="button button-primary" type="submit">Buscar</button>
      </form>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <div className="table-wrap">
        <table className="data-table">
          <thead><tr><th>Marca</th><th>Nombre</th><th>Estado</th>{operate ? <th>Acciones</th> : null}</tr></thead>
          <tbody>
            {rows.length === 0 ? <tr><td colSpan={operate ? 4 : 3}>No hay modelos.</td></tr> : rows.map((row) => (
              <tr key={row.id}>
                <td data-label="Marca">{row.marca}</td>
                <td data-label="Nombre">{row.nombre}</td>
                <td data-label="Estado">{row.activo ? 'Activo' : 'Inactivo'}</td>
                {operate ? (
                  <td data-label="Acciones">
                    <button type="button" className="button button-quiet" onClick={() => setForm({ id: row.id, marca_id: String(row.marca_id), marca_label: row.marca, nombre: row.nombre, descripcion: row.descripcion ?? '' })}>Editar</button>
                    <button type="button" className="button button-quiet" onClick={() => changeModeloActivo(row.id, !row.activo).then(() => load())}>{row.activo ? 'Desactivar' : 'Activar'}</button>
                  </td>
                ) : null}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Pager page={page} limit={10} total={total} onPage={setPage} />
      {operate ? (
        <form className="stack-form" onSubmit={onSubmit}>
          <h3>{form.id ? 'Editar modelo' : 'Nuevo modelo'}</h3>
          <SearchSelect
            label="Marca"
            value={form.marca_id}
            selectedLabel={form.marca_label}
            required
            allowEmpty={false}
            placeholder="Buscar marca activa"
            onChange={(id, item) => setForm({ ...form, marca_id: id, marca_label: item?.nombre ?? '' })}
            fetchPage={(params) => listMarcas({ ...params, activo: 1, sort: 'nombre', order: 'asc' }).then(asPage)}
            getLabel={(item) => item.nombre}
          />
          <label>Nombre<input required value={form.nombre} onChange={(event) => setForm({ ...form, nombre: event.target.value })} /></label>
          <label>Descripción<input value={form.descripcion} onChange={(event) => setForm({ ...form, descripcion: event.target.value })} /></label>
          <div className="actions">
            <button className="button button-primary" type="submit">{form.id ? 'Guardar modelo' : 'Crear modelo'}</button>
            {form.id ? <button type="button" className="button button-quiet" onClick={() => setForm({ id: null, marca_id: '', marca_label: '', nombre: '', descripcion: '' })}>Cancelar</button> : null}
          </div>
        </form>
      ) : null}
    </article>
  );
}

function Medidas({ operate }) {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [applied, setApplied] = useState('');
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [form, setForm] = useState({ id: null, descripcion: '', ancho: '', perfil: '', construccion: '', diametro: '' });
  const [error, setError] = useState('');

  async function load(nextPage = page, nextSearch = applied) {
    const result = await listMedidas({ page: nextPage, limit: 10, search: nextSearch, sort: 'descripcion', order: 'asc' });
    if (!result.ok) {
      setError(errorMessage(result));
      return;
    }
    setRows(result.payload?.data ?? []);
    setTotal(result.payload?.total ?? 0);
    setError('');
  }

  useEffect(() => { load(page, applied); }, [page, applied]);

  async function onSubmit(event) {
    event.preventDefault();
    const payload = {
      descripcion: form.descripcion,
      ancho: form.ancho === '' ? null : form.ancho,
      perfil: form.perfil === '' ? null : form.perfil,
      construccion: form.construccion || null,
      diametro: form.diametro === '' ? null : form.diametro,
    };
    const result = form.id ? await updateMedida(form.id, payload) : await createMedida(payload);
    if (!result.ok) {
      setError(firstError(result));
      return;
    }
    setForm({ id: null, descripcion: '', ancho: '', perfil: '', construccion: '', diametro: '' });
    load();
  }

  return (
    <article className="panel">
      <h2>Medidas</h2>
      <form className="filters" onSubmit={(event) => { event.preventDefault(); setPage(1); setApplied(search); }}>
        <label>Buscar medida<input value={search} onChange={(event) => setSearch(event.target.value)} /></label>
        <button className="button button-primary" type="submit">Buscar</button>
      </form>
      {error ? <p className="form-error" role="alert">{error}</p> : null}
      <div className="table-wrap">
        <table className="data-table">
          <thead><tr><th>Descripción</th><th>Detalle</th><th>Estado</th>{operate ? <th>Acciones</th> : null}</tr></thead>
          <tbody>
            {rows.length === 0 ? <tr><td colSpan={operate ? 4 : 3}>No hay medidas.</td></tr> : rows.map((row) => (
              <tr key={row.id}>
                <td data-label="Descripción">{row.descripcion}</td>
                <td data-label="Detalle">{[row.ancho, row.perfil, row.construccion, row.diametro].filter((value) => value !== null && value !== '').join(' · ') || 'Sin desglose'}</td>
                <td data-label="Estado">{row.activo ? 'Activa' : 'Inactiva'}</td>
                {operate ? (
                  <td data-label="Acciones">
                    <button type="button" className="button button-quiet" onClick={() => setForm({
                      id: row.id,
                      descripcion: row.descripcion,
                      ancho: row.ancho ?? '',
                      perfil: row.perfil ?? '',
                      construccion: row.construccion ?? '',
                      diametro: row.diametro ?? '',
                    })}>Editar</button>
                    <button type="button" className="button button-quiet" onClick={() => changeMedidaActivo(row.id, !row.activo).then(() => load())}>{row.activo ? 'Desactivar' : 'Activar'}</button>
                  </td>
                ) : null}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Pager page={page} limit={10} total={total} onPage={setPage} />
      {operate ? (
        <form className="stack-form" onSubmit={onSubmit}>
          <h3>{form.id ? 'Editar medida' : 'Nueva medida'}</h3>
          <label>Descripción<input required value={form.descripcion} placeholder="295/80 R22.5" onChange={(event) => setForm({ ...form, descripcion: event.target.value })} /></label>
          <div className="split">
            <label>Ancho<input inputMode="decimal" value={form.ancho} onChange={(event) => setForm({ ...form, ancho: event.target.value })} /></label>
            <label>Perfil<input inputMode="decimal" value={form.perfil} onChange={(event) => setForm({ ...form, perfil: event.target.value })} /></label>
          </div>
          <div className="split">
            <label>Construcción<input value={form.construccion} onChange={(event) => setForm({ ...form, construccion: event.target.value })} /></label>
            <label>Diámetro<input inputMode="decimal" value={form.diametro} onChange={(event) => setForm({ ...form, diametro: event.target.value })} /></label>
          </div>
          <div className="actions">
            <button className="button button-primary" type="submit">{form.id ? 'Guardar medida' : 'Crear medida'}</button>
            {form.id ? <button type="button" className="button button-quiet" onClick={() => setForm({ id: null, descripcion: '', ancho: '', perfil: '', construccion: '', diametro: '' })}>Cancelar</button> : null}
          </div>
        </form>
      ) : null}
    </article>
  );
}

function firstError(result) {
  const details = errorDetails(result);
  const first = details ? Object.values(details).flat()[0] : '';
  return first || errorMessage(result);
}
