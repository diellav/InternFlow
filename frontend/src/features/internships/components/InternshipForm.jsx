import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../../auth/hooks/useAuth'
import { getInternshipCompanies, getInternshipSupervisors, saveInternship, internshipErrorMessage } from '../api/internshipsApi'

function initialValues(internship) {
  return { position_title: internship?.position_title ?? '', description: internship?.description ?? '', company_id: String(internship?.company_id ?? ''), company_supervisor_id: String(internship?.company_supervisor_id ?? ''), start_date: internship?.start_date ?? '', end_date: internship?.end_date ?? '' }
}

export function InternshipForm({ internship }) {
  const navigate = useNavigate()
  const { refreshUser } = useAuth()
  const original = initialValues(internship)
  const revising = internship?.status === 'REVISION_REQUIRED'
  const [values, setValues] = useState(() => initialValues(internship))
  const [companies, setCompanies] = useState([])
  const [supervisors, setSupervisors] = useState([])
  const [companiesLoading, setCompaniesLoading] = useState(true)
  const [supervisorsLoading, setSupervisorsLoading] = useState(false)
  const [lookupError, setLookupError] = useState('')
  const [supervisorError, setSupervisorError] = useState('')
  const [error, setError] = useState('')
  const [errors, setErrors] = useState({})
  const [submitting, setSubmitting] = useState(false)
  const [revision, setRevision] = useState(0)
  const busy = useRef(false)
  const dirty = Object.keys(values).some((key) => values[key] !== original[key])

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(async () => {
      if (controller.signal.aborted) return
      setCompaniesLoading(true); setLookupError('')
      try { const data = await getInternshipCompanies(controller.signal); if (!controller.signal.aborted) setCompanies(data) }
      catch (failure) {
        if (controller.signal.aborted) return
        setLookupError(internshipErrorMessage(failure))
        if ([401, 403].includes(failure.status)) await refreshUser()
      } finally { if (!controller.signal.aborted) setCompaniesLoading(false) }
    })
    return () => controller.abort()
  }, [revision, refreshUser])

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(async () => {
      if (controller.signal.aborted) return
      setSupervisors([]); setSupervisorError('')
      if (!values.company_id) { setSupervisorsLoading(false); return }
      setSupervisorsLoading(true)
      try { const data = await getInternshipSupervisors(values.company_id, controller.signal); if (!controller.signal.aborted) setSupervisors(data) }
      catch (failure) {
        if (controller.signal.aborted) return
        setSupervisorError(internshipErrorMessage(failure))
        if ([401, 403].includes(failure.status)) await refreshUser()
      } finally { if (!controller.signal.aborted) setSupervisorsLoading(false) }
    })
    return () => controller.abort()
  }, [values.company_id, revision, refreshUser])

  function change(event) {
    const { name, value } = event.target
    setValues((current) => ({ ...current, [name]: value, ...(name === 'company_id' ? { company_supervisor_id: '' } : {}) }))
    setErrors((current) => ({ ...current, [name]: undefined, ...(name === 'company_id' ? { company_supervisor_id: undefined } : {}) }))
    setError('')
  }

  async function save(event) {
    event.preventDefault()
    if (busy.current) return
    const fieldErrors = {}
    for (const field of ['position_title', 'company_id', 'start_date', 'end_date']) if (!values[field].trim()) fieldErrors[field] = 'Kjo fushë është e detyrueshme edhe për draftin.'
    if (values.end_date && values.start_date && values.end_date < values.start_date) fieldErrors.end_date = 'Data e përfundimit nuk mund të jetë para datës së fillimit.'
    setErrors(fieldErrors); setError('')
    if (Object.keys(fieldErrors).length) { document.getElementById(`internship-${Object.keys(fieldErrors)[0]}`)?.focus(); return }
    const payload = { ...values, position_title: values.position_title.trim(), description: values.description.trim() || null, company_id: Number(values.company_id), company_supervisor_id: values.company_supervisor_id ? Number(values.company_supervisor_id) : null }
    busy.current = true; setSubmitting(true)
    try {
      const updated = await saveInternship(internship?.id, payload)
      navigate(`/student/internships/${updated.id}`, { replace: true, state: { notice: revising ? 'Korrigjimet u ruajtën. Aplikimi nuk është ridorëzuar; ridorëzojeni nga detajet kur të jetë gati.' : internship ? 'Drafti u përditësua me sukses.' : 'Aplikimi u ruajt si draft.' } })
    } catch (failure) {
      if (failure.status === 409) {
        navigate(`/student/internships/${internship.id}`, { replace: true, state: { conflict: internshipErrorMessage(failure) } })
      } else {
        setErrors(Object.fromEntries(Object.entries(failure.validationErrors ?? {}).map(([field, messages]) => [field, messages[0]])))
        setError(internshipErrorMessage(failure))
        if ([401, 403].includes(failure.status)) await refreshUser()
        document.getElementById('internship-form-error')?.focus()
      }
    } finally { busy.current = false; setSubmitting(false) }
  }

  function fieldError(field) { return errors[field] && <span id={`internship-${field}-error`} className="field-error">{errors[field]}</span> }
  function attributes(field) { return { id: `internship-${field}`, name: field, value: values[field], onChange: change, disabled: submitting, 'aria-invalid': Boolean(errors[field]), 'aria-describedby': errors[field] ? `internship-${field}-error` : undefined } }
  const savedCompanyUnavailable = internship && String(internship.company_id) === values.company_id && !companies.some((company) => String(company.id) === values.company_id)
  const savedSupervisorUnavailable = internship?.supervisor && String(internship.company_supervisor_id) === values.company_supervisor_id && !supervisors.some((user) => String(user.user_id) === values.company_supervisor_id)

  return <div className="internship-form-layout"><form className="internship-panel internship-form" onSubmit={save} noValidate>
    {error && <p id="internship-form-error" className="form-alert" role="alert" tabIndex="-1">{error}</p>}
    <section aria-labelledby="internship-position-heading"><div className="internship-section-heading"><span>01</span><div><h2 id="internship-position-heading">Praktika e organizuar</h2><p>Shënoni pozitën dhe një përshkrim të punës së planifikuar.</p></div></div>
      <div className="form-field"><label htmlFor="internship-position_title">Titulli i pozitës *</label><input {...attributes('position_title')} maxLength={255} required placeholder="P.sh. Praktikant në zhvillim softueri" />{fieldError('position_title')}</div>
      <div className="form-field"><label htmlFor="internship-description">Përshkrimi (opsional)</label><textarea {...attributes('description')} rows={6} maxLength={10000} placeholder="Përshkruani përgjegjësitë dhe çfarë synoni të mësoni…" />{fieldError('description')}<small>Deri në 10,000 karaktere. Mund ta plotësoni më vonë.</small></div>
    </section>
    <section aria-labelledby="internship-company-heading"><div className="internship-section-heading"><span>02</span><div><h2 id="internship-company-heading">Kompania dhe mbikëqyrësi</h2><p>Zgjidhni kompaninë ku e keni organizuar praktikën.</p></div></div>
      {lookupError && <div className="form-alert" role="alert">{lookupError} <button type="button" className="admin-button" onClick={() => setRevision((current) => current + 1)}>Riprovo opsionet</button></div>}
      {!companiesLoading && !lookupError && !companies.length && <p className="internship-help">Nuk ka kompani aktive të miratuara. Nuk mund të ruani draft pa kompani. Kontaktoni koordinatorin përpara se të vazhdoni.</p>}
      <div className="internship-fields"><div className="form-field"><label htmlFor="internship-company_id">Kompania *</label><select {...attributes('company_id')} disabled={submitting || companiesLoading} required><option value="">{companiesLoading ? 'Duke ngarkuar kompanitë…' : 'Zgjidhni kompaninë'}</option>{savedCompanyUnavailable && <option value={values.company_id}>{internship.company?.name} · jo e disponueshme</option>}{companies.map((company) => <option value={company.id} key={company.id}>{company.name}</option>)}</select>{fieldError('company_id')}</div>
      <div className="form-field"><label htmlFor="internship-company_supervisor_id">Mbikëqyrësi (opsional)</label><select {...attributes('company_supervisor_id')} disabled={submitting || !values.company_id || supervisorsLoading}><option value="">{supervisorsLoading ? 'Duke ngarkuar mbikëqyrësit…' : 'Pa mbikëqyrës për momentin'}</option>{savedSupervisorUnavailable && <option value={values.company_supervisor_id}>{internship.supervisor.first_name} {internship.supervisor.last_name} · jo i disponueshëm</option>}{supervisors.map((user) => <option value={user.user_id} key={user.user_id}>{user.first_name} {user.last_name}{user.job_title ? ` · ${user.job_title}` : ''}</option>)}</select>{fieldError('company_supervisor_id')}</div></div>
      {supervisorError && <p className="form-alert" role="alert">{supervisorError} <button type="button" className="admin-button" onClick={() => setRevision((current) => current + 1)}>Riprovo opsionet</button></p>}
      <p className="internship-form-hint">Shfaqen vetëm kompani dhe mbikëqyrës aktivë e të miratuar. Mbikëqyrësi mund të mbetet i papërzgjedhur në draft.</p>
    </section>
    <section aria-labelledby="internship-dates-heading"><div className="internship-section-heading"><span>03</span><div><h2 id="internship-dates-heading">Periudha e planifikuar</h2><p>Të dyja datat nevojiten për ruajtjen e draftit.</p></div></div><div className="internship-fields">{[['start_date', 'Data e fillimit *'], ['end_date', 'Data e përfundimit *']].map(([field, label]) => <div className="form-field" key={field}><label htmlFor={`internship-${field}`}>{label}</label><input {...attributes(field)} type="date" required />{fieldError(field)}</div>)}</div></section>
    <div className="internship-form-actions"><Link className="admin-button" to={internship ? `/student/internships/${internship.id}` : '/student/internships'} onClick={(event) => { if (busy.current) event.preventDefault() }} aria-disabled={submitting}>Anulo</Link><button className="admin-button" type="button" disabled={submitting || !dirty} onClick={() => { setValues(original); setErrors({}); setError('') }}>Rivendos fushat</button><button className="admin-button admin-primary" disabled={submitting || companiesLoading || (Boolean(internship) && !dirty)}>{submitting ? 'Duke ruajtur…' : revising ? 'Ruaj korrigjimet' : internship ? 'Ruaj ndryshimet' : 'Ruaj si draft'}</button></div>
  </form><aside className="internship-form-aside"><div className="internship-help"><span className="internship-status internship-status-draft">{revising ? 'Kërkohen korrigjime' : 'Draft'}</span><h2>{revising ? 'Ruaj, pastaj ridorëzo' : 'Një hap përpara miratimit'}</h2><p>Ky formular regjistron një praktikë të organizuar jashtë InternFlow. Nuk është aplikim për punë.</p><ul><li>Kompania, pozita dhe datat janë të detyrueshme.</li><li>Përshkrimi dhe mbikëqyrësi mund të plotësohen më vonë.</li><li>Ruajtja nuk e dorëzon aplikimin për miratim.</li></ul><p>{revising ? 'Ruajtja mban udhëzimet dhe koordinatorin ekzistues. Hapni detajet dhe ridorëzojeni veçmas për ta kthyer në radhën e shqyrtimit të të njëjtit koordinator.' : 'Për dorëzim nevojitet mbikëqyrës aktiv dhe i miratuar. Hapni detajet e draftit për ta dorëzuar për shqyrtim akademik.'}</p></div></aside></div>
}
