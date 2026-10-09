export function TaskFeedback({ feedback }) {
  if (!feedback) return null
  const revision = feedback.decision === 'REVISION_REQUIRED'
  return <div className="task-feedback" aria-label="Vendimi i mbikëqyrësit"><h4>{revision ? 'Kërkohen korrigjime' : 'Puna është miratuar'}</h4>{feedback.comment && <p className="task-description">{feedback.comment}</p>}<p>Shqyrtuar më: {new Date(feedback.created_at).toLocaleString('sq-AL')}</p></div>
}
