import { useEffect, useId, useRef, useState } from 'react';

const PAGE = 20;

export function SearchSelect({
  label,
  value,
  selectedLabel = '',
  onChange,
  fetchPage,
  getLabel,
  placeholder = 'Buscar',
  emptyLabel = 'Seleccione',
  allowEmpty = true,
  disabled = false,
  required = false,
  scopeKey = '',
}) {
  const listId = useId();
  const fetchRef = useRef(fetchPage);
  const request = useRef(0);
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [rows, setRows] = useState([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState('');

  fetchRef.current = fetchPage;

  useEffect(() => {
    setOpen(false);
    setQuery('');
    setRows([]);
    setTotal(0);
    setPage(1);
  }, [scopeKey]);

  useEffect(() => {
    if (!open) return undefined;
    setLoading(true);
    const token = ++request.current;
    const handle = setTimeout(async () => {
      const result = await fetchRef.current({ search: query, page: 1, limit: PAGE });
      if (token !== request.current) return;
      setLoading(false);
      if (!result.ok) {
        setRows([]);
        setTotal(0);
        setNotice(result.message || 'No se pudo consultar.');
        return;
      }
      setNotice('');
      setRows(result.rows);
      setTotal(result.total);
      setPage(1);
    }, 250);
    return () => {
      clearTimeout(handle);
      request.current += 1;
    };
  }, [open, query, scopeKey]);

  async function loadMore() {
    const token = ++request.current;
    const nextPage = page + 1;
    setLoading(true);
    const result = await fetchRef.current({ search: query, page: nextPage, limit: PAGE });
    if (token !== request.current) return;
    setLoading(false);
    if (!result.ok) {
      setNotice(result.message || 'No se pudo consultar.');
      return;
    }
    setRows((current) => [...current, ...result.rows]);
    setTotal(result.total);
    setPage(nextPage);
  }

  function choose(id, item) {
    onChange(id, item);
    setOpen(false);
    setQuery('');
  }

  return (
    <label className="search-select">
      {label}
      <input
        role="combobox"
        aria-expanded={open}
        aria-controls={listId}
        aria-autocomplete="list"
        disabled={disabled}
        required={required && !value}
        placeholder={placeholder}
        value={open ? query : selectedLabel}
        onFocus={() => {
          if (!disabled) setOpen(true);
        }}
        onChange={(event) => {
          setOpen(true);
          setQuery(event.target.value);
        }}
        onBlur={() => setOpen(false)}
      />
      {open ? (
        <ul id={listId} className="search-select-menu" role="listbox">
          {allowEmpty ? (
            <li>
              <button type="button" onMouseDown={(event) => event.preventDefault()} onClick={() => choose('', null)}>
                {emptyLabel}
              </button>
            </li>
          ) : null}
          {rows.map((row) => (
            <li key={row.id}>
              <button type="button" onMouseDown={(event) => event.preventDefault()} onClick={() => choose(String(row.id), row)}>
                {getLabel(row)}
              </button>
            </li>
          ))}
          {rows.length === 0 && !loading ? <li className="search-select-empty">{notice || 'Sin resultados'}</li> : null}
          {rows.length < total ? (
            <li>
              <button type="button" onMouseDown={(event) => event.preventDefault()} onClick={loadMore}>
                {loading ? 'Cargando…' : 'Cargar más'}
              </button>
            </li>
          ) : null}
        </ul>
      ) : null}
    </label>
  );
}
