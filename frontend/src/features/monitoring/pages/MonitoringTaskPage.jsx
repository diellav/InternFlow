import { Link, useParams } from 'react-router-dom'
import { PageHeading } from '../../users/components/UserUi'
import { useActivityRecord } from '../../activities/hooks/useActivityRecord'
import { ActivityState } from '../../activities/components/ActivityState'
import { TaskBadge } from '../../tasks/components/TaskUi'
import { TaskAttachments } from '../../tasks/components/TaskAttachments'
import { TaskFeedback } from '../../tasks/components/TaskFeedback'
import { getMonitoringTask, listMonitoringSubmissions, monitoringErrorMessage } from '../api/monitoringApi'
import { MonitoringRecords } from '../components/MonitoringRecords'
import '../../tasks/styles/tasks.css'
import '../styles/monitoring.css'

function safeUrl(value) {
  try { const url = new URL(value); return url.protocol === 'https:' ? url.href : null } catch { return null }
}

export function MonitoringTaskPage() {
  const { id } = useParams()
  return <Details key={id} id={id} />
}

function Details({ id }) {
  const { data: task, error, reload } = useActivityRecord(getMonitoringTask, id, monitoringErrorMessage)
  if (error || !task) return <ActivityState error={error} reload={reload} />
  return <div className="monitoring-feature task-feature"><Link className="internship-back" to={`/coordinator/monitoring/internships/${task.internship.id}`}>← Monitorimi i praktikës</Link><PageHeading eyebrow="Vetëm lexim" title={task.title} description={task.internship.position_title}><TaskBadge status={task.status} /></PageHeading><section className="internship-panel"><h2>Informacioni i detyrës</h2><p className="task-description">{task.description}</p><dl className="internship-facts">{[['Prioriteti', task.priority], ['Afati', task.due_date ?? 'Pa afat'], ['Caktuar nga', `${task.assigned_by?.first_name ?? ''} ${task.assigned_by?.last_name ?? ''}`], ['Progresi i raportuar', task.progress_percent === null ? 'Nuk është shënuar' : `${task.progress_percent}%`]].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl></section><section className="internship-panel internship-record"><h2>Puna e dorëzuar</h2><MonitoringRecords loader={listMonitoringSubmissions} id={id} empty="Nuk ka dorëzime.">{(records) => records.map((submission) => <article className="task-submission-record" key={submission.id}><h3>Dorëzimi {submission.version_no}</h3><p className="task-description">{submission.submission_text}</p><p>Dorëzuar më: {new Date(submission.submitted_at).toLocaleString('sq-AL')}</p>{safeUrl(submission.resource_url) && <a className="internship-back" href={safeUrl(submission.resource_url)} target="_blank" rel="noopener noreferrer">{submission.resource_url}</a>}<TaskFeedback feedback={submission.feedback} />{!submission.feedback && <p>Në pritje të shqyrtimit nga mbikëqyrësi.</p>}<TaskAttachments files={submission.files} /></article>)}</MonitoringRecords></section></div>
}
