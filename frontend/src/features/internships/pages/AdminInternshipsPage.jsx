import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { PageHeading } from '../../users/components/UserUi'
import { adminInternshipError, getAdminInternship, listAdminInternships } from '../api/adminInternshipsApi'
import { internshipStatusLabels } from '../constants/internshipStatusLabels'
import '../styles/adminInternships.css'

const name = person => person ? `${person.first_name} ${person.last_name}` : 'Pa caktuar'
const date = value => value ? new Date(value).toLocaleDateString('sq-AL') : 'Nuk është dhënë'

export function AdminInternshipsPage() {
  const [filters, setFilters] = useState({ search: '', status: '', page: 1, per_page: 15 })
  const [query, setQuery] = useState('')
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [retry, setRetry] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => { if (!controller.signal.aborted) { setLoading(true); setError('') } })
    const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
    listAdminInternships(params, controller.signal).then(data => {
      if (!controller.signal.aborted) setResult(data)
    }).catch(failure => { if (!controller.signal.aborted) setError(adminInternshipError(failure)) })
      .finally(() => { if (!controller.signal.aborted) setLoading(false) })
    return () => controller.abort()
  }, [filters, retry])
  return <>
    <PageHeading title="Praktikat" description="Përmbledhje administrative, vetëm për lexim. Procesi akademik menaxhohet nga koordinatori dhe mbikëqyrësi." />
    <section className="admin-panel admin-internships" aria-label="Lista e praktikave">
      <form className="admin-filters" onSubmit={event => { event.preventDefault(); setFilters(current => ({ ...current, search: query.trim(), page: 1 })) }}>
        <div className="form-field search-field"><label htmlFor="admin-internship-search">Kërko student ose kompani</label><input id="admin-internship-search" value={query} maxLength={255} onChange={event => setQuery(event.target.value)} /></div>
        <div className="form-field"><label htmlFor="admin-internship-status">Statusi i praktikës</label><select id="admin-internship-status" value={filters.status} onChange={event => setFilters(current => ({ ...current, status: event.target.value, page: 1 }))}><option value="">Të gjitha statuset</option>{Object.entries(internshipStatusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div>
        <button className="admin-button" type="submit">Kërko</button>
      </form>
      {error ? <div className="admin-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRetry(value => value + 1)}>Provo përsëri</button></div> : loading ? <p className="admin-empty" role="status">Duke ngarkuar praktikat…</p> : !result?.data.length ? <p className="admin-empty">Nuk u gjet asnjë praktikë.</p> : <div className="admin-table-scroll"><table className="admin-table"><caption className="sr-only">Praktikat e studentëve</caption><thead><tr>{['Studenti', 'Kompania', 'Statusi', 'Koordinatori', 'Mbikëqyrësi', 'Aplikimi / Dorëzimi', 'Veprimet'].map(label => <th scope="col" key={label}>{label}</th>)}</tr></thead><tbody>{result.data.map(item => <tr key={item.id}><td>{name(item.student)}</td><td>{item.company?.name ?? 'Nuk është dhënë'}</td><td>{internshipStatusLabels[item.status] ?? item.status}</td><td>{name(item.coordinator)}</td><td>{name(item.supervisor)}</td><td>{date(item.submitted_at ?? item.created_at)}<small>{item.submitted_at ? 'Dorëzuar' : 'Krijuar'}</small></td><td><Link to={`/admin/internships/${item.id}`}>Detajet</Link></td></tr>)}</tbody></table></div>}
      {!loading && !error && result && <div className="admin-pagination"><span>{result.meta.total} praktika · Faqja {result.meta.current_page} nga {result.meta.last_page}</span><div><button className="admin-button" disabled={filters.page <= 1} onClick={() => setFilters(current => ({ ...current, page: current.page - 1 }))}>Para</button><button className="admin-button" disabled={filters.page >= result.meta.last_page} onClick={() => setFilters(current => ({ ...current, page: current.page + 1 }))}>Pas</button></div></div>}
    </section>
  </>
}

export function AdminInternshipDetailsPage() {
  const { id } = useParams()
  const [item, setItem] = useState(null)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => { if (!controller.signal.aborted) { setItem(null); setError('') } })
    getAdminInternship(id, controller.signal).then(data => { if (!controller.signal.aborted) setItem(data) })
      .catch(failure => { if (!controller.signal.aborted) setError(adminInternshipError(failure)) })
    return () => controller.abort()
  }, [id, retry])
  return <>
    <Link className="admin-back-link" to="/admin/internships">← Të gjitha praktikat</Link>
    <PageHeading title={item?.position_title ?? 'Detajet e praktikës'} description="Vetëm lexim" />
    {error ? <div className="admin-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRetry(value => value + 1)}>Provo përsëri</button></div> : !item ? <p role="status">Duke ngarkuar praktikën…</p> : <section className="admin-panel detail-panel admin-internships"><dl className="admin-detail-list">
      {[['Studenti', name(item.student)], ['Kompania', item.company?.name], ['Statusi', internshipStatusLabels[item.status] ?? item.status], ['Koordinatori', name(item.coordinator)], ['Mbikëqyrësi', name(item.supervisor)], ['Përshkrimi', item.description], ['Data e krijimit', date(item.created_at)], ['Data e dorëzimit', date(item.submitted_at)], ['Data e miratimit', date(item.approved_at)], ['Data e fillimit', date(item.start_date)], ['Data e mbarimit', date(item.end_date)], ['Data e përfundimit', date(item.completed_at)]].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value ?? 'Nuk është dhënë'}</dd></div>)}
    </dl></section>}
  </>
}
