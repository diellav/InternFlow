import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { normalizeApiError } from '../../../shared/api/apiError'
import { getSupervisorRegistrationCompanies, registerSupervisor } from '../api/authApi'
import { COMPANY_REGISTRATION_MODES } from '../constants/authConstants'
import '../styles/auth.css'

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const INITIAL_VALUES = {
  first_name: '', last_name: '', email: '', password: '', password_confirmation: '',
  phone: '', job_title: '', company_mode: COMPANY_REGISTRATION_MODES.EXISTING,
  company_id: '', company_name: '', company_industry: '', company_address: '',
  company_email: '', company_phone: '', company_website: '',
}

const FIELD_LABELS = {
  first_name: 'Emri', last_name: 'Mbiemri', email: 'Email', password: 'Fjalëkalimi',
  password_confirmation: 'Konfirmimi i fjalëkalimit', phone: 'Telefoni',
  job_title: 'Pozita e punës', company_mode: 'Lloji i kompanisë', company_id: 'Kompania',
  company_name: 'Emri i kompanisë', company_industry: 'Industria', company_address: 'Adresa',
  company_email: 'Email-i i kompanisë', company_phone: 'Telefoni i kompanisë',
  company_website: 'Faqja e internetit',
}

function validate(values) {
  const errors = {}
  for (const field of ['first_name', 'last_name', 'email', 'password', 'password_confirmation']) {
    if (!values[field].trim()) errors[field] = `${FIELD_LABELS[field]} është i detyrueshëm.`
  }
  if (values.email.trim() && !EMAIL_PATTERN.test(values.email.trim())) errors.email = 'Shkruani një email të vlefshëm.'
  if (values.password && (values.password.length < 8 || !/[A-Za-z]/.test(values.password) || !/\d/.test(values.password))) {
    errors.password = 'Fjalëkalimi duhet të ketë të paktën 8 karaktere, një shkronjë dhe një numër.'
  }
  if (values.password_confirmation && values.password !== values.password_confirmation) {
    errors.password_confirmation = 'Fjalëkalimet nuk përputhen.'
  }
  if (values.company_mode === COMPANY_REGISTRATION_MODES.EXISTING && !values.company_id) {
    errors.company_id = 'Zgjidhni një kompani.'
  }
  if (values.company_mode === COMPANY_REGISTRATION_MODES.NEW && !values.company_name.trim()) {
    errors.company_name = 'Emri i kompanisë është i detyrueshëm.'
  }
  if (values.company_email.trim() && !EMAIL_PATTERN.test(values.company_email.trim())) {
    errors.company_email = 'Shkruani një email të vlefshëm për kompaninë.'
  }
  if (values.company_website.trim() && !/^https?:\/\/.+/i.test(values.company_website.trim())) {
    errors.company_website = 'Adresa duhet të fillojë me http:// ose https://.'
  }
  return errors
}

function backendFieldErrors(validationErrors) {
  const mapped = {}
  for (const field of Object.keys(validationErrors)) {
    if (!(field in FIELD_LABELS)) continue
    const message = validationErrors[field]?.[0] ?? ''
    if (field === 'email' && message.includes('taken')) mapped.email = 'Ky email është tashmë në përdorim.'
    else if (field === 'company_id') mapped.company_id = 'Kompania e zgjedhur nuk është e vlefshme ose nuk mund të përdoret.'
    else if (field === 'company_name' && message.includes('already exists')) mapped.company_name = 'Një kompani me këtë emër ekziston. Zgjidhni kompaninë ekzistuese.'
    else if (field === 'company_website' && message.includes('already exists')) mapped.company_website = 'Një kompani me këtë faqe ekziston. Zgjidhni kompaninë ekzistuese.'
    else if (field === 'password') mapped.password = 'Fjalëkalimi nuk i plotëson kërkesat ose konfirmimi nuk përputhet.'
    else mapped[field] = `${FIELD_LABELS[field]} nuk është i vlefshëm.`
  }
  return mapped
}

function requestErrorMessage(error) {
  if (error.type === 'csrf') return 'Sesioni nuk mund të verifikohej. Rifreskoni faqen dhe provoni përsëri.'
  if (error.type === 'rate_limited') return 'Janë bërë shumë tentativa për regjistrim. Ju lutem provoni përsëri pas pak.'
  if (['server', 'network', 'unexpected'].includes(error.type)) return 'Nuk ishte e mundur të lidhej me serverin. Ju lutem provoni përsëri.'
  return 'Regjistrimi nuk mund të përfundohej. Kontrolloni të dhënat dhe provoni përsëri.'
}

