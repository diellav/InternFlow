import { useEffect, useRef, useState } from 'react'
import { Link, NavLink, Outlet } from 'react-router-dom'
import { AdminLayout } from '../../../app/layouts/AdminLayout'
import { StudentLayout } from '../../../app/layouts/StudentLayout'
import { CoordinatorLayout } from '../../../app/layouts/CoordinatorLayout'
import { useAuth } from '../../auth/hooks/useAuth'
import { errorMessage, getProfile, updateProfile } from '../api/usersApi'
import { AccountBadge, PageHeading } from '../components/UserUi'
import { roleLabels } from '../constants/userLabels'
import '../styles/profile.css'

function editableValues(user) {
  return {
    first_name: user.first_name,
    last_name: user.last_name,
    phone: user.phone ?? '',
    ...(user.role === 'COMPANY_SUPERVISOR' && user.profile ? { job_title: user.profile.job_title ?? '' } : {}),
  }
}

function ReadOnlyField({ label, value }) {
  return <div className="profile-read-only"><dt>{label}</dt><dd>{value ?? 'Nuk është dhënë'}</dd></div>
}

export function ProfileLayout() {
  const { user } = useAuth()

  if (user.role === 'ADMIN') return <AdminLayout />
  if (user.role === 'STUDENT') return <StudentLayout />
  if (user.role === 'ACADEMIC_COORDINATOR') return <CoordinatorLayout />

  return <div className={`profile-shell${user.role === 'COMPANY_SUPERVISOR' ? ' supervisor-profile-shell' : ''}`}>
    <header className="profile-topbar">
      <Link to="/session" className="profile-brand">InternFlow</Link>
      <nav aria-label="Navigimi i llogarisë">
        <NavLink to="/session">Llogaria ime</NavLink>
        <NavLink to="/profile">Profili</NavLink>
        {user.role === 'COMPANY_SUPERVISOR' && <NavLink to="/supervisor/verification">Verifikimi</NavLink>}
      </nav>
    </header>
    <main className="profile-main"><Outlet /></main>
  </div>
}

