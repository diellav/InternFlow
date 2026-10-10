import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useAuth } from '../../auth/hooks/useAuth'
import { PageHeading } from '../../users/components/UserUi'
import { useActivityRecord } from '../../activities/hooks/useActivityRecord'
import { evaluationErrorMessage, getEvaluation, saveEvaluation, submitEvaluation } from '../api/evaluationsApi'
import { evaluationLabels, submissionBlockers } from '../constants/evaluationLabels'
import '../styles/evaluations.css'

function valuesFor(evaluation) {
  return { ...Object.fromEntries(Object.keys(evaluationLabels).map((field) => [field, evaluation?.[field] == null ? '' : String(evaluation[field])])), comments: evaluation?.comments ?? '' }
}

export function SupervisorEvaluationPage() {
  const { id } = useParams()
  return <Details key={id} id={id} />
}

function Details({ id }) {
  const { data, error, reload } = useActivityRecord(getEvaluation, id, evaluationErrorMessage)
  const [conflict, setConflict] = useState('')
  if (error || !data) return <section className="evaluation-feature internship-empty">{error ? <><p role="alert">{error}</p><button className="admin-button" onClick={reload}>Provo përsëri</button></> : <p role="status">Duke ngarkuar vlerësimin…</p>}</section>
  return <div className="evaluation-feature"><Link className="internship-back" to={`/supervisor/internships/${id}`}>← Detajet e praktikës</Link><PageHeading eyebrow="Vlerësimi i mbikëqyrësit" title="Vlerësimi përfundimtar" description={data.internship.position_title} />{conflict && <p role="alert" className="form-alert">{conflict}</p>}<EvaluationForm key={data.evaluation?.draft_token ?? data.evaluation?.submitted_at ?? 'empty'} initial={data} id={id} onConflict={(message) => { setConflict(message); reload() }} /></div>
}

