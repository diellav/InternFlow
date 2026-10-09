import { useEffect, useRef, useState } from 'react'
import { useAuth } from '../../auth/hooks/useAuth'
import { resubmitTask, startTask, submitTask, taskErrorMessage } from '../api/tasksApi'
import { fileSize } from '../utils/fileSize'

export function StudentTaskActions({ task, onUpdated, resubmission = null }) {
  const [text, setText] = useState(resubmission?.submission_text ?? '')
  const [url, setUrl] = useState(resubmission?.resource_url ?? '')
  const [formOpen, setFormOpen] = useState(!resubmission)
  const [errors, setErrors] = useState({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [files, setFiles] = useState([])
  const [progress, setProgress] = useState(null)
  const pending = useRef(false)
  const dialog = useRef(null)
  const { refreshUser } = useAuth()
  useEffect(() => {
    if (!confirming) return
    const element = dialog.current
    element.showModal()
    return () => element.close()
  }, [confirming])
  async function perform(submission) {
    if (pending.current) return
    pending.current = true; setBusy(true); setError(''); setProgress(null)
    try {
      if (submission) {
        const payload = { submission_text: text.trim(), resource_url: url.trim() || null }
        if (files.length) payload.files = files
        const uploadProgress = (event) => { if (event.total) setProgress(Math.min(100, Math.round(event.loaded * 100 / event.total))) }
        const options = files.length ? [uploadProgress] : []
        if (resubmission) await resubmitTask(task.id, { ...payload, expected_submission_id: resubmission.id }, ...options)
        else await submitTask(task.id, payload, ...options)
      }
      else await startTask(task.id)
      setConfirming(false); onUpdated()
    } catch (failure) {
      setConfirming(false)
      if ([404, 409].includes(failure.status)) { onUpdated(taskErrorMessage(failure)) }
      else {
        setError(taskErrorMessage(failure))
        setErrors(Object.fromEntries(Object.entries(failure.validationErrors ?? {}).map(([field, messages]) => [field, messages[0]])))
        if ([401, 403].includes(failure.status)) await refreshUser()
      }
    } finally { pending.current = false; setBusy(false) }
  }
  function prepare(event) {
    event.preventDefault()
    if (pending.current) return
    const invalid = {}
    if (!text.trim()) invalid.submission_text = 'Përshkruani punën e përfunduar.'
    else if (text.trim().length > 10000) invalid.submission_text = 'Maksimumi 10,000 karaktere.'
    if (url.trim()) {
      try { const parsed = new URL(url.trim()); if (parsed.protocol !== 'https:' || !parsed.hostname || url.trim().length > 255) throw new Error() }
      catch { invalid.resource_url = 'Vendosni një lidhje HTTPS të vlefshme, deri në 255 karaktere.' }
    }
    setErrors(invalid); setError('')
    if (Object.keys(invalid).length) document.getElementById(`submission-${Object.keys(invalid)[0]}`)?.focus()
    else setConfirming(true)
  }
  function selectFiles(event) {
    const selected = [...files, ...Array.from(event.target.files ?? [])]
    event.target.value = ''
    let message = ''
    if (selected.length > 5) message = 'Lejohen deri në 5 dokumente.'
    else if (selected.some((file) => file.size > 10 * 1024 * 1024)) message = 'Maksimumi 10 MB për dokument.'
    else if (selected.some((file) => !/\.(pdf|docx|xlsx|png|jpe?g|zip)$/i.test(file.name))) message = 'Lejohen PDF, DOCX, XLSX, PNG, JPG dhe ZIP.'
    setErrors((value) => ({ ...value, files: message || undefined }))
    if (!message) setFiles(selected)
  }
  return <section className="internship-panel task-submission-actions" aria-label="Puna ime">{error && <p className="form-alert" role="alert">{error}</p>}
    {resubmission && !formOpen && <button className="admin-button admin-primary" onClick={() => setFormOpen(true)}>Rishiko dhe ridorëzo</button>}
    {task.can_start && <><h2>Filloni punën</h2><p>Hapja e detyrës nuk e ndryshon statusin. Fillojeni shprehimisht kur të jeni gati.</p><button className="admin-button admin-primary" disabled={busy} onClick={() => perform(false)}>{busy ? 'Duke filluar…' : 'Fillo punën'}</button></>}
    {(task.can_submit || resubmission) && formOpen && <form className="internship-form" onSubmit={prepare} noValidate><h2>{resubmission ? 'Korrigjo punën' : 'Dorëzo punën'}</h2>{resubmission && <p className="internship-help">Korrigjoni versionin {resubmission.version_no}. Ridorëzimi krijon një version të ri dhe ruan historikun.</p>}<div className="form-field"><label htmlFor="submission-submission_text">Puna e përfunduar *</label><textarea id="submission-submission_text" value={text} rows={7} maxLength={10000} disabled={busy} required aria-invalid={Boolean(errors.submission_text)} aria-describedby={errors.submission_text ? 'submission-text-error' : undefined} onChange={(event) => { setText(event.target.value); setErrors((value) => ({ ...value, submission_text: undefined })) }} />{errors.submission_text && <span className="field-error" id="submission-text-error">{errors.submission_text}</span>}</div><div className="form-field"><label htmlFor="submission-resource_url">Lidhja e punës (opsionale)</label><input type="url" id="submission-resource_url" value={url} maxLength={255} disabled={busy} aria-invalid={Boolean(errors.resource_url)} aria-describedby={errors.resource_url ? 'submission-url-error' : undefined} onChange={(event) => { setUrl(event.target.value); setErrors((value) => ({ ...value, resource_url: undefined })) }} />{errors.resource_url && <span className="field-error" id="submission-url-error">{errors.resource_url}</span>}</div><div className="form-field task-file-picker"><label htmlFor="submission-files">Dokumentet (opsionale)</label><input id="submission-files" type="file" multiple accept=".pdf,.docx,.xlsx,.png,.jpg,.jpeg,.zip" disabled={busy} onChange={selectFiles} aria-describedby="submission-files-help submission-files-errors" aria-invalid={Object.entries(errors).some(([key, value]) => key.startsWith('files') && value)} /><p id="submission-files-help" className="internship-help">Deri në 5 dokumente, 10 MB secili. PDF, DOCX, XLSX, PNG, JPG ose ZIP. {resubmission && 'Dokumentet e mëparshme ruhen në versionin e tyre; zgjidhni dokumentet e reja veçmas.'}</p><div id="submission-files-errors">{Object.entries(errors).filter(([key, value]) => key.startsWith('files') && value).map(([key, value]) => <p className="field-error" role="alert" key={key}>{value}</p>)}</div><ul className="task-selected-files">{files.map((file, index) => <li key={index}><span>{file.name} — {fileSize(file.size)}</span><button type="button" className="admin-button" disabled={busy} aria-label={`Hiq ${file.name}`} onClick={() => { setFiles((value) => value.filter((_, position) => position !== index)); setErrors((value) => Object.fromEntries(Object.entries(value).filter(([key]) => !key.startsWith('files')))) }}>Hiq</button></li>)}</ul></div><p className="internship-help">Vetëm lidhje HTTPS. Pas dorëzimit, puna është vetëm për lexim dhe pret shqyrtimin e mbikëqyrësit.</p><div className="admin-dialog-actions">{resubmission && <button className="admin-button" type="button" disabled={busy} onClick={() => { setText(resubmission.submission_text ?? ''); setUrl(resubmission.resource_url ?? ''); setErrors({}); setError(''); setFiles([]); setFormOpen(false) }}>Anulo korrigjimin</button>}<button className="admin-button admin-primary" disabled={busy}>{resubmission ? 'Ridorëzo punën' : 'Dorëzo punën'}</button></div></form>}
    {confirming && <dialog ref={dialog} className="admin-dialog task-submission-dialog" aria-labelledby="submission-confirm-title" aria-describedby="submission-confirm-description" onCancel={(event) => { event.preventDefault(); if (!pending.current) setConfirming(false) }}><h2 id="submission-confirm-title">{resubmission ? 'Konfirmoni ridorëzimin?' : 'Konfirmoni dorëzimin?'}</h2><p id="submission-confirm-description">{resubmission ? 'Krijohet një version i ri për shqyrtim. Versionet dhe komentet e mëparshme ruhen pa ndryshime.' : 'Puna do t’i dorëzohet mbikëqyrësit dhe nuk mund të ndryshohet ndërsa pret shqyrtimin.'}</p><p className="task-description">{text.trim()}</p>{url.trim() && <p className="task-description">{url.trim()}</p>}{files.length > 0 && <ul className="task-selected-files">{files.map((file, index) => <li key={index}>{file.name} — {fileSize(file.size)}</li>)}</ul>}{busy && progress !== null && <p role="status">Ngarkimi: {progress}%{progress === 100 && ' — duke ruajtur dorëzimin…'}</p>}<div className="admin-dialog-actions"><button className="admin-button" autoFocus disabled={busy} onClick={() => setConfirming(false)}>Anulo</button><button className="admin-button admin-primary" disabled={busy} onClick={() => perform(true)}>{busy ? 'Duke dorëzuar…' : resubmission ? 'Konfirmo ridorëzimin' : 'Konfirmo dorëzimin'}</button></div></dialog>}
  </section>
}
