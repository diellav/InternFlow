import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/hooks/useAuth'
import { internshipErrorMessage, submitInternship, resubmitInternship } from '../api/internshipsApi'

export function SubmitInternshipDialog({ internship, onClose, onUpdated, onConflict }) {
  const dialog = useRef(null)
  const resubmitting = internship.status === 'REVISION_REQUIRED'
  const busy = useRef(false)
  const { refreshUser } = useAuth()
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [errors, setErrors] = useState([])
  useEffect(() => {
    const element = dialog.current
    element.showModal()
    return () => element.close()
  }, [])
  async function confirm(event) {
    event.preventDefault()
    if (busy.current) return
    busy.current = true
    setSubmitting(true); setError(''); setErrors([])
    try { onUpdated(await (resubmitting ? resubmitInternship : submitInternship)(internship.id)); onClose() }
    catch (failure) {
      if (failure.status === 409) { onConflict(internshipErrorMessage(failure)); return }
      setError(internshipErrorMessage(failure))
      setErrors(Object.entries(failure.validationErrors ?? {}).map(([field, messages]) => `${({ company_supervisor_id: 'Mbikëqyrësi', company_id: 'Kompania', coordinator_id: 'Koordinatori (kontaktoni njësinë akademike)', position_title: 'Titulli', start_date: 'Fillimi', end_date: 'Përfundimi' })[field] ?? field}: ${messages[0]}`))
      if ([401, 403].includes(failure.status)) await refreshUser()
      busy.current = false; setSubmitting(false)
    }
  }
  return <dialog ref={dialog} className="admin-dialog" aria-labelledby="submission-title" aria-describedby="submission-description" onCancel={(event) => { event.preventDefault(); if (!busy.current) onClose() }}>
    <p className="admin-eyebrow">Shqyrtim akademik</p><h2 id="submission-title">{resubmitting ? 'Ridorëzoni aplikimin për shqyrtim?' : 'Dorëzoni aplikimin për miratim?'}</h2>
    <p id="submission-description"><strong>{internship.position_title}</strong> {resubmitting ? 'do t’i kthehet të njëjtit koordinator akademik për shqyrtim të ri. Nuk mund ta ndryshoni gjatë pritjes ose shqyrtimit. Udhëzimet aktuale do të pastrohen; nuk ruhet historik i tyre.' : 'do të dorëzohet në kutinë e përbashkët të koordinatorëve. Pas dorëzimit, redaktimi i zakonshëm i draftit nuk është më i disponueshëm. Nevojitet një mbikëqyrës aktiv dhe i miratuar nga kompania e përzgjedhur.'}</p>
    <form onSubmit={confirm}>{error && <div className="form-alert" role="alert"><p>{error}</p>{errors.length > 0 && <ul>{errors.map((message) => <li key={message}>{message}</li>)}</ul>}{resubmitting && <Link to={`/student/internships/${internship.id}/edit`} onClick={onClose}>Kthehu te korrigjimet</Link>}</div>}<div className="admin-dialog-actions"><button type="button" className="admin-button" autoFocus disabled={submitting} onClick={onClose}>Anulo</button><button className="admin-button admin-primary" disabled={submitting}>{submitting ? 'Duke dorëzuar…' : resubmitting ? 'Konfirmo ridorëzimin' : 'Konfirmo dorëzimin'}</button></div></form>
  </dialog>
}
