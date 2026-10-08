import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { AccountBadge, PageHeading } from '../../users/components/UserUi'
import { getSupervisor, supervisorErrorMessage } from '../api/supervisorsApi'
import { VerificationBadge } from '../components/VerificationBadge'
import { VerificationDialog } from '../components/VerificationDialog'

function Detail({ label, children }) {
  return <div><dt>{label}</dt><dd>{children ?? 'Nuk është dhënë'}</dd></div>
}

export function SupervisorDetailsPage() {
  const { id } = useParams()
  const [user, setUser] = useState(null)
  const [error, setError] = useState('')
  const [revision, setRevision] = useState(0)
  const [decision, setDecision] = useState(null)
  const [feedback, setFeedback] = useState(null)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(async () => {
      if (controller.signal.aborted) return
      setUser(null)
      setError('')
      try {
        const response = await getSupervisor(id, controller.signal)
        if (!controller.signal.aborted) setUser(response)
      } catch (requestError) { if (!controller.signal.aborted) setError(supervisorErrorMessage(requestError)) }
    })
    return () => controller.abort()
  }, [id, revision])

  const message = feedback && <p className={feedback.conflict ? 'form-alert' : 'form-success'} role={feedback.conflict ? 'alert' : 'status'}>{feedback.text}</p>
  if (error) return <>{message}<div className="admin-panel admin-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRevision((value) => value + 1)}>Provo përsëri</button><Link to="/admin/supervisors">Kthehu te mbikëqyrësit</Link></div></>
  if (!user) return <>{message}<p role="status">Duke ngarkuar mbikëqyrësin…</p></>
  const profile = user.profile
  const company = profile?.company
  const restrictions = []
  if (!user.is_active) restrictions.push('Llogaria është joaktive.')
  if (!profile || profile.verification_status !== 'APPROVED') restrictions.push('Mbikëqyrësi nuk është miratuar.')
  if (!company || company.verification_status !== 'APPROVED') restrictions.push('Kompania nuk është miratuar.')
  if (company && !company.is_active) restrictions.push('Kompania është joaktive.')

  return <>
    <Link className="admin-back-link" to="/admin/supervisors">← Të gjithë mbikëqyrësit</Link>
    <PageHeading title={`${user.first_name} ${user.last_name}`} description="Verifikimi i mbikëqyrësit të kompanisë.">{profile && <VerificationBadge status={profile.verification_status} />}{profile?.verification_status === 'PENDING' && <><button className="admin-button admin-primary" disabled={decision !== null} onClick={() => { setFeedback(null); setDecision('APPROVED') }}>Mirato mbikëqyrësin</button><button className="admin-button admin-danger" disabled={decision !== null} onClick={() => { setFeedback(null); setDecision('REJECTED') }}>Refuzo mbikëqyrësin</button></>}</PageHeading>
    {message}
    <p className="admin-muted" role="note">{restrictions.length ? `Qasja operative mbetet e kufizuar. ${restrictions.join(' ')}` : 'Kushtet e verifikimit dhe aktivizimit për qasje operative janë plotësuar.'}</p>
    <div className="admin-detail-grid"><section className="admin-panel detail-panel"><h2>Të dhënat e mbikëqyrësit</h2><dl className="admin-detail-list"><Detail label="Emri">{user.first_name}</Detail><Detail label="Mbiemri">{user.last_name}</Detail><Detail label="Email">{user.email}</Detail><Detail label="Telefoni">{user.phone}</Detail><Detail label="Llogaria"><AccountBadge active={user.is_active} /></Detail><Detail label="Pozita">{profile?.job_title}</Detail><Detail label="Regjistruar më">{user.created_at ? new Date(user.created_at).toLocaleString('sq-AL') : null}</Detail></dl></section>
    <section className="admin-panel detail-panel"><h2>Verifikimi dhe kompania</h2>{!profile ? <p className="admin-muted">Profili i mbikëqyrësit mungon dhe nuk mund të shqyrtohet.</p> : <dl className="admin-detail-list"><Detail label="Verifikimi i mbikëqyrësit"><VerificationBadge status={profile.verification_status} /></Detail><Detail label="Kompania">{company && <Link to={`/admin/companies/${company.id}`}>{company.name}</Link>}</Detail><Detail label="Verifikimi i kompanisë">{company && <VerificationBadge status={company.verification_status} />}</Detail><Detail label="Statusi i kompanisë">{company && <AccountBadge active={company.is_active} />}</Detail><Detail label="ID e shqyrtuesit">{profile.reviewed_by}</Detail><Detail label="Shqyrtuar më">{profile.reviewed_at ? new Date(profile.reviewed_at).toLocaleString('sq-AL') : null}</Detail><Detail label={profile.verification_status === 'REJECTED' ? 'Arsyeja e refuzimit' : 'Shënimi i shqyrtimit'}><span className="company-note">{profile.review_comment ?? 'Nuk është dhënë'}</span></Detail></dl>}</section></div>
    {decision && <VerificationDialog key={`${id}-${decision}`} supervisor={user} decision={decision} onClose={() => setDecision(null)} onUpdated={(updated) => { setUser(updated); setFeedback({ text: updated.profile.verification_status === 'APPROVED' ? 'Mbikëqyrësi u miratua me sukses.' : 'Mbikëqyrësi u refuzua me sukses.', conflict: false }) }} onConflict={(text) => { setDecision(null); setUser(null); setFeedback({ text, conflict: true }); setRevision((value) => value + 1) }} />}
  </>
}
