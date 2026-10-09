import { useEffect, useState } from 'react'
import { useAuth } from '../../auth/hooks/useAuth'
import { listTaskSubmissions, taskErrorMessage } from '../api/tasksApi'
import { TaskFeedback } from './TaskFeedback'
import { TaskReviewDialog } from './TaskReviewDialog'
import { StudentTaskActions } from './StudentTaskActions'
import { TaskAttachments } from './TaskAttachments'

function safeLink(value) {
  try { const url = new URL(value); return url.protocol === 'https:' ? url.href : null } catch { return null }
}

export function TaskSubmissions({ task, student, onUpdated }) {
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')
  const [page, setPage] = useState(1)
  const [revision, setRevision] = useState(0)
  const [decision, setDecision] = useState(null)
  const { refreshUser } = useAuth()
  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(async () => {
      if (controller.signal.aborted) return
      setResult(null); setError('')
      try { const response = await listTaskSubmissions(student ? 'student' : 'supervisor', task.id, page, controller.signal); if (!controller.signal.aborted) setResult(response) }
      catch (failure) { if (!controller.signal.aborted) { setError(taskErrorMessage(failure)); if ([401, 403].includes(failure.status)) await refreshUser() } }
    })
    return () => controller.abort()
  }, [task.id, student, page, revision, refreshUser])
  const latest = result?.data[0]
  const canReview = !student && task.can_review && page === 1 && latest && !latest.feedback
  const canResubmit = student && task.can_resubmit && task.status === 'REVISION_REQUIRED' && page === 1 && latest?.feedback?.decision === 'REVISION_REQUIRED'
  return <section className="internship-panel internship-record" aria-label="Dorëzimet e punës"><h2>Puna e dorëzuar</h2>{task.status === 'SUBMITTED' && <p className="internship-help">Në pritje të shqyrtimit nga mbikëqyrësi.</p>}{error ? <><p role="alert">{error}</p><button className="admin-button" onClick={() => setRevision((value) => value + 1)}>Provo përsëri</button></> : !result ? <p role="status">Duke ngarkuar dorëzimet…</p> : !result.data.length ? <p>Nuk ka dorëzime.</p> : result.data.map((submission, index) => <article className={`task-submission-record${page === 1 && index === 0 ? ' task-submission-latest' : ''}`} key={submission.id}><h3>Dorëzimi {submission.version_no}</h3><p className="admin-eyebrow">{page === 1 && index === 0 ? 'Versioni më i fundit' : 'Version i mëparshëm'}</p><p className="task-description">{submission.submission_text}</p>{safeLink(submission.resource_url) && <a className="internship-back" href={submission.resource_url} title={submission.resource_url} target="_blank" rel="noopener noreferrer">{submission.resource_url}</a>}<p>Dorëzuar më: {new Date(submission.submitted_at).toLocaleString('sq-AL')}</p>{!submission.feedback && <p className="internship-help">Dorëzim në pritje të shqyrtimit.</p>}<TaskAttachments files={submission.files} /><TaskFeedback feedback={submission.feedback} />{canResubmit && index === 0 && <StudentTaskActions key={latest.id} task={task} resubmission={latest} onUpdated={onUpdated} />}</article>)}{canReview && <div className="admin-dialog-actions"><button className="admin-button admin-primary" onClick={() => setDecision('APPROVED')}>Mirato punën</button><button className="admin-button" onClick={() => setDecision('REVISION_REQUIRED')}>Kërko korrigjime</button></div>}{decision && canReview && <TaskReviewDialog taskId={task.id} submission={latest} decision={decision} onClose={() => setDecision(null)} onUpdated={onUpdated} />}{result && result.meta.last_page > 1 && <div className="internship-pagination"><span>Faqja {result.meta.current_page} nga {result.meta.last_page}</span><button className="admin-button" disabled={page <= 1} onClick={() => setPage(page - 1)}>Para</button><button className="admin-button" disabled={page >= result.meta.last_page} onClick={() => setPage(page + 1)}>Pas</button></div>}</section>
}
