import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/hooks/useAuth'
import { errorMessage, listUsers } from '../api/usersApi'
import { ActivationDialog } from '../components/ActivationDialog'
import { AccountBadge, PageHeading } from '../components/UserUi'
import { roleLabels } from '../constants/userLabels'

export function UsersPage() {
  const { user: currentUser } = useAuth()
  const [filters, setFilters] = useState({ search: '', role: '', is_active: '', page: 1, per_page: 15 })
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [target, setTarget] = useState(null)
  const [revision, setRevision] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      if (!controller.signal.aborted) { setLoading(true); setError('') }
    })
    const timer = setTimeout(async () => {
      try {
        const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
        const response = await listUsers(params, controller.signal)
        if (!controller.signal.aborted) {
          if (response.data.length === 0 && response.meta.current_page > response.meta.last_page) {
            setFilters((current) => ({ ...current, page: response.meta.last_page }))
          } else setResult(response)
        }
      } catch (requestError) {
        if (!controller.signal.aborted) setError(errorMessage(requestError))
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
    <PageHeading title="Përdoruesit" description="Menaxhoni llogaritë dhe qasjen në InternFlow."><Link className="admin-button admin-primary" to="/admin/academic-coordinators/new">+ Krijo koordinator</Link></PageHeading>
    {notice && <p className="form-success" role="status">{notice}</p>}
    <section className="admin-panel" aria-label="Lista e përdoruesve">
      <div className="admin-filters"><div className="form-field search-field"><label htmlFor="user-search">Kërko përdorues</label><input id="user-search" name="search" placeholder="Emër, mbiemër ose email…" maxLength={255} value={filters.search} onChange={changeFilter} /></div><div className="form-field"><label htmlFor="role-filter">Roli</label><select id="role-filter" name="role" value={filters.role} onChange={changeFilter}><option value="">Të gjitha rolet</option>{Object.entries(roleLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div><div className="form-field"><label htmlFor="state-filter">Statusi</label><select id="state-filter" name="is_active" value={filters.is_active} onChange={changeFilter}><option value="">Të gjitha llogaritë</option><option value="true">Aktive</option><option value="false">Joaktive</option></select></div></div>
      {error ? <div className="admin-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRevision((current) => current + 1)}>Provo përsëri</button></div> : loading ? <div className="admin-empty" role="status">Duke ngarkuar përdoruesit…</div> : !result?.data.length ? <div className="admin-empty"><h2>Nuk u gjet asnjë përdorues</h2><p>Provoni një kërkim tjetër ose hiqni filtrat.</p><button className="admin-button" onClick={() => setFilters({ search: '', role: '', is_active: '', page: 1, per_page: 15 })}>Pastro filtrat</button></div> : <div className="admin-table-scroll"><table className="admin-table"><caption className="sr-only">Përdoruesit e sistemit</caption><thead><tr><th scope="col">Përdoruesi</th><th scope="col">Roli</th><th scope="col">Statusi</th><th scope="col" className="actions-cell">Veprimet</th></tr></thead><tbody>{result.data.map((user) => <tr key={user.id}><td><div className="table-person"><span className="user-avatar" aria-hidden="true">{user.first_name[0]}{user.last_name[0]}</span><div><strong>{user.first_name} {user.last_name}</strong><span>{user.email}</span></div></div></td><td>{roleLabels[user.role] ?? user.role}</td><td><AccountBadge active={user.is_active} /></td><td className="actions-cell"><div className="row-actions"><Link to={`/admin/users/${user.id}`}>Detajet</Link><button className="table-action" disabled={user.id === currentUser.id && user.is_active} title={user.id === currentUser.id ? 'Nuk mund të çaktivizoni llogarinë tuaj.' : undefined} onClick={() => { setNotice(''); setTarget(user) }}>{user.is_active ? 'Çaktivizo' : 'Aktivizo'}</button></div></td></tr>)}</tbody></table></div>}
      {!loading && !error && result && <div className="admin-pagination"><span>{result.meta.total} përdorues · Faqja {result.meta.current_page} nga {result.meta.last_page}</span><div><label htmlFor="page-size" className="sr-only">Përdorues për faqe</label><select id="page-size" name="per_page" value={filters.per_page} onChange={changeFilter}><option value="15">15 / faqe</option><option value="30">30 / faqe</option><option value="50">50 / faqe</option></select><button className="admin-button" disabled={filters.page <= 1} onClick={() => setFilters((current) => ({ ...current, page: current.page - 1 }))}>Para</button><button className="admin-button" disabled={filters.page >= result.meta.last_page} onClick={() => setFilters((current) => ({ ...current, page: current.page + 1 }))}>Pas</button></div></div>}
    </section>
    {target && <ActivationDialog user={target} onClose={() => setTarget(null)} onUpdated={() => { setNotice('Statusi i llogarisë u përditësua.'); setRevision((current) => current + 1) }} />}
  </>
}
