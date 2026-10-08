import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { AccountBadge, PageHeading } from '../../users/components/UserUi'
import { companyErrorMessage, getCompany } from '../api/companiesApi'
import { VerificationBadge } from '../components/VerificationBadge'
import { VerificationDialog } from '../components/VerificationDialog'

function Detail({ label, children }) {
  return <div><dt>{label}</dt><dd>{children ?? 'Nuk është dhënë'}</dd></div>
}

function dateLabel(value) {
  return value ? new Intl.DateTimeFormat('sq-AL', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : null
}

export function CompanyDetailsPage() {
  const { id } = useParams()
  const [company, setCompany] = useState(null)
  const [error, setError] = useState('')
  const [revision, setRevision] = useState(0)
  const [decision, setDecision] = useState(null)
  const [feedback, setFeedback] = useState(null)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(async () => {
      if (controller.signal.aborted) return
      setCompany(null)
      setError('')
      try {
        const response = await getCompany(id, controller.signal)
        if (!controller.signal.aborted) setCompany(response)
      } catch (requestError) {
        if (!controller.signal.aborted) setError(companyErrorMessage(requestError))
      }
    })
    return () => controller.abort()
  }, [id, revision])

  const message = feedback && <p className={feedback.conflict ? 'form-alert' : 'form-success'} role={feedback.conflict ? 'alert' : 'status'}>{feedback.text}</p>
  if (error) return <>{message}<div className="admin-panel admin-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRevision((value) => value + 1)}>Provo përsëri</button><Link to="/admin/companies">Kthehu te kompanitë</Link></div></>
  if (!company) return <>{message}<p role="status">Duke ngarkuar kompaninë…</p></>

  return <>
    <Link className="admin-back-link" to="/admin/companies">← Të gjitha kompanitë</Link>
    <PageHeading title={company.name} description="Të dhënat e kompanisë dhe mbikëqyrësit e lidhur."><VerificationBadge status={company.verification_status} />{company.verification_status === 'PENDING' && <><button className="admin-button admin-primary" disabled={decision !== null} onClick={() => { setFeedback(null); setDecision('APPROVED') }}>Mirato kompaninë</button><button className="admin-button admin-danger" disabled={decision !== null} onClick={() => { setFeedback(null); setDecision('REJECTED') }}>Refuzo kompaninë</button></>}</PageHeading>
    {message}
    <div className="admin-detail-grid">
      <section className="admin-panel detail-panel"><h2>Të dhënat e kompanisë</h2><dl className="admin-detail-list"><Detail label="Emri">{company.name}</Detail><Detail label="Industria">{company.industry}</Detail><Detail label="Adresa">{company.address}</Detail><Detail label="Email">{company.email}</Detail><Detail label="Telefoni">{company.phone}</Detail><Detail label="Faqja e internetit">{company.website}</Detail><Detail label="Statusi i kompanisë"><AccountBadge active={company.is_active} /></Detail><Detail label="Regjistruar më">{dateLabel(company.created_at)}</Detail></dl></section>
      <section className="admin-panel detail-panel"><h2>Verifikimi i kompanisë</h2><dl className="admin-detail-list"><Detail label="Verifikimi"><VerificationBadge status={company.verification_status} /></Detail><Detail label="Verifikuar më">{dateLabel(company.verified_at)}</Detail><Detail label="ID e verifikuesit">{company.verified_by}</Detail><Detail label="Shënimi i verifikimit"><span className="company-note">{company.verification_note ?? 'Nuk është dhënë'}</span></Detail></dl></section>
    </div>
    <section className="admin-panel company-supervisors" aria-labelledby="company-supervisors-heading"><div className="detail-panel"><h2 id="company-supervisors-heading">Mbikëqyrësit e kompanisë</h2><p className="admin-muted">Statusi i llogarisë dhe verifikimi i mbikëqyrësit janë të ndarë nga verifikimi i kompanisë.</p></div>{!company.supervisors.length ? <div className="admin-empty"><p>Nuk ka mbikëqyrës të lidhur me këtë kompani.</p></div> : <div className="admin-table-scroll"><table className="admin-table company-table"><caption className="sr-only">Mbikëqyrësit e lidhur</caption><thead><tr><th scope="col">Mbikëqyrësi</th><th scope="col">Pozita</th><th scope="col">Llogaria</th><th scope="col">Verifikimi</th><th scope="col" className="actions-cell">Veprimet</th></tr></thead><tbody>{company.supervisors.map((supervisor) => <tr key={supervisor.user_id}><td><div className="table-person"><div><strong>{supervisor.first_name} {supervisor.last_name}</strong><span>{supervisor.email}</span></div></div></td><td>{supervisor.job_title ?? 'Nuk është dhënë'}</td><td><AccountBadge active={supervisor.is_active} /></td><td><VerificationBadge status={supervisor.verification_status} /></td><td className="actions-cell"><div className="row-actions"><Link to={`/admin/users/${supervisor.user_id}`}>Detajet e përdoruesit</Link></div></td></tr>)}</tbody></table></div>}</section>
    {decision && <VerificationDialog key={`${id}-${decision}`} company={company} decision={decision} onClose={() => setDecision(null)} onUpdated={(updated) => { setCompany(updated); setFeedback({ text: updated.verification_status === 'APPROVED' ? 'Kompania u miratua me sukses.' : 'Kompania u refuzua me sukses.', conflict: false }) }} onConflict={(text) => { setDecision(null); setCompany(null); setFeedback({ text, conflict: true }); setRevision((value) => value + 1) }} />}
  </>
}
