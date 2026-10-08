import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/hooks/useAuth'
import { PageHeading } from '../../users/components/UserUi'
import { listInternships, internshipErrorMessage } from '../api/internshipsApi'
import { InternshipStatusBadge } from '../components/InternshipStatusBadge'
import { internshipStatusLabels } from '../constants/internshipStatusLabels'

export function StudentInternshipsPage() {
  const { refreshUser } = useAuth()
  const [filters, setFilters] = useState({ status: '', page: 1, per_page: 15 })
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
        const data = await listInternships(Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')), controller.signal)
        if (!controller.signal.aborted) {
          if (data.meta.current_page > data.meta.last_page) setFilters((current) => ({ ...current, page: data.meta.last_page }))
          else setResult(data)
        }
      } catch (failure) {
        if (controller.signal.aborted) return
        setError(internshipErrorMessage(failure))
        if ([401, 403].includes(failure.status)) await refreshUser()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    })
    return () => controller.abort()
  }, [filters, revision, refreshUser])

  return <>
    <PageHeading eyebrow="Praktika profesionale" title="Praktikat e mia" description="Regjistroni praktikën që keni organizuar jashtë platformës për shqyrtim akademik."><Link className="admin-button admin-primary" to="/student/internships/new">Krijo aplikim për praktikë</Link></PageHeading>
    <div className="internship-intro"><span className="internship-step">01</span><div><strong>Përgatitni aplikimin tuaj</strong><p>Ruajeni si draft dhe plotësojeni në ritmin tuaj. Kur të jetë gati, hapni detajet dhe dorëzojeni për shqyrtim akademik.</p></div></div>
    <section className="internship-list" aria-label="Aplikimet e mia">
      <div className="internship-list-toolbar"><h2>Aplikimet e regjistruara</h2><div className="form-field"><label htmlFor="internship-status-filter">Statusi i aplikimit</label><select id="internship-status-filter" value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value, page: 1 })}><option value="">Të gjitha statuset</option>{Object.entries(internshipStatusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div></div>
      {loading ? <div className="internship-empty" role="status">Duke ngarkuar aplikimet…</div> : error ? <div className="internship-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRevision((current) => current + 1)}>Provo përsëri</button></div> : !result?.data.length ? <div className="internship-empty"><span className="internship-empty-mark" aria-hidden="true">IF</span><h2>{filters.status ? 'Nuk ka aplikime me këtë status' : 'Praktika juaj fillon këtu'}</h2><p>{filters.status ? 'Provoni një status tjetër.' : 'Nuk keni regjistruar ende një praktikë. Krijoni aplikimin e parë dhe ruajeni si draft.'}</p>{filters.status ? <button className="admin-button" onClick={() => setFilters({ ...filters, status: '', page: 1 })}>Pastro filtrin</button> : <Link className="admin-button admin-primary" to="/student/internships/new">Krijo draftin e parë</Link>}</div> : <div className="internship-cards">{result.data.map((internship) => <article className="internship-card" key={internship.id}>
        <div className="internship-card-top"><span className="internship-record-number">APLIKIMI #{internship.id}</span><InternshipStatusBadge status={internship.status} /></div>
        <h2><Link to={`/student/internships/${internship.id}`}>{internship.position_title}</Link></h2><p className="internship-company-name">{internship.company?.name ?? 'Kompania nuk është e disponueshme'}</p>
        <dl className="internship-card-dates"><div><dt>Fillimi</dt><dd>{internship.start_date}</dd></div><div><dt>Përfundimi</dt><dd>{internship.end_date}</dd></div></dl>
        <div className="internship-card-actions"><Link to={`/student/internships/${internship.id}`}>Shiko detajet →</Link>{['DRAFT', 'REVISION_REQUIRED'].includes(internship.status) && <Link to={`/student/internships/${internship.id}/edit`}>{internship.status === 'REVISION_REQUIRED' ? 'Ndrysho aplikimin' : 'Ndrysho draftin'}</Link>}</div>
      </article>)}</div>}
      {!loading && !error && result && <div className="internship-pagination"><span>{result.meta.total} aplikime · Faqja {result.meta.current_page} nga {result.meta.last_page}</span><div><label className="sr-only" htmlFor="internship-page-size">Aplikime për faqe</label><select id="internship-page-size" value={filters.per_page} onChange={(event) => setFilters({ ...filters, per_page: Number(event.target.value), page: 1 })}><option value="15">15 / faqe</option><option value="30">30 / faqe</option><option value="50">50 / faqe</option></select><button className="admin-button" disabled={filters.page <= 1} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>Para</button><button className="admin-button" disabled={filters.page >= result.meta.last_page} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>Pas</button></div></div>}
    </section>
  </>
}
