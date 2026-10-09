import { useEffect, useRef, useState } from 'react'
import { useAuth } from '../../auth/hooks/useAuth'
import { reviewTask, taskErrorMessage } from '../api/tasksApi'

export function TaskReviewDialog({ taskId, submission, decision, onClose, onUpdated }) {
  const revision = decision === 'REVISION_REQUIRED'
  const [comment, setComment] = useState('')
  const [error, setError] = useState('')
  const [fieldError, setFieldError] = useState('')
  const [busy, setBusy] = useState(false)
  const pending = useRef(false)
  const dialog = useRef(null)
  const { refreshUser } = useAuth()
  useEffect(() => { const element = dialog.current; element.showModal(); return () => element.close() }, [])
  async function confirm(event) {
    event.preventDefault()
    if (pending.current) return
    const value = comment.trim()
    if ((revision && !value) || value.length > 10000) { setFieldError(!value ? 'Shkruani udhëzimet e korrigjimit.' : 'Maksimumi 10,000 karaktere.'); document.getElementById('review-comment')?.focus(); return }
    pending.current = true; setBusy(true); setError(''); setFieldError('')
    try { await reviewTask(taskId, { decision, comment: value || null, expected_submission_id: submission.id }); onUpdated(); onClose() }
    catch (failure) {
      if ([404, 409].includes(failure.status)) { onUpdated(taskErrorMessage(failure)); onClose() }
      else {
        setError(taskErrorMessage(failure)); setFieldError(failure.validationErrors?.comment?.[0] ?? '')
        if ([401, 403].includes(failure.status)) await refreshUser()
      }
    } finally { pending.current = false; setBusy(false) }
  }
  return <dialog ref={dialog} className="admin-dialog task-review-dialog" aria-labelledby="review-title" aria-describedby="review-description" onCancel={(event) => { event.preventDefault(); if (!pending.current) onClose() }}><p className="admin-eyebrow">Dorëzimi {submission.version_no}</p><h2 id="review-title">{revision ? 'Kërkoni korrigjime?' : 'Miratoni punën?'}</h2><p id="review-description">{revision ? 'Jepni udhëzime të qarta. Dorëzimi do të shënohet për korrigjim.' : 'Ky vendim miraton vetëm detyrën, jo përfundimin e praktikës.'}</p><form className="internship-form" onSubmit={confirm} noValidate>{error && <p className="form-alert" role="alert">{error}</p>}<div className="form-field"><label htmlFor="review-comment">{revision ? 'Udhëzimet e korrigjimit *' : 'Komenti (opsional)'}</label><textarea id="review-comment" rows={5} value={comment} maxLength={10000} required={revision} disabled={busy} aria-invalid={Boolean(fieldError)} aria-describedby={fieldError ? 'review-comment-error' : undefined} onChange={(event) => { setComment(event.target.value); setFieldError('') }} />{fieldError && <span className="field-error" id="review-comment-error">{fieldError}</span>}</div><div className="admin-dialog-actions"><button className="admin-button" type="button" autoFocus disabled={busy} onClick={onClose}>Anulo</button><button className="admin-button admin-primary" disabled={busy}>{busy ? 'Duke ruajtur vendimin…' : revision ? 'Konfirmo korrigjimet' : 'Konfirmo miratimin'}</button></div></form></dialog>
}
