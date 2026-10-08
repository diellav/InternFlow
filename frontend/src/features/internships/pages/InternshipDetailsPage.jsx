import { useEffect, useRef, useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import { useAuth } from '../../auth/hooks/useAuth'
import { PageHeading } from '../../users/components/UserUi'
import { getInternship, internshipErrorMessage } from '../api/internshipsApi'
import { InternshipStatusBadge } from '../components/InternshipStatusBadge'
import { SubmitInternshipDialog } from '../components/SubmitInternshipDialog'
import { InternshipDecisionSummary } from '../components/InternshipDecisionSummary'

function dateLabel(value) {
  return value ? new Intl.DateTimeFormat('sq-AL', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : null
}

export function InternshipDetailsPage() {
  const { id } = useParams()
  const recordId = useRef(id)
  const { refreshUser } = useAuth()
  const location = useLocation()
  const [internship, setInternship] = useState(null)
  const [error, setError] = useState('')
  const [revision, setRevision] = useState(0)
  const [confirming, setConfirming] = useState(false)
  const [notice, setNotice] = useState('')
  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(async () => {
      if (controller.signal.aborted) return
      if (recordId.current !== id) { recordId.current = id; setConfirming(false); setNotice('') }
      setInternship(null); setError('')
      try { const data = await getInternship(id, controller.signal); if (!controller.signal.aborted) setInternship(data) }
      catch (failure) {
        if (controller.signal.aborted) return
        setError(internshipErrorMessage(failure))
        if ([401, 403].includes(failure.status)) await refreshUser()
      }
    })
    return () => controller.abort()
  }, [id, revision, refreshUser])
  if (error) return <section className="internship-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRevision((current) => current + 1)}>Provo përsëri</button><Link to="/student/internships">Kthehu te aplikimet</Link></section>
  if (!internship || internship.id !== Number(id)) return <p role="status">Duke ngarkuar aplikimin…</p>
  const supervisor = internship.supervisor
  const facts = [['Kompania', internship.company?.name], ['Industria', internship.company?.industry], ['Mbikëqyrësi', supervisor ? `${supervisor.first_name} ${supervisor.last_name}` : 'Nuk është përzgjedhur'], ['Pozita e mbikëqyrësit', supervisor?.job_title], ['Data e fillimit', internship.start_date], ['Data e përfundimit', internship.end_date]]
  return <>
    <Link className="internship-back" to="/student/internships">← Praktikat e mia</Link>
    <PageHeading eyebrow={`Aplikimi #${internship.id}`} title={internship.position_title} description={internship.company?.name}><InternshipStatusBadge status={internship.status} />{['DRAFT', 'REVISION_REQUIRED'].includes(internship.status) && <><Link className="admin-button" to={`/student/internships/${id}/edit`}>{internship.status === 'REVISION_REQUIRED' ? 'Ndrysho aplikimin' : 'Ndrysho draftin'}</Link><button className="admin-button admin-primary" onClick={() => setConfirming(true)}>{internship.status === 'REVISION_REQUIRED' ? 'Ridorëzo për shqyrtim' : 'Dorëzo për miratim'}</button></>}</PageHeading>
    {notice && <p className="form-success" role="status">{notice}</p>}
    {confirming && <SubmitInternshipDialog internship={internship} onClose={() => setConfirming(false)} onUpdated={(updated) => { setInternship(updated); setNotice('Aplikimi u dorëzua për shqyrtim akademik.'); setRevision((current) => current + 1) }} onConflict={(message) => { setConfirming(false); setNotice(message); setRevision((current) => current + 1) }} />}
    {location.state?.notice && ['DRAFT', 'REVISION_REQUIRED'].includes(internship.status) && <p className="form-success" role="status">{location.state.notice}</p>}
    {location.state?.conflict && <p className="form-alert" role="alert">{location.state.conflict}</p>}
    <InternshipDecisionSummary internship={internship} student />
    <div className="internship-detail-grid"><section className="internship-panel"><h2>Informacioni i praktikës</h2><dl className="internship-facts">{facts.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value ?? 'Nuk është dhënë'}</dd></div>)}</dl></section><section className="internship-panel"><h2>Përshkrimi i praktikës</h2><p className="internship-description">{internship.description || 'Përshkrimi nuk është plotësuar ende.'}</p><div className="internship-help"><strong>{internship.status === 'REVISION_REQUIRED' ? 'Aplikim për korrigjim' : internship.status === 'DRAFT' ? 'Aplikimi është ende draft' : 'Aplikim vetëm për lexim'}</strong><p>{internship.status === 'REVISION_REQUIRED' ? 'Ruani korrigjimet, pastaj ridorëzojeni veçmas te koordinatori i caktuar.' : internship.status === 'DRAFT' ? 'Mund të ndryshoni informacionin e ruajtur. Ky draft nuk është dorëzuar për shqyrtim akademik.' : 'Ky aplikim nuk mund të ndryshohet përmes redaktimit të draftit.'}</p></div></section></div>
    <section className="internship-panel internship-record"><h2>Të dhënat e regjistrimit</h2><dl className="internship-facts">{[['Krijuar më', dateLabel(internship.created_at)], ['Përditësuar më', dateLabel(internship.updated_at)], ['Dorëzuar më', dateLabel(internship.submitted_at)], ['Miratuar më', dateLabel(internship.approved_at)], ['Përfunduar më', dateLabel(internship.completed_at)], ['Koordinatori', internship.coordinator ? `${internship.coordinator.first_name} ${internship.coordinator.last_name}` : null], ['Njësia akademike', internship.coordinator?.academic_unit], ['Shënimi i vendimit', internship.decision_comment]].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value ?? 'Nuk është dhënë'}</dd></div>)}</dl></section>
  </>
}
