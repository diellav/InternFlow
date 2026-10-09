import { useRef, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../../auth/hooks/useAuth'
import { activityErrorMessage, saveActivity } from '../api/activitiesApi'

function initialValues(activity, internship) {
  const latest = internship.end_date < internship.server_date ? internship.end_date : internship.server_date
  return { title: activity?.title ?? '', description: activity?.description ?? '', activity_date: activity?.activity_date ?? (latest >= internship.start_date ? latest : ''), hours: activity?.hours ?? '' }
}

export function ActivityForm({ activity, internship }) {
  const [values, setValues] = useState(() => initialValues(activity, internship))
  const [errors, setErrors] = useState({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const pending = useRef(false)
  const navigate = useNavigate()
  const location = useLocation()
  const { refreshUser } = useAuth()
  const maxDate = internship.end_date < internship.server_date ? internship.end_date : internship.server_date
  const activityList = location.state?.activityList ?? `/student/activities?internship=${internship.id}`
  const back = activity ? `/student/activities/${activity.id}` : activityList
  function change(event) { const { name, value } = event.target; setValues((current) => ({ ...current, [name]: value })); setErrors((current) => ({ ...current, [name]: undefined })); setError('') }
  async function save(event) {
    event.preventDefault()
    if (pending.current) return
    const invalid = {}
    for (const field of ['title', 'description']) if (!values[field].trim()) invalid[field] = 'Kjo fushë është e detyrueshme.'
    if (values.title.trim().length > 255) invalid.title = 'Maksimumi 255 karaktere.'
    if (values.description.trim().length > 10000) invalid.description = 'Maksimumi 10,000 karaktere.'
    const parsedDate = new Date(`${values.activity_date}T00:00:00Z`)
    if (!/^\d{4}-\d{2}-\d{2}$/.test(values.activity_date) || Number.isNaN(parsedDate.getTime()) || parsedDate.toISOString().slice(0, 10) !== values.activity_date || values.activity_date < internship.start_date || values.activity_date > maxDate) invalid.activity_date = 'Zgjidhni një datë brenda praktikës, jo në të ardhmen.'
    if (values.hours !== '' && (!/^\d+(\.\d{1,2})?$/.test(String(values.hours)) || Number(values.hours) <= 0 || Number(values.hours) > 24)) invalid.hours = 'Vendosni 0.01–24 orë, me deri në dy shifra dhjetore.'
    setErrors(invalid); setError('')
    if (Object.keys(invalid).length) { document.getElementById(`activity-${Object.keys(invalid)[0]}`)?.focus(); return }
    pending.current = true; setBusy(true)
    try {
      const saved = await saveActivity(internship.id, activity?.id, { title: values.title.trim(), description: values.description.trim(), activity_date: values.activity_date, hours: values.hours === '' ? null : values.hours })
      navigate(`/student/activities/${saved.id}`, { replace: true, state: { notice: activity ? 'Aktiviteti u përditësua me sukses.' : 'Aktiviteti u regjistrua me sukses.', activityList } })
    } catch (failure) {
      if ([404, 409].includes(failure.status)) navigate(`/student/activities?internship=${internship.id}`, { replace: true, state: { conflict: activityErrorMessage(failure) } })
      else { setError(activityErrorMessage(failure)); setErrors(Object.fromEntries(Object.entries(failure.validationErrors ?? {}).map(([key, messages]) => [key, messages[0]]))); if ([401, 403].includes(failure.status)) await refreshUser() }
    } finally { pending.current = false; setBusy(false) }
  }
  function attrs(field) { return { id: `activity-${field}`, name: field, value: values[field], disabled: busy, onChange: change, 'aria-invalid': Boolean(errors[field]), 'aria-describedby': errors[field] ? `activity-${field}-error` : undefined } }
  function message(field) { return errors[field] && <span className="field-error" id={`activity-${field}-error`}>{errors[field]}</span> }
  return <form className="internship-panel internship-form" onSubmit={save} noValidate>{error && <p className="form-alert" role="alert">{error}</p>}<div className="form-field"><label htmlFor="activity-title">Titulli *</label><input {...attrs('title')} required maxLength={255} />{message('title')}</div><div className="form-field"><label htmlFor="activity-description">Puna e kryer *</label><textarea {...attrs('description')} required maxLength={10000} rows={7} />{message('description')}</div><div className="internship-fields"><div className="form-field"><label htmlFor="activity-activity_date">Data e aktivitetit *</label><input {...attrs('activity_date')} type="date" required min={internship.start_date} max={maxDate} />{message('activity_date')}</div><div className="form-field"><label htmlFor="activity-hours">Orët e punës (opsionale)</label><input {...attrs('hours')} type="number" step="0.01" min="0.01" max="24" />{message('hours')}</div></div><p className="internship-help">Periudha: {internship.start_date} — {internship.end_date}. Data e serverit: {internship.server_date}. Orët ruhen me dy shifra dhjetore.</p><div className="internship-form-actions"><Link className="admin-button" to={back} state={{ activityList }} onClick={(event) => { if (pending.current) event.preventDefault() }} aria-disabled={busy}>Anulo</Link><button className="admin-button admin-primary" disabled={busy}>{busy ? 'Duke ruajtur…' : activity ? 'Ruaj ndryshimet' : 'Regjistro aktivitetin'}</button></div></form>
}
