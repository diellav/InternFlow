import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { normalizeApiError } from '../../../shared/api/apiError'
import { registerStudent } from '../api/authApi'
import '../styles/auth.css'

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const INITIAL_VALUES = {
  first_name: '',
  last_name: '',
  email: '',
  password: '',
  password_confirmation: '',
  phone: '',
  student_number: '',
  study_program: '',
  study_year: '',
}

const FIELD_LABELS = {
  first_name: 'Emri',
  last_name: 'Mbiemri',
  email: 'Email',
  password: 'Fjalëkalimi',
  password_confirmation: 'Konfirmimi i fjalëkalimit',
  phone: 'Telefoni',
  student_number: 'Numri i studentit',
  study_program: 'Programi i studimit',
  study_year: 'Viti i studimit',
}

function validate(values) {
  const errors = {}

  for (const field of ['first_name', 'last_name', 'email', 'password', 'password_confirmation', 'student_number', 'study_program']) {
    if (!values[field].trim()) {
      errors[field] = `${FIELD_LABELS[field]} është i detyrueshëm.`
    }
  }

  if (values.email.trim() && !EMAIL_PATTERN.test(values.email.trim())) {
    errors.email = 'Shkruani një email të vlefshëm.'
  }

  if (values.password && (values.password.length < 8 || !/[A-Za-z]/.test(values.password) || !/\d/.test(values.password))) {
    errors.password = 'Fjalëkalimi duhet të ketë të paktën 8 karaktere, një shkronjë dhe një numër.'
  }

  if (values.password_confirmation && values.password !== values.password_confirmation) {
    errors.password_confirmation = 'Fjalëkalimet nuk përputhen.'
  }

  if (values.study_year && (!/^\d+$/.test(values.study_year) || Number(values.study_year) < 1)) {
    errors.study_year = 'Viti i studimit duhet të jetë numër pozitiv.'
  }

  return errors
}

function backendFieldErrors(validationErrors) {
  const mapped = {}

  for (const field of Object.keys(validationErrors)) {
    if (!(field in FIELD_LABELS)) continue

    const message = validationErrors[field]?.[0] ?? ''

    if (field === 'email' && message.includes('taken')) {
      mapped.email = 'Ky email është tashmë në përdorim.'
    } else if (field === 'student_number' && message.includes('taken')) {
      mapped.student_number = 'Ky numër studenti është tashmë në përdorim.'
    } else if (field === 'password') {
      mapped.password = 'Fjalëkalimi nuk i plotëson kërkesat ose konfirmimi nuk përputhet.'
    } else {
      mapped[field] = `${FIELD_LABELS[field]} nuk është i vlefshëm.`
    }
  }

  return mapped
}

function registrationErrorMessage(error) {
  switch (error.type) {
    case 'csrf':
      return 'Sesioni nuk mund të verifikohej. Rifreskoni faqen dhe provoni përsëri.'
    case 'rate_limited':
      return 'Janë bërë shumë tentativa për regjistrim. Ju lutem provoni përsëri pas pak.'
    case 'server':
    case 'network':
    case 'unexpected':
      return 'Nuk ishte e mundur të lidhej me serverin. Ju lutem provoni përsëri.'
    default:
      return 'Regjistrimi nuk mund të përfundohej. Kontrolloni të dhënat dhe provoni përsëri.'
  }
}

function buildPayload(values) {
  const payload = {
    first_name: values.first_name.trim(),
    last_name: values.last_name.trim(),
    email: values.email.trim(),
    password: values.password,
    password_confirmation: values.password_confirmation,
    student_number: values.student_number.trim(),
    study_program: values.study_program.trim(),
  }

  if (values.phone.trim()) payload.phone = values.phone.trim()
  if (values.study_year) payload.study_year = Number(values.study_year)

  return payload
}

