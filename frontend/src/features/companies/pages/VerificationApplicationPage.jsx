import { useEffect, useRef, useState } from 'react'
import { useAuth } from '../../auth/hooks/useAuth'
import { PageHeading, AccountBadge } from '../../users/components/UserUi'
import { companyErrorMessage } from '../api/companiesApi'
import { getVerificationApplication, saveVerificationApplication, resubmitVerificationApplication } from '../api/verificationApplicationApi'
import { VerificationBadge } from '../components/VerificationBadge'
import '../styles/verificationApplication.css'

const fields = {
  supervisor: [['first_name', 'Emri'], ['last_name', 'Mbiemri'], ['phone', 'Telefoni'], ['job_title', 'Pozita profesionale']],
  company: [['name', 'Emri i kompanisë'], ['industry', 'Industria'], ['address', 'Adresa'], ['email', 'Email-i i kompanisë'], ['phone', 'Telefoni i kompanisë'], ['website', 'Faqja e internetit']],
}

const conflictMessage = 'Gjendja e aplikimit ka ndryshuar. Të dhënat po rifreskohen.'

function draft(application) {
  return Object.fromEntries(Object.entries(fields).map(([section, entries]) => [section,
    Object.fromEntries(entries.map(([key]) => [key, application[section]?.[key] ?? ''])),
  ]))
}

