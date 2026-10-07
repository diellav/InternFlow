import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { errorMessage, getUser, saveCoordinator } from '../api/usersApi'
import { PageHeading } from '../components/UserUi'

const emptyValues = { first_name: '', last_name: '', email: '', phone: '', academic_unit: '', password: '' }
const fields = [
  ['first_name', 'Emri', 'text', 'given-name'], ['last_name', 'Mbiemri', 'text', 'family-name'],
  ['email', 'Email', 'email', 'email'], ['phone', 'Telefoni (opsional)', 'tel', 'tel'],
  ['academic_unit', 'Njësia akademike (opsionale)', 'text', 'organization'],
]

export function CoordinatorFormPage() {
  const { id } = useParams()
  const editing = Boolean(id)
  const navigate = useNavigate()
  const busy = useRef(false)
  const [values, setValues] = useState(emptyValues)
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [loadError, setLoadError] = useState('')
  const [loading, setLoading] = useState(editing)
  const [submitting, setSubmitting] = useState(false)
  const [revision, setRevision] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      if (controller.signal.aborted) return
      setErrors({})
      setFormError('')
      setLoadError('')
      if (!id) { setValues(emptyValues); setLoading(false); return }
      setLoading(true)
      getUser(id, controller.signal).then((user) => {
        if (controller.signal.aborted) return
        if (user.role !== 'ACADEMIC_COORDINATOR') { setLoadError('Kjo faqe është vetëm për koordinatorët akademikë.'); return }
        setValues({ first_name: user.first_name, last_name: user.last_name, email: user.email, phone: user.phone ?? '', academic_unit: user.profile?.academic_unit ?? '', password: '' })
      }).catch((error) => { if (!controller.signal.aborted) setLoadError(errorMessage(error)) }).finally(() => { if (!controller.signal.aborted) setLoading(false) })
    })
    return () => controller.abort()
  }, [id, revision])

  useEffect(() => {
    if (formError) document.getElementById('coordinator-form-error')?.focus()
  }, [formError])

  function change(event) {
    const { name, value } = event.target
    setValues((current) => ({ ...current, [name]: value }))
    setErrors((current) => ({ ...current, [name]: undefined }))
    setFormError('')
  }

  async function submit(event) {
    event.preventDefault()
    if (busy.current) return
    const nextErrors = {}
    for (const name of ['first_name', 'last_name', 'email']) if (!values[name].trim()) nextErrors[name] = 'Kjo fushë është e detyrueshme.'
    if (values.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email.trim())) nextErrors.email = 'Shkruani një email të vlefshëm.'
    if (!editing && (values.password.length < 8 || !/[a-z]/i.test(values.password) || !/[0-9]/.test(values.password))) nextErrors.password = 'Përdorni të paktën 8 karaktere, shkronja dhe numra.'
    setErrors(nextErrors)
    setFormError('')
    if (Object.keys(nextErrors).length) { document.getElementById(`coordinator-${Object.keys(nextErrors)[0]}`)?.focus(); return }
    busy.current = true
    setSubmitting(true)
    const payload = { first_name: values.first_name.trim(), last_name: values.last_name.trim(), email: values.email.trim(), phone: values.phone.trim() || null, academic_unit: values.academic_unit.trim() || null }
    if (!editing) payload.password = values.password
    try {
      const user = await saveCoordinator(id, payload)
      navigate(`/admin/users/${user.id}`, { replace: true, state: { success: editing ? 'Të dhënat e koordinatorit u përditësuan.' : 'Llogaria e koordinatorit u krijua me sukses.' } })
    } catch (error) {
      setErrors(Object.fromEntries(Object.entries(error.validationErrors ?? {}).map(([key, messages]) => [key, messages[0]])))
      setFormError(error.type === 'validation' ? 'Kontrolloni fushat dhe provoni përsëri.' : errorMessage(error))
    } finally { busy.current = false; setSubmitting(false) }
  }

  if (loading) return <p role="status">Duke ngarkuar koordinatorin…</p>
  if (loadError) return <div className="admin-panel admin-empty"><p role="alert">{loadError}</p><button className="admin-button" onClick={() => setRevision((value) => value + 1)}>Provo përsëri</button><Link to="/admin/users">Kthehu te përdoruesit</Link></div>
  const visibleFields = editing ? fields : [...fields, ['password', 'Fjalëkalimi fillestar', 'password', 'new-password']]
  return <>
    <Link className="admin-back-link" to={editing ? `/admin/users/${id}` : '/admin/users'}>← {editing ? 'Detajet e koordinatorit' : 'Përdoruesit'}</Link>
    <PageHeading eyebrow="Ekipi akademik" title={editing ? 'Ndrysho koordinatorin' : 'Krijo koordinator akademik'} description={editing ? 'Përditësoni të dhënat e llogarisë dhe njësinë akademike.' : 'Shtoni një anëtar të ekipit akademik në InternFlow.'} />
    <section className="admin-panel coordinator-panel"><div className="form-section-heading"><h2>Të dhënat e koordinatorit</h2><p>{editing ? 'Fushat e shënuara me * janë të detyrueshme.' : 'Llogaria krijohet aktive me rolin Koordinator akademik.'}</p></div>
      {formError && <p id="coordinator-form-error" tabIndex="-1" className="form-alert" role="alert">{formError}</p>}
      <form onSubmit={submit} noValidate><div className="coordinator-fields">{visibleFields.map(([name, label, type, autocomplete]) => <div className="form-field" key={name}><label htmlFor={`coordinator-${name}`}>{label}{['first_name', 'last_name', 'email', 'password'].includes(name) && ' *'}</label><input id={`coordinator-${name}`} name={name} type={type} autoComplete={autocomplete} maxLength={255} value={values[name]} onChange={change} disabled={submitting} required={['first_name', 'last_name', 'email', 'password'].includes(name)} aria-invalid={Boolean(errors[name])} aria-describedby={errors[name] ? `${name}-error` : name === 'password' ? 'password-help' : undefined} />{errors[name] && <span className="field-error" id={`${name}-error`}>{errors[name]}</span>}{name === 'password' && <small className="admin-muted" id="password-help">Të paktën 8 karaktere, shkronja dhe numra. Ndajeni në mënyrë të sigurt me koordinatorin.</small>}</div>)}</div><div className="coordinator-form-actions"><Link className="admin-button" to={editing ? `/admin/users/${id}` : '/admin/users'} aria-disabled={submitting} onClick={(event) => { if (submitting) event.preventDefault() }}>Anulo</Link><button className="admin-button admin-primary" type="submit" disabled={submitting}>{submitting ? 'Duke ruajtur…' : editing ? 'Ruaj ndryshimet' : 'Krijo koordinator'}</button></div></form>
    </section>
  </>
}
