import { useEffect, useRef, useState } from 'react'
import { useAuth } from '../../auth/hooks/useAuth'
import { useActivityRecord } from '../../activities/hooks/useActivityRecord'
import { completeInternship, completionErrorMessage, getCoordinatorEvaluation } from '../api/coordinatorEvaluationApi'
import { evaluationLabels } from '../constants/evaluationLabels'
import '../styles/evaluations.css'

const blockers = {
  COMPLETED: 'Praktika është përfunduar. Vlerësimi dhe evidencat mbeten vetëm për lexim.',
  NOT_ACTIVE: 'Përfundimi mund të konfirmohet vetëm për një praktikë aktive.',
  END_DATE_NOT_REACHED: 'Data e përfundimit të praktikës ende nuk është arritur.',
  MISSING_SUBMITTED_EVALUATION: 'Në pritje të vlerësimit përfundimtar të dorëzuar nga mbikëqyrësi.',
  UNRESOLVED_TASKS: 'Ka dorëzime pa shqyrtim ose detyra të dorëzuara/që kërkojnë korrigjime. Mbikëqyrësi duhet t’i zgjidhë përpara përfundimit.',
}

export function CoordinatorEvaluationSection({ internshipId, onCompleted, onRefresh }) {
  const { data, error, reload } = useActivityRecord(getCoordinatorEvaluation, internshipId, completionErrorMessage)
  return <section className="internship-panel internship-record evaluation-feature" aria-label="Vlerësimi përfundimtar"><h2>Vlerësimi përfundimtar</h2>{error ? <><p role="alert">{error}</p><button className="admin-button" onClick={reload}>Provo përsëri</button></> : !data ? <p role="status">Duke ngarkuar vlerësimin…</p> : <Review key={`${data.internship.id}-${data.internship.status}-${data.evaluation?.id ?? 'pending'}-${data.completion_blocker ?? 'ready'}`} initial={data} onCompleted={onCompleted} onConflict={(message) => { reload(); onRefresh(message) }} />}</section>
}

function Review({ initial, onCompleted, onConflict }) {
  const [record, setRecord] = useState(initial)
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const pending = useRef(false)
  const dialog = useRef(null)
  const { refreshUser } = useAuth()
  const evaluation = record.evaluation
  const completed = record.internship.status === 'COMPLETED'
  const canComplete = Boolean(evaluation?.submitted_at && record.can_complete && record.internship.status === 'ACTIVE' && !record.internship.completed_at)
  useEffect(() => {
    if (!confirming) return
    const element = dialog.current
    element.showModal()
    return () => element.close()
  }, [confirming])
  async function complete() {
    if (pending.current || !canComplete) return
    pending.current = true; setBusy(true); setError(''); setNotice('')
    try {
      const internship = await completeInternship(record.internship.id, evaluation.id)
      setRecord((current) => ({ ...current, internship, can_complete: false, completion_blocker: 'COMPLETED' }))
      setConfirming(false); setNotice('Përfundimi i praktikës u konfirmua me sukses.'); onCompleted(internship)
    } catch (failure) {
      setConfirming(false)
      if ([404, 409].includes(failure.status)) onConflict(completionErrorMessage(failure))
      else {
        setError(completionErrorMessage(failure))
        if ([401, 403].includes(failure.status)) await refreshUser()
      }
    } finally { pending.current = false; setBusy(false) }
  }
  return <>{notice && <p role="status" className="form-alert">{notice}</p>}{error && <p role="alert" className="form-alert">{error}</p>}<p>{completed ? 'COMPLETED · Praktika e përfunduar' : `Gjendja e praktikës: ${record.internship.status}`}</p>{record.internship.completed_at && <p>Përfunduar më: <time dateTime={record.internship.completed_at}>{new Date(record.internship.completed_at).toLocaleString('sq-AL')}</time></p>}{record.completion_blocker && record.completion_blocker !== 'MISSING_SUBMITTED_EVALUATION' && <p>{blockers[record.completion_blocker]}</p>}{!evaluation?.submitted_at ? <p className="internship-empty">Në pritje të vlerësimit përfundimtar të dorëzuar nga mbikëqyrësi.</p> : <><p>Vlerësimi i dorëzuar nga: {evaluation.evaluator?.first_name} {evaluation.evaluator?.last_name}</p><p>Dorëzuar më: {new Date(evaluation.submitted_at).toLocaleString('sq-AL')}</p><dl className="evaluation-ratings">{Object.entries(evaluationLabels).map(([field, label]) => <div key={field}><dt>{label}</dt><dd>{evaluation[field] ?? 'Nuk është shënuar'} / 5</dd></div>)}</dl><h3>Komenti përfundimtar i mbikëqyrësit</h3><p className="evaluation-comments">{evaluation.comments ?? 'Nuk është shënuar'}</p></>}<p className="internship-help">Vlerësimi dhe evidencat janë vetëm për lexim. Hapja e tyre nuk konfirmon përfundimin. Nuk kërkohet minimum orësh dhe nuk rillogariten vlerësimet e mbikëqyrësit.</p>{canComplete && <button className="admin-button admin-primary" disabled={busy} onClick={() => setConfirming(true)}>Konfirmo përfundimin e praktikës</button>}{confirming && <dialog className="admin-dialog" ref={dialog} aria-labelledby="completion-confirm-title" aria-describedby="completion-confirm-description" onCancel={(event) => { event.preventDefault(); if (!pending.current) setConfirming(false) }}><h2 id="completion-confirm-title">Konfirmoni përfundimin e praktikës?</h2><p id="completion-confirm-description">Praktika kalon nga ACTIVE në COMPLETED dhe regjistrohet koha e serverit. Vlerësimi i mbikëqyrësit, detyrat, dorëzimet dhe aktivitetet ruhen pa ndryshime.</p><div className="admin-dialog-actions"><button className="admin-button" autoFocus disabled={busy} onClick={() => setConfirming(false)}>Anulo</button><button className="admin-button admin-primary" disabled={busy} onClick={complete}>{busy ? 'Duke konfirmuar…' : 'Konfirmo përfundimin'}</button></div></dialog>}</>
}
