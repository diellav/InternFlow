import { useEffect, useRef, useState } from 'react'
import { companyErrorMessage, verifyCompany } from '../api/companiesApi'
import { supervisorErrorMessage, verifySupervisor } from '../api/supervisorsApi'

export function VerificationDialog({ company, supervisor, decision, onClose, onUpdated, onConflict }) {
  const dialog = useRef(null)
  const reasonInput = useRef(null)
  const busy = useRef(false)
  const [reason, setReason] = useState('')
  const [reasonError, setReasonError] = useState('')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const rejecting = decision === 'REJECTED'
  const subject = supervisor ?? company
  const subjectName = supervisor ? `${supervisor.first_name} ${supervisor.last_name}` : company.name
  const errorMessage = supervisor ? supervisorErrorMessage : companyErrorMessage

  useEffect(() => {
    const element = dialog.current
    element.showModal()
    return () => element.close()
  }, [])

  async function confirm(event) {
    event.preventDefault()
    if (busy.current) return
    setError('')
    setReasonError('')
    if (rejecting && !reason.trim()) {
      setReasonError('Shkruani arsyen e refuzimit.')
      reasonInput.current.focus()
      return
    }
    busy.current = true
    setSubmitting(true)
    try {
      const updated = await (supervisor ? verifySupervisor : verifyCompany)(subject.id, { decision, ...(rejecting ? { reason: reason.trim() } : {}) })
      onUpdated(updated)
      onClose()
    } catch (requestError) {
      if (requestError.status === 409) {
        onConflict(errorMessage(requestError))
        return
      }
      setReasonError(requestError.validationErrors?.reason?.[0] ?? '')
      setError(requestError.type === 'validation' ? 'Kontrolloni të dhënat dhe provoni përsëri.' : errorMessage(requestError))
      busy.current = false
      setSubmitting(false)
    }
  }

  return <dialog ref={dialog} className="admin-dialog verification-dialog" aria-labelledby="company-verification-title" aria-describedby="company-verification-description" onCancel={(event) => { event.preventDefault(); if (!busy.current) onClose() }}>
    <p className="admin-eyebrow">Verifikimi {supervisor ? 'i mbikëqyrësit' : 'i kompanisë'}</p>
    <h2 id="company-verification-title">{rejecting ? 'Refuzoni' : 'Miratoni'} {supervisor ? 'mbikëqyrësin' : 'kompaninë'}?</h2>
    <p id="company-verification-description"><strong>{subjectName}</strong> do të shënohet si {supervisor ? (rejecting ? 'i refuzuar' : 'i miratuar') : (rejecting ? 'e refuzuar' : 'e miratuar')}. {supervisor ? 'Statusi i kompanisë dhe aktivizimi i llogarisë nuk ndryshojnë.' : 'Verifikimi dhe llogaritë e mbikëqyrësve nuk ndryshojnë.'} {rejecting ? 'Një aplikim i refuzuar mund të shqyrtohet përsëri vetëm pas një ridërgimi të autorizuar.' : 'Një aplikim i miratuar nuk mund të shqyrtohet përsëri.'}</p>
    <form onSubmit={confirm} noValidate>
      {rejecting && <div className="form-field"><label htmlFor="company-rejection-reason">Arsyeja e refuzimit *</label><textarea ref={reasonInput} id="company-rejection-reason" value={reason} onChange={(event) => { setReason(event.target.value); setReasonError(''); setError('') }} maxLength={2000} rows={5} required disabled={submitting} aria-invalid={Boolean(reasonError)} aria-describedby={reasonError ? 'company-reason-error' : 'company-reason-hint'} /><span id="company-reason-hint" className="admin-muted">Maksimumi 2,000 karaktere.</span>{reasonError && <span className="field-error" id="company-reason-error">{reasonError}</span>}</div>}
      {error && <p className="form-alert" role="alert">{error}</p>}
      <div className="admin-dialog-actions"><button type="button" className="admin-button" onClick={onClose} disabled={submitting} autoFocus>Anulo</button><button type="submit" className={`admin-button ${rejecting ? 'admin-danger' : 'admin-primary'}`} disabled={submitting}>{submitting ? 'Duke ruajtur…' : 'Konfirmo'}</button></div>
    </form>
  </dialog>
}
