import { useEffect, useState } from 'react'
import { PageHeading } from '../../users/components/UserUi'
import { roleLabels } from '../../users/constants/userLabels'
import { listAuditLogs, auditLogsError } from '../api/auditLogsApi'
import { auditActionLabels, auditEntityLabels, auditDetails } from '../auditLabels'
import '../styles/audit.css'

const initialFilters = { search: '', action: '', entity_type: '', date_from: '', date_to: '', page: 1, per_page: 15 }

export function AdminAuditLogsPage() {
  const [filters, setFilters] = useState(initialFilters)
  const [search, setSearch] = useState('')
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => { if (!controller.signal.aborted) { setLoading(true); setError('') } })
    listAuditLogs(Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')), controller.signal)
      .then(data => { if (!controller.signal.aborted) setResult(data) })
      .catch(failure => { if (!controller.signal.aborted) setError(auditLogsError(failure)) })
      .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [filters, retry])
  const change = event => setFilters(current => ({ ...current, [event.target.name]: event.target.value, page: 1 }))
  return <>
    <PageHeading eyebrow="ADMINISTRIMI" title="Historiku i veprimeve" description="Gjurmueshmëria e veprimeve kryesore në sistem." />
    <section className="admin-panel admin-audit" aria-label="Historiku i veprimeve">
      <form className="admin-filters audit-filters" onSubmit={event => { event.preventDefault(); setFilters(current => ({ ...current, search: search.trim(), page: 1 })) }}>
        <div className="form-field search-field"><label htmlFor="audit-search">Kërko përdorues ose veprim</label><input id="audit-search" value={search} maxLength={255} onChange={event => setSearch(event.target.value)} /></div>
        <div className="form-field"><label htmlFor="audit-action">Veprimi</label><select id="audit-action" name="action" value={filters.action} onChange={change}><option value="">Të gjitha veprimet</option>{Object.entries(auditActionLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div>
        <div className="form-field"><label htmlFor="audit-entity">Entiteti</label><select id="audit-entity" name="entity_type" value={filters.entity_type} onChange={change}><option value="">Të gjitha entitetet</option>{Object.entries(auditEntityLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div>
        <div className="form-field"><label htmlFor="audit-from">Data nga</label><input id="audit-from" name="date_from" type="date" value={filters.date_from} onChange={change} /></div>
        <div className="form-field"><label htmlFor="audit-to">Data deri</label><input id="audit-to" name="date_to" type="date" value={filters.date_to} onChange={change} /></div>
        <div className="audit-filter-actions"><button className="admin-button admin-primary" type="submit">Kërko</button><button className="admin-button" type="button" onClick={() => { setSearch(''); setFilters({ ...initialFilters }) }}>Pastro filtrat</button></div>
      </form>
      {loading ? <p className="admin-empty" role="status">Duke ngarkuar historikun…</p> : error ? <div className="admin-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRetry(value => value + 1)}>Provo përsëri</button></div> : !result?.data.length ? <p className="admin-empty">Nuk u gjet asnjë veprim.</p> : <div className="admin-table-scroll"><table className="admin-table"><caption className="sr-only">Historiku i veprimeve në sistem</caption><thead><tr>{['Data dhe ora', 'Përdoruesi', 'Roli', 'Veprimi', 'Entiteti', 'Detajet'].map(label => <th scope="col" key={label}>{label}</th>)}</tr></thead><tbody>{result.data.map(log => <tr key={log.id}>
        <td><time dateTime={log.created_at}>{log.created_at ? new Date(log.created_at).toLocaleString('sq-AL') : '—'}</time></td>
        <td>{log.actor ? <><strong>{log.actor.first_name} {log.actor.last_name}</strong><small>{log.actor.email}</small></> : 'Sistemi / përdorues i fshirë'}</td>
        <td>{roleLabels[log.actor?.role] ?? '—'}</td><td>{auditActionLabels[log.action] ?? 'Veprim i panjohur'}</td>
        <td>{auditEntityLabels[log.entity_type] ?? 'Entitet i panjohur'} #{log.entity_id}</td>
        <td>{auditDetails(log.details).length ? auditDetails(log.details).map(detail => <div key={detail}>{detail}</div>) : '—'}</td>
      </tr>)}</tbody></table></div>}
      {!loading && !error && result && <div className="admin-pagination"><span>{result.meta.total} veprime · Faqja {result.meta.current_page} nga {result.meta.last_page}</span><div><button className="admin-button" disabled={filters.page <= 1} onClick={() => setFilters(current => ({ ...current, page: current.page - 1 }))}>Para</button><button className="admin-button" disabled={filters.page >= result.meta.last_page} onClick={() => setFilters(current => ({ ...current, page: current.page + 1 }))}>Pas</button></div></div>}
    </section>
  </>
}
