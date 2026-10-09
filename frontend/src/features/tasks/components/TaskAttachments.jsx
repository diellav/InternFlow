import { useRef, useState } from 'react'
import { useAuth } from '../../auth/hooks/useAuth'
import { downloadTaskFile, taskErrorMessage, taskFileUrl } from '../api/tasksApi'
import { fileSize } from '../utils/fileSize'

export function TaskAttachments({ files = [] }) {
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(null)
  const pending = useRef(false)
  const { refreshUser } = useAuth()
  if (!files.length) return null
  async function download(event, file) {
    event.preventDefault()
    if (pending.current) return
    pending.current = true; setBusy(file.id); setError('')
    try { await downloadTaskFile(file) }
    catch (failure) { setError(taskErrorMessage(failure)); if ([401, 403].includes(failure.status)) await refreshUser() }
    finally { pending.current = false; setBusy(null) }
  }
  return <section className="task-attachments" aria-label="Dokumentet e dorëzimit"><h4>Dokumentet</h4><ul>{files.map((file) => <li key={file.id}><span aria-hidden="true">{file.mime_type?.startsWith('image/') ? '▧' : file.original_name?.toLowerCase().endsWith('.zip') ? '▣' : '▤'}</span><a href={taskFileUrl(file.id)} aria-disabled={busy !== null} onClick={(event) => download(event, file)}>{file.original_name}</a><span>{fileSize(file.size_bytes)}</span>{busy === file.id && <span role="status">Duke shkarkuar…</span>}</li>)}</ul>{error && <p role="alert" className="form-alert">{error}</p>}</section>
}