function buildPayload(values) {
  const payload = {
    first_name: values.first_name.trim(), last_name: values.last_name.trim(),
    email: values.email.trim(), password: values.password,
    password_confirmation: values.password_confirmation, company_mode: values.company_mode,
  }
  if (values.phone.trim()) payload.phone = values.phone.trim()
  if (values.job_title.trim()) payload.job_title = values.job_title.trim()

  if (values.company_mode === COMPANY_REGISTRATION_MODES.EXISTING) {
    payload.company_id = Number(values.company_id)
  } else {
    payload.company_name = values.company_name.trim()
    for (const field of ['company_industry', 'company_address', 'company_email', 'company_phone', 'company_website']) {
      if (values[field].trim()) payload[field] = values[field].trim()
    }
  }
  return payload
}

function Field({ name, label, values, errors, onChange, type = 'text', autoComplete = 'off', wide = false, children }) {
  const describedBy = errors[name] ? `${name}-error` : undefined
  return (
    <div className={`form-field${wide ? ' form-field-wide' : ''}`}>
      <label htmlFor={name}>{label}</label>
      {children ?? <input id={name} name={name} type={type} autoComplete={autoComplete} value={values[name]} onChange={onChange} aria-invalid={Boolean(errors[name])} aria-describedby={describedBy} />}
      {errors[name] ? <span className="field-error" id={`${name}-error`}>{errors[name]}</span> : null}
    </div>
  )
}

