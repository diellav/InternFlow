import { useEffect, useRef, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import '../styles/auth.css'

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function validate(values) {
  const errors = {}

  if (!values.email.trim()) {
    errors.email = 'Email-i është i detyrueshëm.'
  } else if (!EMAIL_PATTERN.test(values.email.trim())) {
    errors.email = 'Shkruani një email të vlefshëm.'
  }

  if (!values.password) {
    errors.password = 'Fjalëkalimi është i detyrueshëm.'
  }

  return errors
}

function loginErrorMessage(error) {
  switch (error.type) {
    case 'forbidden':
      return 'Llogaria juaj nuk është aktive ose qasja nuk është e disponueshme.'
    case 'csrf':
      return 'Sesioni nuk mund të verifikohej. Ju lutem rifreskoni faqen dhe provoni përsëri.'
    case 'rate_limited':
      return 'Janë bërë shumë tentativa për kyçje. Ju lutem provoni përsëri pas pak.'
    case 'server':
    case 'network':
    case 'unexpected':
      return 'Nuk ishte e mundur të lidhej me serverin. Ju lutem provoni përsëri.'
    default:
      return 'Email-i ose fjalëkalimi nuk është i saktë.'
  }
}

export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const errorRef = useRef(null)
  const [values, setValues] = useState({ email: '', password: '' })
  const [fieldErrors, setFieldErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [successMessage] = useState(() => location.state?.registrationSuccess ?? '')

  useEffect(() => {
    if (location.state?.registrationSuccess) {
      navigate('/login', { replace: true, state: null })
    }
  }, [location.state, navigate])

  useEffect(() => {
    if (formError) {
      errorRef.current?.focus()
    }
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

    if (Object.keys(validationErrors).length > 0) {
      return
    }

    setIsSubmitting(true)

    try {
      await login({ email: values.email.trim(), password: values.password })
      const requestedPath = location.state?.from?.pathname
      navigate(requestedPath && requestedPath !== '/login' ? requestedPath : '/session', {
        replace: true,
      })
    } catch (error) {
      if (error.type === 'validation' && error.validationErrors?.password) {
        setFieldErrors({ password: 'Fjalëkalimi nuk është i vlefshëm.' })
      }

      setFormError(loginErrorMessage(error))
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="auth-shell">
      <section className="auth-card" aria-labelledby="login-heading">
        <div className="auth-brand">InternFlow</div>
        <h1 id="login-heading">Kyçu në llogarinë tënde</h1>
        <p className="auth-intro">
          Përdor email-in dhe fjalëkalimin e llogarisë InternFlow.
        </p>

        {successMessage ? (
          <div className="form-success" role="status">
            {successMessage}
          </div>
        ) : null}

        {formError ? (
          <div className="form-alert" role="alert" tabIndex="-1" ref={errorRef}>
            {formError}
          </div>
        ) : null}

        <form className="auth-form" onSubmit={handleSubmit} noValidate>
          <div className="form-field">
            <label htmlFor="email">Email</label>
            <input
              id="email"
              name="email"
              type="email"
              autoComplete="email"
              value={values.email}
              onChange={updateField}
              aria-invalid={Boolean(fieldErrors.email)}
              aria-describedby={fieldErrors.email ? 'email-error' : undefined}
              disabled={isSubmitting}
              autoFocus
            />
            {fieldErrors.email ? (
              <span className="field-error" id="email-error">
                {fieldErrors.email}
              </span>
            ) : null}
          </div>

          <div className="form-field">
            <label htmlFor="password">Fjalëkalimi</label>
            <input
              id="password"
              name="password"
              type="password"
              autoComplete="current-password"
              value={values.password}
              onChange={updateField}
              aria-invalid={Boolean(fieldErrors.password)}
              aria-describedby={fieldErrors.password ? 'password-error' : undefined}
              disabled={isSubmitting}
            />
            {fieldErrors.password ? (
              <span className="field-error" id="password-error">
                {fieldErrors.password}
              </span>
            ) : null}
          </div>

          <button className="primary-button" type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Duke u kyçur…' : 'Kyçu'}
          </button>
        </form>

        <nav className="registration-links" aria-label="Opsionet e regjistrimit">
          <span>Nuk ke llogari?</span>
          <Link to="/register/student">Regjistrohu si student</Link>
          <Link to="/register/supervisor">Regjistrohu si mbikëqyrës i kompanisë</Link>
        </nav>
      </section>
    </main>
  )
}
