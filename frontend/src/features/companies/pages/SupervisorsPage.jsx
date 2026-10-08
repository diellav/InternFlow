import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { AccountBadge, PageHeading } from '../../users/components/UserUi'
import { listSupervisors, supervisorErrorMessage } from '../api/supervisorsApi'
import { VerificationBadge } from '../components/VerificationBadge'
import { verificationLabels } from '../constants/verificationLabels'

const initialFilters = { search: '', verification_status: '', company_id: '', page: 1, per_page: 15 }

export function SupervisorsPage() {
  const [filters, setFilters] = useState(initialFilters)
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [revision, setRevision] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => { if (!controller.signal.aborted) { setLoading(true); setError('') } })
    const timer = setTimeout(async () => {
      try {
        const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
        const response = await listSupervisors(params, controller.signal)
        if (!controller.signal.aborted) {
          if (!response.data.length && response.meta.current_page > response.meta.last_page) setFilters((current) => ({ ...current, page: response.meta.last_page }))
          else setResult(response)
        }
      } catch (requestError) {
        if (!controller.signal.aborted) setError(supervisorErrorMessage(requestError))
      } finally { if (!controller.signal.aborted) setLoading(false) }
    }, 250)
    return () => { clearTimeout(timer); controller.abort() }
  }, [filters, revision])

  function changeFilter(event) {
    const { name, value } = event.target
    setFilters((current) => ({ ...current, [name]: value, page: 1 }))
  }

  return <>
    <PageHeading title="Mbikëqyrësit" description="Shqyrtoni mbikëqyrësit e kompanive dhe statuset e tyre të veçanta." />
    <section className="admin-panel" aria-label="Lista e mbikëqyrësve">
      <div className="admin-filters"><div className="form-field search-field"><label htmlFor="supervisor-search">Kërko mbikëqyrës</label><input id="supervisor-search" name="search" placeholder="Emër, mbiemër ose email…" maxLength={255} value={filters.search} onChange={changeFilter} /></div><div className="form-field"><label htmlFor="supervisor-verification">Verifikimi i mbikëqyrësit</label><select id="supervisor-verification" name="verification_status" value={filters.verification_status} onChange={changeFilter}><option value="">Të gjitha statuset</option>{Object.entries(verificationLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div><div className="form-field"><label htmlFor="supervisor-company">ID e kompanisë (opsionale)</label><input id="supervisor-company" name="company_id" type="number" min="1" step="1" value={filters.company_id} onChange={changeFilter} /></div></div>
      {error ? <div className="admin-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRevision((value) => value + 1)}>Provo përsëri</button><button className="admin-button" onClick={() => setFilters(initialFilters)}>Pastro filtrat</button></div> : loading ? <div className="admin-empty" role="status">Duke ngarkuar mbikëqyrësit…</div> : !result?.data.length ? <div className="admin-empty"><h2>Nuk u gjet asnjë mbikëqyrës</h2><button className="admin-button" onClick={() => setFilters(initialFilters)}>Pastro filtrat</button></div> : <div className="admin-table-scroll"><table className="admin-table company-table"><caption className="sr-only">Mbikëqyrësit e kompanive</caption><thead><tr><th scope="col">Mbikëqyrësi</th><th scope="col">Kompania</th><th scope="col">Verifikimi i mbikëqyrësit</th><th scope="col">Verifikimi i kompanisë</th><th scope="col">Llogaria</th><th scope="col">Veprimet</th></tr></thead><tbody>{result.data.map((user) => <tr key={user.id}><td><div className="table-person"><div><strong>{user.first_name} {user.last_name}</strong><span>{user.email}</span></div></div></td><td>{user.profile?.company?.name ?? 'Profili mungon'}</td><td>{user.profile ? <VerificationBadge status={user.profile.verification_status} /> : 'Profili mungon'}</td><td>{user.profile?.company && <VerificationBadge status={user.profile.company.verification_status} />}</td><td><AccountBadge active={user.is_active} /></td><td><div className="row-actions"><Link to={`/admin/supervisors/${user.id}`}>Detajet</Link></div></td></tr>)}</tbody></table></div>}
      {!loading && !error && result && <div className="admin-pagination"><span>{result.meta.total} mbikëqyrës · Faqja {result.meta.current_page} nga {result.meta.last_page}</span><div><label htmlFor="supervisor-page-size" className="sr-only">Mbikëqyrës për faqe</label><select id="supervisor-page-size" name="per_page" value={filters.per_page} onChange={changeFilter}><option value="15">15 / faqe</option><option value="30">30 / faqe</option><option value="50">50 / faqe</option></select><button className="admin-button" disabled={filters.page <= 1} onClick={() => setFilters((current) => ({ ...current, page: current.page - 1 }))}>Para</button><button className="admin-button" disabled={filters.page >= result.meta.last_page} onClick={() => setFilters((current) => ({ ...current, page: current.page + 1 }))}>Pas</button></div></div>}
    </section>
  </>
}
