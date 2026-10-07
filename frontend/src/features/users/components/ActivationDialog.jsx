import { useEffect, useRef, useState } from 'react'
import { errorMessage, updateActivation } from '../api/usersApi'

export function ActivationDialog({ user, onClose, onUpdated }) {
  const dialog = useRef(null)
  const busy = useRef(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const activating = !user.is_active

  useEffect(() => {
    const element = dialog.current
    element.showModal()
    return () => element.close()
  }, [])

  async function confirm() {
    if (busy.current) return
    busy.current = true
    setSubmitting(true)
    setError('')
    try {
      const updated = await updateActivation(user.id, activating)
      onUpdated(updated)
      onClose()
    } catch (requestError) {
      setError(errorMessage(requestError))
      busy.current = false
      setSubmitting(false)
    }
  }

  return <dialog ref={dialog} className="admin-dialog" aria-labelledby="activation-title" aria-describedby="activation-description" onCancel={(event) => { event.preventDefault(); if (!submitting) onClose() }}>
    <p className="admin-eyebrow">Statusi i llogarisë</p>
    <h2 id="activation-title">{activating ? 'Aktivizoni' : 'Çaktivizoni'} llogarinë?</h2>
    <p id="activation-description"><strong>{user.first_name} {user.last_name}</strong>{activating ? ' do të mund të kyçet dhe të përdorë funksionet e lejuara për rolin e vet.' : ' nuk do të ketë qasje në funksionet e mbrojtura. Të dhënat e llogarisë ruhen.'}</p>
    {error && <p className="form-alert" role="alert">{error}</p>}
    <div className="admin-dialog-actions"><button className="admin-button" onClick={onClose} disabled={submitting} autoFocus>Anulo</button><button className={`admin-button ${activating ? 'admin-primary' : 'admin-danger'}`} onClick={confirm} disabled={submitting}>{submitting ? 'Duke ruajtur…' : 'Konfirmo'}</button></div>
  </dialog>
}
