import { Link, useLocation, useParams } from 'react-router-dom'
import { useState } from 'react'
import { PageHeading } from '../../users/components/UserUi'
import { getStudentTask, getSupervisorTask } from '../api/tasksApi'
import { useTaskRecord } from '../hooks/useTaskRecord'
import { RecordLoading, TaskBadge } from '../components/TaskUi'
import { priorityLabels } from '../constants/taskLabels'
import { StudentTaskActions } from '../components/StudentTaskActions'
import { TaskSubmissions } from '../components/TaskSubmissions'
import '../styles/tasks.css'

export function TaskDetailsPage({ student = false }) {
  const { id } = useParams()
  return <Details key={`${student}-${id}`} id={id} student={student} />
}

function Details({ id, student }) {
  const { data: task, error, reload } = useTaskRecord(student ? getStudentTask : getSupervisorTask, id)
  const location = useLocation()
  const [actionNotice, setActionNotice] = useState('')
  function updated(message) { setActionNotice(message ?? 'Gjendja e detyrës u përditësua.'); reload() }
  if (error || !task) return <>{actionNotice && <p role="status">{actionNotice}</p>}<RecordLoading error={error} reload={reload} /></>
  const portal = student ? 'student' : 'supervisor'
  const navigationNotice = !actionNotice && task.status === 'ASSIGNED' ? location.state?.notice : null
  return <div className="task-feature"><Link className="internship-back" to={`/${portal}/internships/${task.internship.id}`}>← Detajet e praktikës</Link><PageHeading eyebrow={`Detyra #${task.id}`} title={task.title} description={task.internship.position_title}><TaskBadge status={task.status} />{!student && task.can_edit && <Link className="admin-button admin-primary" to={`/supervisor/tasks/${id}/edit`}>Ndrysho detyrën</Link>}</PageHeading>{navigationNotice && <p className="form-success" role="status">{navigationNotice}</p>}{!actionNotice && location.state?.conflict && <p className="form-alert" role="alert">{location.state.conflict}</p>}
    <section className="internship-panel"><h2>Informacioni i detyrës</h2><p className="task-description">{task.description}</p><dl className="internship-facts">{[['Prioriteti', priorityLabels[task.priority]], ['Afati', task.due_date ?? 'Pa afat'], ['Studenti', task.internship.student ? `${task.internship.student.first_name} ${task.internship.student.last_name}` : 'Nuk është dhënë'], ['Kompania', task.internship.company?.name], ['Krijuar më', new Date(task.created_at).toLocaleString('sq-AL')], ['Përditësuar më', new Date(task.updated_at).toLocaleString('sq-AL')]].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl></section>
    {actionNotice && <p className="form-success" role="status">{actionNotice}</p>}
    {student && (task.can_start || task.can_submit) && <StudentTaskActions key={`${task.id}-${task.status}`} task={task} onUpdated={updated} />}
    {(task.has_submissions || task.status === 'SUBMITTED') && <TaskSubmissions key={`${task.id}-${task.status}`} task={task} student={student} onUpdated={updated} />}
    <p className="internship-help internship-record">{student ? 'Detyrën e cakton mbikëqyrësi. Mund të filloni dhe dorëzoni punën vetëm kur veprimet janë të disponueshme.' : task.can_edit ? 'Mund të ndryshoni fushat e detyrës së caktuar, për sa kohë praktika është aktive dhe nuk ekziston dorëzim.' : 'Detyra nuk është e redaktueshme: praktika duhet të jetë aktive, detyra e caktuar dhe pa dorëzime.'}</p>
  </div>
}