function EvaluationForm({ initial, id, onConflict }) {
  const [record, setRecord] = useState(initial)
  const [values, setValues] = useState(() => valuesFor(initial.evaluation))
  const [errors, setErrors] = useState({})
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const pending = useRef(false)
  const dialog = useRef(null)
  const { refreshUser } = useAuth()
  const original = valuesFor(record.evaluation)
  const dirty = Object.keys(values).some((field) => values[field] !== original[field])
  const readonly = record.evaluation?.status === 'SUBMITTED' || !record.can_edit
  useEffect(() => {
    if (!confirming) return
    const element = dialog.current
    element.showModal()
    return () => element.close()
  }, [confirming])
  function change(event) {
    const { name, value } = event.target
    setValues((current) => ({ ...current, [name]: value })); setErrors((current) => ({ ...current, [name]: undefined })); setNotice(''); setError('')
  }
  async function perform(submitting) {
    if (pending.current || readonly) return
    pending.current = true; setBusy(true); setError(''); setNotice('')
    try {
      const payload = Object.fromEntries(Object.keys(evaluationLabels).map((field) => [field, values[field] === '' ? null : Number(values[field])]))
      payload.comments = values.comments.trim() || null
      const result = submitting ? await submitEvaluation(id, record.evaluation.draft_token) : await saveEvaluation(id, payload)
      setRecord(result); setValues(valuesFor(result.evaluation)); setErrors({}); setConfirming(false)
      setNotice(submitting ? 'Vlerësimi u dorëzua me sukses. Praktika mbetet aktive.' : 'Drafti u ruajt me sukses.')
    } catch (failure) {
      setConfirming(false)
      if ([404, 409].includes(failure.status)) onConflict(evaluationErrorMessage(failure))
      else {
        setError(evaluationErrorMessage(failure))
        setErrors(Object.fromEntries(Object.entries(failure.validationErrors ?? {}).map(([field, messages]) => [field, messages[0]])))
        if ([401, 403].includes(failure.status)) await refreshUser()
      }
    } finally { pending.current = false; setBusy(false) }
  }
  function prepare() {
    if (pending.current || !record.can_submit || dirty) return
    const invalid = {}
    for (const field of Object.keys(evaluationLabels)) if (!/^[1-5]$/.test(values[field])) invalid[field] = 'Zgjidhni një vlerësim nga 1 deri në 5.'
    if ([...values.comments.trim()].length < 20 || [...values.comments.trim()].length > 10000) invalid.comments = 'Shkruani një koment kuptimplotë me 20–10,000 karaktere.'
    setErrors(invalid); setError(''); setNotice('')
    if (Object.keys(invalid).length) document.getElementById(`evaluation-${Object.keys(invalid)[0]}`)?.focus()
    else setConfirming(true)
  }
  return <><section className="internship-panel evaluation-eligibility"><h2>Gjendja e vlerësimit</h2><p>{record.evaluation?.status === 'SUBMITTED' ? 'I dorëzuar · vetëm lexim' : record.evaluation ? 'Draft · në përgatitje' : 'Nuk ka ende vlerësim.'}</p><p>Data e përfundimit: {record.internship.end_date ?? '—'} · Data e serverit: {record.server_date}</p>{record.submission_blocker && <p>{submissionBlockers[record.submission_blocker]}</p>}<p className="internship-help">Dorëzimi nuk e përfundon praktikën. Orët dhe numri i detyrave nuk prodhojnë automatikisht notë. Detyrat e caktuara ose në punim nuk e bllokojnë dorëzimin; dorëzimet pa shqyrtim dhe korrigjimet e pazgjidhura e bllokojnë.</p></section>{error && <p role="alert" className="form-alert">{error}</p>}{notice && <p role="status" className="form-alert">{notice}</p>}{readonly ? record.evaluation && <section className="internship-panel"><h2>Vlerësimi i dorëzuar</h2><dl className="evaluation-ratings">{Object.entries(evaluationLabels).map(([field, label]) => <div key={field}><dt>{label}</dt><dd>{record.evaluation[field] ?? 'Nuk është shënuar'} / 5</dd></div>)}</dl><h3>Komenti përfundimtar i mbikëqyrësit</h3><p className="evaluation-comments">{record.evaluation.comments}</p><p>Dorëzuar më: {record.evaluation.submitted_at ? new Date(record.evaluation.submitted_at).toLocaleString('sq-AL') : '—'}</p></section> : <form className="internship-panel internship-form" aria-label="Formulari i vlerësimit përfundimtar" onSubmit={(event) => { event.preventDefault(); perform(false) }} noValidate><h2>Kriteret e vlerësimit</h2><p>Shkalla: 1 — shumë dobët, 2 — dobët, 3 — kënaqshëm, 4 — mirë, 5 — shumë mirë. Çdo kriter dhe komenti janë të detyrueshëm për dorëzim.</p><div className="evaluation-grid">{Object.entries(evaluationLabels).map(([field, label]) => <div className="form-field" key={field}><label htmlFor={`evaluation-${field}`}>{label} *</label><select id={`evaluation-${field}`} name={field} value={values[field]} onChange={change} disabled={busy} aria-invalid={Boolean(errors[field])} aria-describedby={errors[field] ? `evaluation-${field}-error` : undefined}><option value="">Zgjidhni vlerësimin</option>{[1, 2, 3, 4, 5].map((rating) => <option value={rating} key={rating}>{rating} / 5</option>)}</select>{errors[field] && <span className="field-error" id={`evaluation-${field}-error`}>{errors[field]}</span>}</div>)}</div><div className="form-field"><label htmlFor="evaluation-comments">Komenti përfundimtar i mbikëqyrësit *</label><textarea id="evaluation-comments" name="comments" rows={7} maxLength={10000} value={values.comments} onChange={change} disabled={busy} aria-invalid={Boolean(errors.comments)} aria-describedby={errors.comments ? 'evaluation-comments-error' : undefined} />{errors.comments && <span className="field-error" id="evaluation-comments-error">{errors.comments}</span>}</div>{dirty && <p className="internship-help">Ruani ndryshimet përpara dorëzimit përfundimtar.</p>}<div className="internship-form-actions"><button className="admin-button" type="submit" disabled={busy}>{busy ? 'Duke ruajtur…' : 'Ruaj draftin'}</button><button className="admin-button admin-primary" type="button" disabled={busy || !record.can_submit || dirty} onClick={prepare}>Dorëzo vlerësimin</button></div></form>}{confirming && <dialog className="admin-dialog" ref={dialog} aria-labelledby="evaluation-confirm-title" aria-describedby="evaluation-confirm-description" onCancel={(event) => { event.preventDefault(); if (!pending.current) setConfirming(false) }}><h2 id="evaluation-confirm-title">Dorëzoni vlerësimin përfundimtar?</h2><p id="evaluation-confirm-description">Pas dorëzimit, vlerësimi është vetëm për lexim dhe nuk mund të ndryshohet. Praktika mbetet aktive.</p><div className="admin-dialog-actions"><button className="admin-button" autoFocus disabled={busy} onClick={() => setConfirming(false)}>Anulo</button><button className="admin-button admin-primary" disabled={busy} onClick={() => perform(true)}>{busy ? 'Duke dorëzuar…' : 'Konfirmo dorëzimin'}</button></div></dialog>}</>
}