export function SupervisorRegistrationPage() {
  const navigate = useNavigate()
  const errorRef = useRef(null)
  const companyLoadStartedRef = useRef(false)
  const [values, setValues] = useState(INITIAL_VALUES)
  const [fieldErrors, setFieldErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [companies, setCompanies] = useState([])
  const [companyLoadState, setCompanyLoadState] = useState('loading')
  const [isSubmitting, setIsSubmitting] = useState(false)

  const loadCompanies = useCallback(async () => {
    setCompanyLoadState('loading')
    try {
      setCompanies(await getSupervisorRegistrationCompanies())
      setCompanyLoadState('ready')
    } catch {
      setCompanies([])
      setCompanyLoadState('error')
    }
  }, [])

  useEffect(() => {
    if (!companyLoadStartedRef.current) {
      companyLoadStartedRef.current = true
      queueMicrotask(loadCompanies)
    }
  }, [loadCompanies])
  useEffect(() => { if (formError) errorRef.current?.focus() }, [formError])

  function updateField(event) {
    const { name, value } = event.target
    setValues((current) => ({ ...current, [name]: value }))
    setFieldErrors((current) => ({ ...current, [name]: undefined }))
    setFormError('')
  }

  function updateMode(event) {
    const company_mode = event.target.value
    setValues((current) => ({ ...current, company_mode }))
    setFieldErrors((current) => ({
      ...current, company_mode: undefined, company_id: undefined, company_name: undefined,
      company_industry: undefined, company_address: undefined, company_email: undefined,
      company_phone: undefined, company_website: undefined,
    }))
    setFormError('')
  }

  async function handleSubmit(event) {
    event.preventDefault()
    const errors = validate(values)
    setFieldErrors(errors)
    setFormError('')
    if (Object.keys(errors).length) return
    setIsSubmitting(true)
    try {
      await registerSupervisor(buildPayload(values))
      navigate('/login', { replace: true, state: {
        registrationSuccess: 'Llogaria u krijua me sukses. Regjistrimi juaj është në pritje të verifikimit administrativ. Mund të kyçeni për të parë statusin e llogarisë.',
      } })
    } catch (requestError) {
      const error = normalizeApiError(requestError)
      if (error.type === 'validation') setFieldErrors(backendFieldErrors(error.validationErrors))
      setFormError(requestErrorMessage(error))
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="auth-shell">
      <section className="auth-card registration-card" aria-labelledby="supervisor-registration-heading">
        <div className="auth-brand">InternFlow</div>
        <h1 id="supervisor-registration-heading">Regjistrimi i mbikëqyrësit</h1>
        <p className="auth-intro">Krijoni llogarinë dhe lidhni kompaninë tuaj me InternFlow.</p>
        {formError ? <div className="form-alert" role="alert" tabIndex="-1" ref={errorRef}>{formError}</div> : null}

        <form className="auth-form" onSubmit={handleSubmit} noValidate>
          <fieldset className="form-section" disabled={isSubmitting}>
            <legend>Të dhënat personale dhe profesionale</legend>
            <div className="registration-grid">
              <Field name="first_name" label="Emri" values={values} errors={fieldErrors} onChange={updateField} autoComplete="given-name" />
              <Field name="last_name" label="Mbiemri" values={values} errors={fieldErrors} onChange={updateField} autoComplete="family-name" />
              <Field name="phone" label="Telefoni (opsional)" values={values} errors={fieldErrors} onChange={updateField} type="tel" autoComplete="tel" />
              <Field name="job_title" label="Pozita e punës (opsionale)" values={values} errors={fieldErrors} onChange={updateField} autoComplete="organization-title" />
            </div>
          </fieldset>

          <fieldset className="form-section" disabled={isSubmitting}>
            <legend>Të dhënat e llogarisë</legend>
            <div className="registration-grid">
              <Field name="email" label="Email" values={values} errors={fieldErrors} onChange={updateField} type="email" autoComplete="email" wide />
              <Field name="password" label="Fjalëkalimi" values={values} errors={fieldErrors} onChange={updateField} type="password" autoComplete="new-password">
                <input id="password" name="password" type="password" autoComplete="new-password" value={values.password} onChange={updateField} aria-invalid={Boolean(fieldErrors.password)} aria-describedby="supervisor-password-hint password-error" />
                <span className="field-hint" id="supervisor-password-hint">Të paktën 8 karaktere, një shkronjë dhe një numër.</span>
              </Field>
              <Field name="password_confirmation" label="Konfirmo fjalëkalimin" values={values} errors={fieldErrors} onChange={updateField} type="password" autoComplete="new-password" />
            </div>
          </fieldset>

          <fieldset className="form-section" disabled={isSubmitting}>
            <legend>Kompania</legend>
            <div className="company-mode-options">
              <label className="company-mode-option"><input type="radio" name="company_mode" value="existing" checked={values.company_mode === 'existing'} onChange={updateMode} />Kompania ime është e regjistruar në sistem</label>
              <label className="company-mode-option"><input type="radio" name="company_mode" value="new" checked={values.company_mode === 'new'} onChange={updateMode} />Kompania ime nuk është ende e regjistruar</label>
            </div>

            {values.company_mode === COMPANY_REGISTRATION_MODES.EXISTING ? (
              <div className="form-field form-field-wide">
                <label htmlFor="company_id">Kompania</label>
                {companyLoadState === 'loading' ? <p className="company-help" role="status">Duke ngarkuar kompanitë…</p> : null}
                {companyLoadState === 'error' ? <p className="form-alert">Kompanitë nuk mund të ngarkoheshin. <button className="inline-button" type="button" onClick={loadCompanies}>Provo përsëri</button></p> : null}
                {companyLoadState === 'ready' ? (
                  <select id="company_id" name="company_id" value={values.company_id} onChange={updateField} aria-invalid={Boolean(fieldErrors.company_id)} aria-describedby={fieldErrors.company_id ? 'company_id-error' : undefined}>
                    <option value="">{companies.length ? 'Zgjidhni kompaninë' : 'Nuk ka kompani të disponueshme'}</option>
                    {companies.map((company) => <option value={company.id} key={company.id}>{company.name}</option>)}
                  </select>
                ) : null}
                {fieldErrors.company_id ? <span className="field-error" id="company_id-error">{fieldErrors.company_id}</span> : null}
                {!companies.length && companyLoadState === 'ready' ? <p className="company-help">Kaloni te opsioni i kompanisë së re për të vazhduar.</p> : null}
              </div>
            ) : (
              <div className="registration-grid">
                <Field name="company_name" label="Emri i kompanisë" values={values} errors={fieldErrors} onChange={updateField} wide />
                <Field name="company_industry" label="Industria (opsionale)" values={values} errors={fieldErrors} onChange={updateField} />
                <Field name="company_email" label="Email-i i kompanisë (opsional)" values={values} errors={fieldErrors} onChange={updateField} type="email" />
                <Field name="company_phone" label="Telefoni i kompanisë (opsional)" values={values} errors={fieldErrors} onChange={updateField} type="tel" />
                <Field name="company_website" label="Faqja e internetit (opsionale)" values={values} errors={fieldErrors} onChange={updateField} type="url" wide />
                <Field name="company_address" label="Adresa (opsionale)" values={values} errors={fieldErrors} onChange={updateField} wide>
                  <textarea id="company_address" name="company_address" value={values.company_address} onChange={updateField} aria-invalid={Boolean(fieldErrors.company_address)} aria-describedby={fieldErrors.company_address ? 'company_address-error' : undefined} />
                </Field>
              </div>
            )}
          </fieldset>

          <button className="primary-button" type="submit" disabled={isSubmitting || (values.company_mode === 'existing' && companyLoadState !== 'ready')}>
            {isSubmitting ? 'Duke u regjistruar…' : 'Regjistrohu'}
          </button>
        </form>
        <p className="auth-switch">Ke tashmë llogari? <Link to="/login">Kthehu te kyçja</Link></p>
      </section>
    </main>
  )
}
