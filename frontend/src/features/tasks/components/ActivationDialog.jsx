import { useEffect, useRef, useState } from 'react'
import { useAuth } from '../../auth/hooks/useAuth'
import { activateInternship, taskErrorMessage } from '../api/tasksApi'

export function ActivationDialog({ internship, onClose, onUpdated, onConflict }) {
  const dialog = useRef(null)
  const pending = useRef(false)
  const { refreshUser } = useAuth()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [errors, setErrors] = useState([])
  useEffect(() => { const element = dialog.current; element.showModal(); return () => element.close() }, [])
  async function confirm(event) {
    event.preventDefault()
    if (pending.current) return
    pending.current = true; setBusy(true); setError(''); setErrors([])
    try { await activateInternship(internship.id); onUpdated(); onClose() }
    catch (failure) {
      if ([404, 409].includes(failure.status)) { onConflict(taskErrorMessage(failure)); return }
      setError(taskErrorMessage(failure)); setErrors(Object.values(failure.validationErrors ?? {}).flat())
      pending.current = false; setBusy(false)
      if ([401, 403].includes(failure.status)) await refreshUser()
    }
  }
  return <dialog ref={dialog} className="admin-dialog" aria-labelledby="activation-title" aria-describedby="activation-description" onCancel={(event) => { event.preventDefault(); if (!pending.current) onClose() }}><p className="admin-eyebrow">Fillimi i praktikës · #{internship.id}</p><h2 id="activation-title">Filloni praktikën?</h2><p id="activation-description">Praktika <strong>{internship.position_title}</strong> do të bëhet aktive. Datat dhe miratimi akademik nuk ndryshojnë. Fillimi lejohet vetëm brenda periudhës së praktikës, sipas datës së serverit.</p><form onSubmit={confirm}>{error && <div className="form-alert" role="alert"><p>{error}</p><ul>{errors.map((message) => <li key={message}>{message}</li>)}</ul></div>}<div className="admin-dialog-actions"><button className="admin-button" type="button" autoFocus disabled={busy} onClick={onClose}>Anulo</button><button className="admin-button admin-primary" disabled={busy}>{busy ? 'Duke filluar…' : 'Konfirmo fillimin'}</button></div></form></dialog>
}