export function VerificationApplicationPage() {
  const { refreshUser } = useAuth()
  const busy = useRef(false)
  const dialog = useRef(null)
  const [application, setApplication] = useState(null)
  const [values, setValues] = useState({ supervisor: {}, company: {} })
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [errors, setErrors] = useState({})
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [revision, setRevision] = useState(0)
  const [confirming, setConfirming] = useState(false)

  function accept(data) { setApplication(data); setValues(draft(data)); setErrors({}) }

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(async () => {
      if (controller.signal.aborted) return
      setLoading(true)
      try {
        const data = await getVerificationApplication(controller.signal)
        if (!controller.signal.aborted) {
          accept(data)
          setError((current) => current === conflictMessage ? current : '')
        }
      } catch (failure) {
        if (!controller.signal.aborted) {
          setError(companyErrorMessage(failure))
          if ([401, 403].includes(failure.status)) await refreshUser()
        }
      } finally { if (!controller.signal.aborted) setLoading(false) }
    })
    return () => controller.abort()
  }, [refreshUser, revision])

  useEffect(() => {
    if (confirming) dialog.current?.showModal()
  }, [confirming])

  const original = application ? draft(application) : values
  const dirty = (section) => Object.keys(values[section]).some((key) => values[section][key] !== original[section][key])
  const targets = application ? ['supervisor', 'company'].filter((section) => application[`can_resubmit_${section}`]) : []

  function closeDialog() {
    if (busy.current) return
    dialog.current?.close()
    setConfirming(false)
  }

  async function perform(operation, savedSection) {
    if (busy.current) return
    busy.current = true
    setSubmitting(true)
    setError('')
    setNotice('')
    setErrors({})
    try {
      const updated = await operation()
      setApplication(updated)
      setValues((current) => {
        const next = draft(updated)
        if (!savedSection) return next
        const other = savedSection === 'company' ? 'supervisor' : 'company'
        return { ...next, ...(updated[`editable_${other}_fields`].length > 0 ? { [other]: current[other] } : {}) }
      })
      dialog.current?.close()
      setConfirming(false)
      await refreshUser()
      setNotice(savedSection ? 'Ndryshimet u ruajtën. Statusi mbetet i refuzuar deri në ridërgim.' : 'Aplikimi u ridërgua. Në pritje të shqyrtimit nga administratori.')
    } catch (failure) {
      setErrors(Object.fromEntries(Object.entries(failure.validationErrors ?? {}).map(([key, messages]) => [key, messages[0]])))
      if (failure.status === 409) {
        dialog.current?.close()
        setConfirming(false)
        setApplication(null)
        setError(conflictMessage)
        setRevision((current) => current + 1)
      } else {
        if (failure.status === 422) { dialog.current?.close(); setConfirming(false) }
        setError(failure.status === 422 ? 'Kontrolloni fushat e aplikimit.' : companyErrorMessage(failure))
        if ([401, 403].includes(failure.status)) await refreshUser()
      }
    } finally { busy.current = false; setSubmitting(false) }
  }

  function save(event, section) {
    event.preventDefault()
    const payload = Object.fromEntries(Object.entries(values[section]).map(([key, value]) => [key,
      ['first_name', 'last_name', 'name'].includes(key) ? value.trim() : value.trim() || null,
    ]))
    return perform(() => saveVerificationApplication({ [section]: payload }), section)
  }

  if (loading) return <p role="status">Duke ngarkuar aplikimin…</p>
  if (!application) return <section className="admin-panel"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRevision((current) => current + 1)}>Provo përsëri</button></section>

  return <div className="verification-application">
    <PageHeading eyebrow="Llogaria ime" title="Aplikimi për verifikim" description="Korrigjoni informacionin e refuzuar dhe ridërgojeni për shqyrtim. Ruajtja nuk ndryshon statusin." />
    <AccountBadge active={application.supervisor.is_active} />
    {error && <p className="form-alert" role="alert">{error}</p>}
    {notice && <p className="form-success" role="status">{notice}</p>}
    <div className="verification-application-grid">
      {['supervisor', 'company'].map((section) => {
        const data = application[section]
        const editable = application[`editable_${section}_fields`]
        return <section className="admin-panel verification-application-section" key={section} aria-labelledby={`${section}-heading`}>
          <h2 id={`${section}-heading`}>{section === 'supervisor' ? 'Verifikimi i mbikëqyrësit' : 'Verifikimi i kompanisë'}</h2>
          {data ? <>
            <VerificationBadge status={data.verification_status} />
            {data.verification_status === 'REJECTED' && <div className="verification-reason"><h3>Arsyeja e refuzimit</h3><p>{data.rejection_reason || 'Arsyeja nuk është regjistruar.'}</p></div>}
            {data.verification_status === 'PENDING' && <p>Në pritje të shqyrtimit nga administratori.</p>}
            {section === 'company' && application.company_editing_limitation && <p className="form-alert">Kompania ka disa mbikëqyrës. Korrigjimi nga kjo faqe nuk lejohet; kontaktoni administratorin.</p>}
            {editable.length > 0 ? <form onSubmit={(event) => save(event, section)}>
              <div className="profile-fields">
                {fields[section].filter(([key]) => editable.includes(key)).map(([key, label]) => {
                  const id = `application-${section}-${key}`
                  const fieldError = errors[`${section}.${key}`]
                  return <div className="form-field" key={key}>
                    <label htmlFor={id}>{label}{['name', 'first_name', 'last_name'].includes(key) ? ' *' : ''}</label>
                    <input id={id} value={values[section][key]} type={key === 'email' ? 'email' : key === 'website' ? 'url' : key === 'phone' ? 'tel' : 'text'} maxLength={key === 'address' ? undefined : 255} required={['name', 'first_name', 'last_name'].includes(key)} disabled={submitting} aria-invalid={Boolean(fieldError)} aria-describedby={fieldError ? `${id}-error` : undefined} onChange={(event) => {
                      setValues((current) => ({ ...current, [section]: { ...current[section], [key]: event.target.value } }))
                      setErrors((current) => ({ ...current, [`${section}.${key}`]: undefined }))
                      setNotice('')
                    }} />
                    {fieldError && <span className="field-error" id={`${id}-error`}>{fieldError}</span>}
                  </div>
                })}
              </div>
              <div className="coordinator-form-actions">
                <button type="button" className="admin-button" disabled={submitting || !dirty(section)} onClick={() => { setValues((current) => ({ ...current, [section]: original[section] })); setErrors({}) }}>Anulo ndryshimet</button>
                <button className="admin-button admin-primary" disabled={submitting || !dirty(section)}>{submitting ? 'Duke ruajtur…' : 'Ruaj ndryshimet'}</button>
              </div>
            </form> : <dl className="profile-role-details">{fields[section].map(([key, label]) => <div key={key}><dt>{label}</dt><dd>{data[key] || 'Nuk është dhënë'}</dd></div>)}</dl>}
          </> : <p>Profili ose kompania mungon. Kontaktoni administratorin.</p>}
        </section>
      })}
    </div>
    <p className="profile-hint">Ridërgimi nuk jep qasje operative. Nevojiten llogari aktive, mbikëqyrës i miratuar dhe kompani aktive e miratuar.</p>
    {targets.length > 0 && <section className="admin-panel verification-application-section">
      <p>{targets.length === 2 ? 'Të dy aplikimet janë të pranueshme për ridërgim dhe do të kthehen në pritje.' : targets[0] === 'supervisor' ? 'Vetëm aplikimi i mbikëqyrësit do të kthehet në pritje.' : 'Vetëm aplikimi i kompanisë do të kthehet në pritje.'}</p>
      {(dirty('company') || dirty('supervisor')) && <p>Ruani ose anuloni ndryshimet para ridërgimit.</p>}
      <button className="admin-button admin-primary" disabled={submitting || dirty('company') || dirty('supervisor')} onClick={() => setConfirming(true)}>Ridërgo për verifikim</button>
    </section>}
    {confirming && <dialog ref={dialog} className="verification-application-dialog" aria-labelledby="resubmit-heading" onCancel={(event) => { event.preventDefault(); closeDialog() }}>
      <h2 id="resubmit-heading">Konfirmoni ridërgimin</h2>
      <p>{targets.length === 2 ? 'Kompania dhe mbikëqyrësi do të kthehen në PENDING.' : `${targets[0] === 'company' ? 'Kompania' : 'Mbikëqyrësi'} do të kthehet në PENDING. Statusi tjetër nuk ndryshon.`}</p>
      <p>Arsyet aktuale të refuzimit do të pastrohen. Historia e vendimeve të mëparshme nuk ruhet.</p>
      {error && <p className="form-alert" role="alert">{error}</p>}
      <div className="coordinator-form-actions"><button className="admin-button" disabled={submitting} onClick={closeDialog}>Anulo</button><button className="admin-button admin-primary" disabled={submitting} onClick={() => perform(() => resubmitVerificationApplication(targets))}>{submitting ? 'Duke ridërguar…' : 'Konfirmo ridërgimin'}</button></div>
    </dialog>}
  </div>
}
