import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/hooks/useAuth'
import { PageHeading } from '../../users/components/UserUi'
import { coordinatorErrorMessage, listCoordinatorInternships } from '../api/internshipsApi'
import { InternshipStatusBadge } from '../components/InternshipStatusBadge'
import { internshipStatusLabels } from '../constants/internshipStatusLabels'

export function CoordinatorInternshipsPage() {
  const { refreshUser } = useAuth()
  const [filters, setFilters] = useState({ search: '', status: '', page: 1, per_page: 15 })
  const [search, setSearch] = useState('')
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [revision, setRevision] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(async () => {
      if (controller.signal.aborted) return
      setLoading(true); setError('')
      try {
        const data = await listCoordinatorInternships(Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')), controller.signal)
        if (!controller.signal.aborted) {
          if (data.meta.current_page > data.meta.last_page) setFilters((current) => ({ ...current, page: data.meta.last_page }))
          else setResult(data)
        }
      } catch (failure) {
        if (controller.signal.aborted) return
        setError(coordinatorErrorMessage(failure))
        if ([401, 403].includes(failure.status)) await refreshUser()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    })
    return () => controller.abort()
  }, [filters, revision, refreshUser])
  return <><PageHeading eyebrow="Portali akademik" title="Aplikimet për praktikë" description="Aplikimet e dorëzuara pa koordinator dhe aplikimet që ju janë caktuar." /><div className="internship-intro"><span className="internship-step">02</span><div><strong>Kuti e përbashkët, përgjegjësi e qartë</strong><p>Hapni një aplikim të pacaktuar dhe merreni për shqyrtim. Pasi merret, vetëm koordinatori i caktuar ka qasje në të. Draftet nuk shfaqen këtu.</p></div></div>
    <section className="internship-list" aria-label="Kutia hyrëse e aplikimeve"><div className="coordinator-filters"><form onSubmit={(event) => { event.preventDefault(); setFilters({ ...filters, search: search.trim(), page: 1 }) }}><div className="form-field"><label htmlFor="coordinator-search">Kërko aplikime</label><input id="coordinator-search" value={search} maxLength={255} placeholder="Studenti, kompania ose pozita" onChange={(event) => setSearch(event.target.value)} /></div><button className="admin-button" type="submit">Kërko</button></form><div className="form-field"><label htmlFor="coordinator-status">Statusi</label><select id="coordinator-status" value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value, page: 1 })}><option value="">Të gjitha statuset</option>{Object.entries(internshipStatusLabels).filter(([value]) => value !== 'DRAFT').map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div><button className="admin-button" onClick={() => { setSearch(''); setFilters({ search: '', status: '', page: 1, per_page: filters.per_page }) }}>Pastro filtrat</button></div>
      {loading ? <p className="internship-empty" role="status">Duke ngarkuar aplikimet…</p> : error ? <div className="internship-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRevision((value) => value + 1)}>Provo përsëri</button></div> : !result?.data.length ? <div className="internship-empty"><h2>Nuk ka aplikime të disponueshme</h2><p>Aplikimet e reja të dorëzuara ose ato të caktuara për ju do të shfaqen këtu. Kontrolloni filtrat ose rifreskoni kutinë hyrëse.</p><button className="admin-button" onClick={() => setRevision((value) => value + 1)}>Rifresko</button></div> : <div className="internship-cards">{result.data.map((item) => <article className="internship-card" key={item.id}><div className="internship-card-top"><span className="internship-record-number">APLIKIMI #{item.id}</span><InternshipStatusBadge status={item.status} /></div><h2><Link to={`/coordinator/internships/${item.id}`}>{item.position_title}</Link></h2><p className="internship-company-name">{item.student.first_name} {item.student.last_name} · {item.company?.name}</p><p className="coordinator-assignment">{item.coordinator_id === null ? 'I pacaktuar · I disponueshëm për marrje' : 'I caktuar për ju'}</p><p className="admin-muted">Dorëzuar më: {item.submitted_at ? new Date(item.submitted_at).toLocaleString('sq-AL') : 'Nuk është dhënë'}</p><div className="internship-card-actions"><Link to={`/coordinator/internships/${item.id}`}>Shiko aplikimin →</Link></div></article>)}</div>}
      {!loading && !error && result && <div className="internship-pagination"><span>{result.meta.total} aplikime · Faqja {result.meta.current_page} nga {result.meta.last_page}</span><div><label className="sr-only" htmlFor="coordinator-page-size">Aplikime për faqe</label><select id="coordinator-page-size" value={filters.per_page} onChange={(event) => setFilters({ ...filters, per_page: Number(event.target.value), page: 1 })}><option value="15">15 / faqe</option><option value="30">30 / faqe</option><option value="50">50 / faqe</option></select><button className="admin-button" disabled={filters.page <= 1} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>Para</button><button className="admin-button" disabled={filters.page >= result.meta.last_page} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>Pas</button></div></div>}
    </section></>
}
