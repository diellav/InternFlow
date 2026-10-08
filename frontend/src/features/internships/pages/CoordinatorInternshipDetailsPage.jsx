import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../../auth/hooks/useAuth'
import { PageHeading } from '../../users/components/UserUi'
import { claimInternship, coordinatorErrorMessage, getCoordinatorInternship } from '../api/internshipsApi'
import { InternshipStatusBadge } from '../components/InternshipStatusBadge'
import { InternshipReviewDialog } from '../components/InternshipReviewDialog'
import { InternshipDecisionSummary } from '../components/InternshipDecisionSummary'

export function CoordinatorInternshipDetailsPage() {
  const { id } = useParams()
  const recordId = useRef(id)
  const { user, refreshUser } = useAuth()
  const pending = useRef(false)
  const [internship, setInternship] = useState(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [revision, setRevision] = useState(0)
  const [reviewAction, setReviewAction] = useState(null)
  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(async () => {
      if (controller.signal.aborted) return
      if (recordId.current !== id) { recordId.current = id; setReviewAction(null); setNotice('') }
      setInternship(null); setError('')
      try { const data = await getCoordinatorInternship(id, controller.signal); if (!controller.signal.aborted) setInternship(data) }
      catch (failure) {
        if (controller.signal.aborted) return
        setError(coordinatorErrorMessage(failure))
        if ([401, 403].includes(failure.status)) await refreshUser()
      }
    })
    return () => controller.abort()
  }, [id, revision, refreshUser])
  async function claim() {
    if (pending.current) return
    pending.current = true; setBusy(true); setNotice('')
    try { setInternship(await claimInternship(internship.id)); setNotice('Aplikimi u caktua për ju. Statusi mbetet i dorëzuar.') }
    catch (failure) {
      setNotice(coordinatorErrorMessage(failure))
      if ([404, 409].includes(failure.status)) { setInternship(null); setRevision((value) => value + 1) }
      if ([401, 403].includes(failure.status)) { setInternship(null); setError(coordinatorErrorMessage(failure)); await refreshUser() }
    } finally { pending.current = false; setBusy(false) }
  }
  return <><Link className="internship-back" to="/coordinator/internships">← Kutia hyrëse</Link>{notice && <p className="form-alert" role="status">{notice}</p>}{error ? <section className="internship-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRevision((value) => value + 1)}>Provo përsëri</button></section> : !internship || internship.id !== Number(id) ? <p role="status">Duke ngarkuar aplikimin…</p> : <>
    <PageHeading eyebrow={`Aplikimi #${internship.id}`} title={internship.position_title} description={internship.company?.name}><InternshipStatusBadge status={internship.status} />{internship.status === 'SUBMITTED' && internship.coordinator_id === null && <button className="admin-button admin-primary" disabled={busy} onClick={claim}>{busy ? 'Duke marrë…' : 'Merr për shqyrtim'}</button>}{internship.coordinator_id === user.id && internship.status === 'SUBMITTED' && <button className="admin-button admin-primary" onClick={() => setReviewAction('START_REVIEW')}>Fillo shqyrtimin</button>}{internship.coordinator_id === user.id && internship.status === 'UNDER_REVIEW' && <><button className="admin-button admin-primary" onClick={() => setReviewAction('APPROVED')}>Mirato</button><button className="admin-button admin-danger" onClick={() => setReviewAction('REJECTED')}>Refuzo</button><button className="admin-button" onClick={() => setReviewAction('REVISION_REQUIRED')}>Kërko korrigjime</button></>}</PageHeading>
    {reviewAction && <InternshipReviewDialog internship={internship} action={reviewAction} onClose={() => setReviewAction(null)} onUpdated={(updated) => { setInternship(updated); setNotice(reviewAction === 'START_REVIEW' ? 'Shqyrtimi filloi me sukses.' : 'Vendimi akademik u regjistrua.'); setRevision((value) => value + 1) }} onConflict={(message) => { setReviewAction(null); setNotice(message); setRevision((value) => value + 1) }} />}
    <div className="internship-help coordinator-review-note"><strong>{internship.coordinator_id === null ? 'Aplikim i pacaktuar' : 'Aplikim i caktuar për ju'}</strong><p>{internship.coordinator_id === null ? 'Merreni aplikimin përpara se të filloni shqyrtimin. Marrja nuk ndryshon statusin dhe nuk përbën miratim.' : 'Vetëm koordinatori i caktuar mund të fillojë shqyrtimin dhe të regjistrojë vendimin.'}</p></div>
    <InternshipDecisionSummary internship={internship} />
    <div className="internship-detail-grid"><section className="internship-panel"><h2>Studenti dhe informacioni akademik</h2><dl className="internship-facts">{[['Studenti', `${internship.student.first_name} ${internship.student.last_name}`], ['Numri i studentit', internship.student.student_number], ['Programi i studimit', internship.student.study_program], ['Viti i studimit', internship.student.study_year]].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value ?? 'Nuk është dhënë'}</dd></div>)}</dl></section><section className="internship-panel"><h2>Kompania dhe mbikëqyrësi</h2><dl className="internship-facts">{[['Kompania', internship.company?.name], ['Industria', internship.company?.industry], ['Mbikëqyrësi', internship.supervisor ? `${internship.supervisor.first_name} ${internship.supervisor.last_name}` : null], ['Pozita e mbikëqyrësit', internship.supervisor?.job_title]].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value ?? 'Nuk është dhënë'}</dd></div>)}</dl></section></div>
    <section className="internship-panel internship-record"><h2>Informacioni i praktikës</h2><p className="internship-description">{internship.description || 'Nuk është dhënë përshkrim.'}</p><dl className="internship-facts">{[['Fillimi', internship.start_date], ['Përfundimi', internship.end_date], ['Dorëzuar më', internship.submitted_at ? new Date(internship.submitted_at).toLocaleString('sq-AL') : null], ['Koordinatori', internship.coordinator ? `${internship.coordinator.first_name} ${internship.coordinator.last_name}` : 'Ende i pacaktuar'], ['Njësia akademike', internship.coordinator?.academic_unit]].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value ?? 'Nuk është dhënë'}</dd></div>)}</dl></section>
    </>}</>
}