export function StudentRegistrationPage() {
  const navigate = useNavigate()
  const errorRef = useRef(null)
  const [values, setValues] = useState(INITIAL_VALUES)
  const [fieldErrors, setFieldErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)

  useEffect(() => {
    if (formError) errorRef.current?.focus()
  }, [formError])

  function updateField(event) {
    const { name, value } = event.target
    setValues((current) => ({ ...current, [name]: value }))
    setFieldErrors((current) => ({ ...current, [name]: undefined }))
    setFormError('')
  }

  async function handleSubmit(event) {
    event.preventDefault()
    const validationErrors = validate(values)
    setFieldErrors(validationErrors)
    setFormError('')

    if (Object.keys(validationErrors).length > 0) return

    setIsSubmitting(true)

    try {
      await registerStudent(buildPayload(values))
      navigate('/login', {
        replace: true,
        state: { registrationSuccess: 'Llogaria u krijua me sukses. Tani mund të kyçeni.' },
      })
    } catch (requestError) {
      const error = normalizeApiError(requestError)

      if (error.type === 'validation') {
        setFieldErrors(backendFieldErrors(error.validationErrors))
      }

      setFormError(registrationErrorMessage(error))
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="auth-shell">
      <section className="auth-card registration-card" aria-labelledby="registration-heading">
        <div className="auth-brand">InternFlow</div>
        <h1 id="registration-heading">Krijo llogari studenti</h1>
        <p className="auth-intro">Plotëso të dhënat për t’u regjistruar në InternFlow.</p>

        {formError ? (
          <div className="form-alert" role="alert" tabIndex="-1" ref={errorRef}>
            {formError}
          </div>
        ) : null}

        <form className="auth-form" onSubmit={handleSubmit} noValidate>
          <div className="registration-grid">
            {[
              ['first_name', 'Emri', 'text', 'given-name'],
              ['last_name', 'Mbiemri', 'text', 'family-name'],
              ['email', 'Email', 'email', 'email'],
              ['phone', 'Telefoni (opsional)', 'tel', 'tel'],
              ['student_number', 'Numri i studentit', 'text', 'off'],
              ['study_program', 'Programi i studimit', 'text', 'organization-title'],
              ['study_year', 'Viti i studimit (opsional)', 'number', 'off'],
            ].map(([name, label, type, autoComplete]) => (
              <div className="form-field" key={name}>
                <label htmlFor={name}>{label}</label>
                <input
                  id={name}
                  name={name}
                  type={type}
                  min={name === 'study_year' ? '1' : undefined}
                  autoComplete={autoComplete}
                  value={values[name]}
                  onChange={updateField}
                  aria-invalid={Boolean(fieldErrors[name])}
                  aria-describedby={fieldErrors[name] ? `${name}-error` : undefined}
                  disabled={isSubmitting}
                  autoFocus={name === 'first_name'}
                />
                {fieldErrors[name] ? <span className="field-error" id={`${name}-error`}>{fieldErrors[name]}</span> : null}
              </div>
            ))}

            <div className="form-field">
              <label htmlFor="password">Fjalëkalimi</label>
              <input id="password" name="password" type="password" autoComplete="new-password" value={values.password} onChange={updateField} aria-invalid={Boolean(fieldErrors.password)} aria-describedby="password-hint password-error" disabled={isSubmitting} />
              <span className="field-hint" id="password-hint">Të paktën 8 karaktere, një shkronjë dhe një numër.</span>
              {fieldErrors.password ? <span className="field-error" id="password-error">{fieldErrors.password}</span> : null}
            </div>

            <div className="form-field">
              <label htmlFor="password_confirmation">Konfirmo fjalëkalimin</label>
              <input id="password_confirmation" name="password_confirmation" type="password" autoComplete="new-password" value={values.password_confirmation} onChange={updateField} aria-invalid={Boolean(fieldErrors.password_confirmation)} aria-describedby={fieldErrors.password_confirmation ? 'password_confirmation-error' : undefined} disabled={isSubmitting} />
              {fieldErrors.password_confirmation ? <span className="field-error" id="password_confirmation-error">{fieldErrors.password_confirmation}</span> : null}
            </div>
          </div>

          <button className="primary-button" type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Duke u regjistruar…' : 'Regjistrohu'}
          </button>
        </form>

        <p className="auth-switch">Ke tashmë llogari? <Link to="/login">Kthehu te kyçja</Link></p>
      </section>
    </main>
  )
}
