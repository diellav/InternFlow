import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { PageHeading } from '../../users/components/UserUi'
import { useActivityRecord } from '../../activities/hooks/useActivityRecord'
import { ActivityState } from '../../activities/components/ActivityState'
import { TaskBadge } from '../../tasks/components/TaskUi'
import { getMonitoringInternship, listMonitoringTasks, monitoringErrorMessage } from '../api/monitoringApi'
import { MonitoringRecords } from '../components/MonitoringRecords'
import { CoordinatorEvaluationSection } from '../../evaluations/components/CoordinatorEvaluationSection'
import '../styles/monitoring.css'

export function MonitoringInternshipPage() {
  const { id } = useParams()
  return <Details key={id} id={id} />
}

function Details({ id }) {
  const { data, error, reload } = useActivityRecord(getMonitoringInternship, id, monitoringErrorMessage)
  const [completedRecord, setCompletedRecord] = useState(null)
  const [completionConflict, setCompletionConflict] = useState('')
  const record = completedRecord ?? data
  if (error || !record) return <ActivityState error={error} reload={reload} />
  return <div className="monitoring-feature"><Link className="internship-back" to="/coordinator/monitoring/internships">← Monitorimi i praktikave</Link><PageHeading eyebrow="Monitorim akademik" title={record.position_title} description={record.status} />{completionConflict && <p role="alert" className="form-alert">{completionConflict}</p>}<section className="internship-panel"><h2>Informacioni i praktikës</h2><p className="activity-description">{record.description}</p><dl className="internship-facts">{[['Studenti', `${record.student?.first_name ?? ''} ${record.student?.last_name ?? ''}`], ['Programi', record.student?.study_program], ['Kompania', record.company?.name], ['Mbikëqyrësi', record.supervisor ? `${record.supervisor.first_name} ${record.supervisor.last_name}` : 'Pa mbikëqyrës'], ['Fillimi', record.start_date], ['Përfundimi', record.end_date], ['Detyrat e miratuara', `${record.approved_tasks_count} / ${record.tasks_count}`]].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value ?? '—'}</dd></div>)}</dl></section><section className="internship-panel internship-record"><h2>Aktivitetet dhe orët</h2><Link className="admin-button" to={`/coordinator/monitoring/internships/${id}/activities`}>Shiko aktivitetet dhe orët</Link></section><section className="internship-panel internship-record"><h2>Detyrat dhe dorëzimet</h2><MonitoringRecords loader={listMonitoringTasks} id={id} empty="Nuk ka detyra të caktuara.">{(tasks) => <div className="internship-cards">{tasks.map((task) => <article className="internship-card" key={task.id}><TaskBadge status={task.status} /><h3><Link to={`/coordinator/monitoring/tasks/${task.id}`}>{task.title}</Link></h3><p>{task.priority} · {task.due_date ?? 'Pa afat'}</p><p>Caktuar nga: {task.assigned_by?.first_name} {task.assigned_by?.last_name}</p></article>)}</div>}</MonitoringRecords></section><CoordinatorEvaluationSection internshipId={id} onCompleted={(updated) => { setCompletedRecord(updated); reload() }} onRefresh={(message) => { setCompletionConflict(message); setCompletedRecord(null); reload() }} /></div>
}
