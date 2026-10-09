import { useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../../auth/hooks/useAuth'
import { saveTask, taskErrorMessage } from '../api/tasksApi'
import { priorityLabels } from '../constants/taskLabels'

function valuesFor(task) { return { title: task?.title ?? '', description: task?.description ?? '', priority: task?.priority ?? 'MEDIUM', due_date: task?.due_date ?? '' } }

export function TaskForm({ task, internshipId }) {
  const original = valuesFor(task)
  const [values, setValues] = useState(() => valuesFor(task))
  const [errors, setErrors] = useState({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const pending = useRef(false)
  const { refreshUser } = useAuth()
  const navigate = useNavigate()
  const back = task ? `/supervisor/tasks/${task.id}` : `/supervisor/internships/${internshipId}`
  const dirty = Object.keys(values).some((key) => values[key] !== original[key])
  function change(event) {
    const { name, value } = event.target
    setValues((current) => ({ ...current, [name]: value })); setErrors((current) => ({ ...current, [name]: undefined })); setError('')
  }
  async function save(event) {
    event.preventDefault()
    if (pending.current) return
    const invalid = {}
    for (const field of ['title', 'description']) if (!values[field].trim()) invalid[field] = 'Kjo fushë është e detyrueshme.'
    if (values.title.trim().length > 255) invalid.title = 'Maksimumi 255 karaktere.'
    if (values.description.trim().length > 10000) invalid.description = 'Maksimumi 10,000 karaktere.'
    setErrors(invalid); setError('')
    if (Object.keys(invalid).length) { document.getElementById(`task-${Object.keys(invalid)[0]}`)?.focus(); return }
    pending.current = true; setBusy(true)
    try {
      const saved = await saveTask(internshipId, task?.id, { title: values.title.trim(), description: values.description.trim(), priority: values.priority, due_date: values.due_date || null })
      navigate(`/supervisor/tasks/${saved.id}`, { replace: true, state: { notice: task ? 'Detyra u përditësua me sukses.' : 'Detyra u krijua dhe iu caktua studentit.' } })
    } catch (failure) {
      if ([404, 409].includes(failure.status)) navigate(back, { replace: true, state: { conflict: taskErrorMessage(failure) } })
      else {
        setError(taskErrorMessage(failure)); setErrors(Object.fromEntries(Object.entries(failure.validationErrors ?? {}).map(([field, messages]) => [field, messages[0]])))
        if ([401, 403].includes(failure.status)) await refreshUser()
      }
    } finally { pending.current = false; setBusy(false) }
  }
  function attributes(field) { return { id: `task-${field}`, name: field, value: values[field], onChange: change, disabled: busy, 'aria-invalid': Boolean(errors[field]), 'aria-describedby': errors[field] ? `task-${field}-error` : undefined } }
  function fieldError(field) { return errors[field] && <span className="field-error" id={`task-${field}-error`}>{errors[field]}</span> }
  return <form className="internship-panel internship-form" onSubmit={save} noValidate>{error && <p className="form-alert" role="alert">{error}</p>}<div className="form-field"><label htmlFor="task-title">Titulli i detyrës *</label><input {...attributes('title')} required maxLength={255} />{fieldError('title')}</div><div className="form-field"><label htmlFor="task-description">Përshkrimi i detyrës *</label><textarea {...attributes('description')} rows={7} required maxLength={10000} />{fieldError('description')}</div><div className="internship-fields"><div className="form-field"><label htmlFor="task-priority">Prioriteti *</label><select {...attributes('priority')} required>{Object.entries(priorityLabels).map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select>{fieldError('priority')}</div><div className="form-field"><label htmlFor="task-due_date">Afati (opsional)</label><input {...attributes('due_date')} type="date" />{fieldError('due_date')}</div></div><p className="internship-help">Detyra fillon me statusin “E caktuar”. Ky formular nuk ndryshon statusin dhe nuk krijon dorëzime ose komente vlerësimi.</p><div className="internship-form-actions"><Link className="admin-button" to={back} aria-disabled={busy} onClick={(event) => { if (pending.current) event.preventDefault() }}>Anulo</Link><button className="admin-button" type="button" disabled={busy || !dirty} onClick={() => { setValues(original); setErrors({}); setError('') }}>Rivendos fushat</button><button className="admin-button admin-primary" disabled={busy || (Boolean(task) && !dirty)}>{busy ? 'Duke ruajtur…' : task ? 'Ruaj detyrën' : 'Krijo detyrën'}</button></div></form>
}
