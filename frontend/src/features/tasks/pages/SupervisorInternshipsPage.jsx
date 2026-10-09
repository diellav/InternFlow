import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/hooks/useAuth'
import { PageHeading } from '../../users/components/UserUi'
import { InternshipStatusBadge } from '../../internships/components/InternshipStatusBadge'
import { internshipStatusLabels } from '../../internships/constants/internshipStatusLabels'
import { listSupervisorInternships, taskErrorMessage } from '../api/tasksApi'

export function SupervisorInternshipsPage() {
  const { refreshUser } = useAuth()
  const [filters, setFilters] = useState({ page: 1, per_page: 15, status: '', search: '' })
  const [search, setSearch] = useState('')
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')
  const [revision, setRevision] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(async () => {
      if (controller.signal.aborted) return
      setResult(null); setError('')
      try {
        const data = await listSupervisorInternships(Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')), controller.signal)
        if (!controller.signal.aborted) {
          if (data.meta.current_page > data.meta.last_page) setFilters((value) => ({ ...value, page: data.meta.last_page }))
          else setResult(data)
        }
      } catch (failure) {
        if (controller.signal.aborted) return
        setError(taskErrorMessage(failure))
        if ([401, 403].includes(failure.status)) await refreshUser()
      }
    })
    return () => controller.abort()
  }, [filters, revision, refreshUser])
  return <div className="task-feature"><PageHeading eyebrow="Portali i mbikëqyrësit" title="Praktikat e caktuara" description="Vetëm praktikat që ju janë caktuar drejtpërdrejt. Fillimi kërkon miratim akademik dhe periudhë të vlefshme." />
    <div className="task-filters"><form onSubmit={(event) => { event.preventDefault(); setFilters({ ...filters, search: search.trim(), page: 1 }) }}><label htmlFor="supervisor-search">Kërko student ose pozitë</label><input id="supervisor-search" value={search} maxLength={255} onChange={(event) => setSearch(event.target.value)} /><button className="admin-button">Kërko</button></form><div><label htmlFor="supervisor-status">Statusi i praktikës</label><select id="supervisor-status" value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value, page: 1 })}><option value="">Të gjitha statuset</option>{Object.entries(internshipStatusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div></div>
    {error ? <section className="internship-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRevision((value) => value + 1)}>Provo përsëri</button><Link to="/supervisor/verification">Shiko verifikimin</Link></section> : !result ? <p role="status">Duke ngarkuar praktikat…</p> : !result.data.length ? <section className="internship-empty"><h2>Nuk ka praktika të caktuara</h2><p>Kontrolloni filtrat ose prisni caktimin e një praktike.</p></section> : <div className="internship-cards">{result.data.map((item) => <article className="internship-card" key={item.id}><InternshipStatusBadge status={item.status} /><h2><Link to={`/supervisor/internships/${item.id}`}>{item.position_title}</Link></h2><p>{item.student.first_name} {item.student.last_name} · {item.company?.name}</p><p>{item.start_date} — {item.end_date}</p><Link className="internship-back" to={`/supervisor/internships/${item.id}`}>Shiko praktikën →</Link></article>)}</div>}
    {result && <div className="internship-pagination"><span>{result.meta.total} praktika · Faqja {result.meta.current_page} nga {result.meta.last_page}</span><div><button className="admin-button" disabled={filters.page <= 1} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>Para</button><button className="admin-button" disabled={filters.page >= result.meta.last_page} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>Pas</button></div></div>}
  </div>
}
