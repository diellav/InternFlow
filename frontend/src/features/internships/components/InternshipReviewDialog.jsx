import { useEffect, useRef, useState } from 'react'
import { useAuth } from '../../auth/hooks/useAuth'
import { decideInternship, reviewErrorMessage, startInternshipReview } from '../api/internshipsApi'

export function InternshipReviewDialog({ internship, action, onClose, onUpdated, onConflict }) {
  const dialog = useRef(null)
  const input = useRef(null)
  const pending = useRef(false)
  const { refreshUser } = useAuth()
  const [comment, setComment] = useState('')
  const [commentError, setCommentError] = useState('')
  const [error, setError] = useState('')
  const [validationErrors, setValidationErrors] = useState([])
  const [busy, setBusy] = useState(false)
  const needsComment = ['REJECTED', 'REVISION_REQUIRED'].includes(action)
  const titles = { START_REVIEW: 'Filloni shqyrtimin?', APPROVED: 'Miratoni aplikimin?', REJECTED: 'Refuzoni aplikimin?', REVISION_REQUIRED: 'Kërkoni korrigjime?' }
  useEffect(() => {
    const element = dialog.current
    element.showModal()
    return () => element.close()
  }, [])
  async function confirm(event) {
    event.preventDefault()
    if (pending.current) return
    setError(''); setCommentError(''); setValidationErrors([])
    if (needsComment && (!comment.trim() || comment.trim().length > 10000)) {
      setCommentError(comment.trim() ? 'Maksimumi 10,000 karaktere.' : 'Shkruani një shpjegim jo të zbrazët.')
      input.current.focus()
      return
    }
    pending.current = true; setBusy(true)
    try {
      const updated = action === 'START_REVIEW' ? await startInternshipReview(internship.id) : await decideInternship(internship.id, { decision: action, ...(needsComment ? { decision_comment: comment.trim() } : {}) })
      onUpdated(updated); onClose()
    } catch (failure) {
      if ([404, 409].includes(failure.status)) { onConflict(reviewErrorMessage(failure)); return }
      setError(reviewErrorMessage(failure))
      setCommentError(failure.validationErrors?.decision_comment?.[0] ?? '')
      setValidationErrors(Object.entries(failure.validationErrors ?? {}).filter(([field]) => field !== 'decision_comment').map(([field, messages]) => `${({ company_id: 'Kompania', company_supervisor_id: 'Mbikëqyrësi', position_title: 'Titulli', start_date: 'Fillimi', end_date: 'Përfundimi', decision: 'Vendimi' })[field] ?? field}: ${messages[0]}`))
      pending.current = false; setBusy(false)
      if ([401, 403].includes(failure.status)) await refreshUser()
    }
  }
  return <dialog ref={dialog} className="admin-dialog internship-review-dialog" aria-labelledby="review-dialog-title" aria-describedby="review-dialog-description" onCancel={(event) => { event.preventDefault(); if (!pending.current) onClose() }}><p className="admin-eyebrow">Shqyrtim akademik · #{internship.id}</p><h2 id="review-dialog-title">{titles[action]}</h2><p id="review-dialog-description"><strong>{internship.position_title}</strong>. {action === 'START_REVIEW' ? 'Aplikimi do të kalojë në shqyrtim. Ky veprim nuk regjistron një vendim.' : action === 'APPROVED' ? 'Miratimi regjistron kohën e serverit dhe nuk aktivizon praktikën. Vendimi nuk mund të ndryshohet përmes këtij veprimi.' : 'Shpjegimi do të jetë i dukshëm për studentin. Vendimi nuk mund të ndryshohet përmes këtij veprimi.'}</p><form onSubmit={confirm} noValidate>
    {needsComment && <div className="form-field"><label htmlFor="internship-decision-comment">{action === 'REVISION_REQUIRED' ? 'Udhëzimet për korrigjim *' : 'Arsyeja e refuzimit *'}</label><textarea id="internship-decision-comment" ref={input} value={comment} onChange={(event) => { setComment(event.target.value); setCommentError(''); setError('') }} rows={5} maxLength={10000} required disabled={busy} aria-invalid={Boolean(commentError)} aria-describedby={commentError ? 'review-comment-error' : 'review-comment-hint'} /><span id="review-comment-hint" className="admin-muted">Maksimumi 10,000 karaktere. Shpjegimi ruhet si vendimi aktual, jo si histori e plotë.</span>{commentError && <span className="field-error" id="review-comment-error">{commentError}</span>}</div>}
    {error && <div className="form-alert" role="alert"><p>{error}</p>{validationErrors.length > 0 && <ul>{validationErrors.map((message) => <li key={message}>{message}</li>)}</ul>}</div>}<div className="admin-dialog-actions"><button className="admin-button" type="button" autoFocus disabled={busy} onClick={onClose}>Anulo</button><button className={`admin-button ${action === 'REJECTED' ? 'admin-danger' : 'admin-primary'}`} disabled={busy}>{busy ? 'Duke ruajtur…' : 'Konfirmo'}</button></div></form></dialog>
}
