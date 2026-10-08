import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { PageHeading } from '../../users/components/UserUi'
import { companyErrorMessage, listCompanies } from '../api/companiesApi'
import { VerificationBadge } from '../components/VerificationBadge'
import { verificationLabels } from '../constants/verificationLabels'

const initialFilters = { search: '', verification_status: '', page: 1, per_page: 15 }

export function CompaniesPage() {
  const [filters, setFilters] = useState(initialFilters)
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [revision, setRevision] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      if (!controller.signal.aborted) { setLoading(true); setError('') }
    })
    const timer = setTimeout(async () => {
      try {
        const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
        const response = await listCompanies(params, controller.signal)
        if (!controller.signal.aborted) {
          if (!response.data.length && response.meta.current_page > response.meta.last_page) {
            setFilters((current) => ({ ...current, page: response.meta.last_page }))
          } else setResult(response)
        }
      } catch (requestError) {
        if (!controller.signal.aborted) setError(companyErrorMessage(requestError))
      } finally {
        if (!controller.signal.aborted) setLoading(false)
      }
    }, 250)
    return () => { clearTimeout(timer); controller.abort() }
  }, [filters, revision])

  function changeFilter(event) {
    const { name, value } = event.target
    setFilters((current) => ({ ...current, [name]: value, page: 1 }))
  }

  return <>
    <PageHeading title="Kompanitë" description="Shikoni kompanitë e regjistruara dhe statusin e verifikimit." />
    <section className="admin-panel" aria-label="Lista e kompanive">
      <div className="admin-filters company-filters">
        <div className="form-field search-field"><label htmlFor="company-search">Kërko kompani</label><input id="company-search" name="search" placeholder="Emri i kompanisë…" maxLength={255} value={filters.search} onChange={changeFilter} /></div>
        <div className="form-field"><label htmlFor="company-verification">Verifikimi</label><select id="company-verification" name="verification_status" value={filters.verification_status} onChange={changeFilter}><option value="">Të gjitha statuset</option>{Object.entries(verificationLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div>
      </div>
      {error ? <div className="admin-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRevision((current) => current + 1)}>Provo përsëri</button></div> : loading ? <div className="admin-empty" role="status">Duke ngarkuar kompanitë…</div> : !result?.data.length ? <div className="admin-empty"><h2>Nuk u gjet asnjë kompani</h2><p>Provoni një kërkim tjetër ose hiqni filtrat.</p><button className="admin-button" onClick={() => setFilters(initialFilters)}>Pastro filtrat</button></div> : <div className="admin-table-scroll"><table className="admin-table company-table"><caption className="sr-only">Kompanitë e regjistruara</caption><thead><tr><th scope="col">Kompania</th><th scope="col">Kontakti</th><th scope="col">Verifikimi</th><th scope="col" className="actions-cell">Veprimet</th></tr></thead><tbody>{result.data.map((company) => <tr key={company.id}><td><div className="table-person"><div><strong>{company.name}</strong><span>{company.industry ?? 'Industria nuk është dhënë'}</span></div></div></td><td>{company.email ?? company.phone ?? 'Nuk është dhënë'}</td><td><VerificationBadge status={company.verification_status} /></td><td className="actions-cell"><div className="row-actions"><Link to={`/admin/companies/${company.id}`}>Detajet</Link></div></td></tr>)}</tbody></table></div>}
      {!loading && !error && result && <div className="admin-pagination"><span>{result.meta.total} kompani · Faqja {result.meta.current_page} nga {result.meta.last_page}</span><div><label htmlFor="company-page-size" className="sr-only">Kompani për faqe</label><select id="company-page-size" name="per_page" value={filters.per_page} onChange={changeFilter}><option value="15">15 / faqe</option><option value="30">30 / faqe</option><option value="50">50 / faqe</option></select><button className="admin-button" disabled={filters.page <= 1} onClick={() => setFilters((current) => ({ ...current, page: current.page - 1 }))}>Para</button><button className="admin-button" disabled={filters.page >= result.meta.last_page} onClick={() => setFilters((current) => ({ ...current, page: current.page + 1 }))}>Pas</button></div></div>}
    </section>
  </>
}