export function ProfilePage() {
  const { refreshUser } = useAuth()
  const busy = useRef(false)
  const [profile, setProfile] = useState(null)
  const [values, setValues] = useState({})
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [errors, setErrors] = useState({})
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [revision, setRevision] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(async () => {
      if (controller.signal.aborted) return
      setLoading(true)
      setError('')
      try {
        const data = await getProfile(controller.signal)
        if (!controller.signal.aborted) { setProfile(data); setValues(editableValues(data)) }
      } catch (requestError) {
        if (controller.signal.aborted) return
        setError(errorMessage(requestError))
        if ([401, 403].includes(requestError.status)) await refreshUser()
      } finally {
        if (!controller.signal.aborted) setLoading(false)
      }
    })
    return () => controller.abort()
  }, [refreshUser, revision])

  useEffect(() => {
    if (error) document.getElementById('profile-error')?.focus()
  }, [error])

  function change(event) {
    const { name, value } = event.target
    setValues((current) => ({ ...current, [name]: value }))
    setErrors((current) => ({ ...current, [name]: undefined }))
    setError('')
    setNotice('')
  }

  function reset() {
    setValues(editableValues(profile))
    setErrors({})
    setError('')
    setNotice('')
  }

  async function save(event) {
    event.preventDefault()
    if (busy.current) return
    const fieldErrors = {}
    for (const field of ['first_name', 'last_name']) {
      if (!values[field]?.trim()) fieldErrors[field] = 'Kjo fushë është e detyrueshme.'
    }
    setErrors(fieldErrors)
    setError('')
    setNotice('')
    if (Object.keys(fieldErrors).length) {
      document.getElementById(`profile-${Object.keys(fieldErrors)[0]}`)?.focus()
      return
    }

    const original = editableValues(profile)
    const payload = Object.fromEntries(Object.entries(values)
      .filter(([field, value]) => value !== original[field])
      .map(([field, value]) => [field, ['phone', 'job_title'].includes(field) ? value.trim() || null : value.trim()]))
    if (!Object.keys(payload).length) return
    busy.current = true
    setSubmitting(true)
    try {
      const updated = await updateProfile(payload)
      setProfile(updated)
      setValues(editableValues(updated))
      const refreshed = await refreshUser()
      if (refreshed) setNotice('Profili u përditësua me sukses.')
      else setError('Profili u ruajt, por sesioni nuk mund të rifreskohej. Kyçuni përsëri.')
    } catch (requestError) {
      setErrors(Object.fromEntries(Object.entries(requestError.validationErrors ?? {}).map(([field, messages]) => [field, messages[0]])))
      setError(requestError.type === 'validation' ? 'Kontrolloni fushat dhe provoni përsëri.' : errorMessage(requestError))
      if ([401, 403].includes(requestError.status)) await refreshUser()
    } finally {
      busy.current = false
      setSubmitting(false)
    }
  }

  if (loading) return <p className="profile-loading" role="status">Duke ngarkuar profilin…</p>
  if (!profile) return <section className="admin-panel admin-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRevision((current) => current + 1)}>Provo përsëri</button></section>

  const original = editableValues(profile)
  const dirty = Object.keys(values).some((field) => values[field] !== original[field])
  const personalFields = [['first_name', 'Emri'], ['last_name', 'Mbiemri'], ['phone', 'Telefoni (opsional)']]
  if ('job_title' in values) personalFields.push(['job_title', 'Pozita profesionale (opsionale)'])
  const roleProfile = profile.profile

  return <>
    <PageHeading eyebrow="Llogaria ime" title="Profili im" description="Përditësoni të dhënat personale dhe shikoni informacionin e llogarisë." />
    <div className="profile-grid">
      <aside className="admin-panel profile-summary">
        <span className="profile-avatar" aria-hidden="true">{profile.first_name[0]}{profile.last_name[0]}</span>
        <h2>{profile.first_name} {profile.last_name}</h2>
        <p>{profile.email}</p>
        <span className="profile-role">{roleLabels[profile.role]}</span>
        <AccountBadge active={profile.is_active} />
        <dl><ReadOnlyField label="Email · vetëm lexim" value={profile.email} /><ReadOnlyField label="Roli · vetëm lexim" value={roleLabels[profile.role]} /></dl>
        <p className="profile-hint">Email-i dhe roli nuk ndryshohen nga profili personal.</p>
      </aside>
      <div className="profile-content">
        <section className="admin-panel profile-form-panel" aria-labelledby="personal-heading">
          <h2 id="personal-heading">Të dhënat personale</h2>
          <p className="admin-muted">Fushat e shënuara me * janë të detyrueshme.</p>
          {notice && <p className="form-success" role="status">{notice}</p>}
          {error && <p id="profile-error" className="form-alert" role="alert" tabIndex="-1">{error}</p>}
          <form onSubmit={save} noValidate>
            <div className="profile-fields">
              {personalFields.map(([field, label]) => <div className="form-field" key={field}>
                <label htmlFor={`profile-${field}`}>{label}{['first_name', 'last_name'].includes(field) ? ' *' : ''}</label>
                <input id={`profile-${field}`} name={field} type={field === 'phone' ? 'tel' : 'text'} maxLength={255} autoComplete={{ first_name: 'given-name', last_name: 'family-name', phone: 'tel', job_title: 'organization-title' }[field]} value={values[field]} onChange={change} disabled={submitting} required={['first_name', 'last_name'].includes(field)} aria-invalid={Boolean(errors[field])} aria-describedby={errors[field] ? `${field}-error` : undefined} />
                {errors[field] && <span className="field-error" id={`${field}-error`}>{errors[field]}</span>}
              </div>)}
            </div>
            <div className="coordinator-form-actions"><button className="admin-button" type="button" onClick={reset} disabled={submitting || !dirty}>Anulo ndryshimet</button><button className="admin-button admin-primary" type="submit" disabled={submitting || !dirty}>{submitting ? 'Duke ruajtur…' : 'Ruaj ndryshimet'}</button></div>
          </form>
        </section>
        {profile.role !== 'ADMIN' && <section className="admin-panel profile-role-panel" aria-labelledby="role-profile-heading">
          <div className="profile-section-heading"><h2 id="role-profile-heading">Informacioni {profile.role === 'STUDENT' ? 'akademik' : profile.role === 'COMPANY_SUPERVISOR' ? 'profesional' : 'i njësisë akademike'}</h2><span>Vetëm lexim</span></div>
          {!roleProfile ? <p className="admin-muted">Profili për këtë rol nuk është regjistruar ende. Mund të përditësoni të dhënat personale.</p> : <dl className="profile-role-details">
            {profile.role === 'STUDENT' && <><ReadOnlyField label="Numri i studentit" value={roleProfile.student_number} /><ReadOnlyField label="Programi i studimit" value={roleProfile.study_program} /><ReadOnlyField label="Viti i studimit" value={roleProfile.study_year} /></>}
            {profile.role === 'ACADEMIC_COORDINATOR' && <ReadOnlyField label="Njësia akademike" value={roleProfile.academic_unit} />}
            {profile.role === 'COMPANY_SUPERVISOR' && <><ReadOnlyField label="Kompania" value={roleProfile.company?.name} /><ReadOnlyField label="Verifikimi i mbikëqyrësit" value={{ PENDING: 'Në pritje', APPROVED: 'I miratuar', REJECTED: 'I refuzuar' }[roleProfile.verification_status]} /><ReadOnlyField label="Verifikimi i kompanisë" value={{ PENDING: 'Në pritje', APPROVED: 'E miratuar', REJECTED: 'E refuzuar' }[roleProfile.company?.verification_status]} /></>}
          </dl>}
        </section>}
      </div>
    </div>
  </>
}
