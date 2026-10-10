import { useActivityRecord } from '../../activities/hooks/useActivityRecord'
import { getStudentEvaluation, studentEvaluationErrorMessage } from '../api/studentEvaluationApi'
import { evaluationLabels } from '../constants/evaluationLabels'
import '../styles/evaluations.css'

export function StudentEvaluationSection({ internshipId }) {
  const { data, error, reload } = useActivityRecord(getStudentEvaluation, internshipId, studentEvaluationErrorMessage)
  const visible = data?.internship.id === Number(internshipId) && data.internship.status === 'COMPLETED' && data.internship.completed_at && data.evaluation?.submitted_at
  const evaluation = visible ? data.evaluation : null
  return <section className="internship-panel internship-record evaluation-feature" aria-label="Vlerësimi përfundimtar"><h2>Vlerësimi përfundimtar</h2>{error ? <><p role="alert">{error}</p><button className="admin-button" onClick={reload}>Provo përsëri</button></> : !data ? <p role="status">Duke ngarkuar vlerësimin…</p> : !evaluation ? <p>Vlerësimi përfundimtar nuk është ende i disponueshëm për ju.</p> : <><p>Vlerësimi i dorëzuar nga: {evaluation.evaluator?.first_name} {evaluation.evaluator?.last_name}</p><p>Dorëzuar më: <time dateTime={evaluation.submitted_at}>{new Date(evaluation.submitted_at).toLocaleString('sq-AL')}</time></p><dl className="evaluation-ratings">{Object.entries(evaluationLabels).map(([field, label]) => <div key={field}><dt>{label}</dt><dd>{evaluation[field] === null || evaluation[field] === undefined ? 'Nuk është shënuar' : `${evaluation[field]} / 5`}</dd></div>)}</dl><h3>Komenti përfundimtar i mbikëqyrësit</h3><p className="evaluation-comments">{evaluation.comments ?? 'Nuk është shënuar'}</p><p className="internship-help">Vlerësimi është vetëm për lexim. Vlerat e mbikëqyrësit nuk rillogariten.</p></>}</section>
}
