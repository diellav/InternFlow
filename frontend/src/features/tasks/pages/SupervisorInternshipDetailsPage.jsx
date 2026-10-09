import { useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import { PageHeading } from '../../users/components/UserUi'
import { InternshipStatusBadge } from '../../internships/components/InternshipStatusBadge'
import { getSupervisorInternship } from '../api/tasksApi'
import { useTaskRecord } from '../hooks/useTaskRecord'
import { RecordLoading } from '../components/TaskUi'
import { ActivationDialog } from '../components/ActivationDialog'
import { TaskList } from '../components/TaskList'

export function SupervisorInternshipDetailsPage() {
  const { id } = useParams()
  return <Details key={id} id={id} />
}

function Details({ id }) {
  const location = useLocation()
  const { data: internship, error, reload } = useTaskRecord(getSupervisorInternship, id)
  const [confirming, setConfirming] = useState(false)
  const [notice, setNotice] = useState('')
  if (error || !internship) return <RecordLoading error={error} reload={reload} />
  return <div className="task-feature"><Link className="internship-back" to="/supervisor/internships">← Praktikat e caktuara</Link><PageHeading eyebrow={`Praktika #${internship.id}`} title={internship.position_title} description={internship.company?.name}><InternshipStatusBadge status={internship.status} />{internship.can_activate && <button className="admin-button admin-primary" onClick={() => setConfirming(true)}>Fillo praktikën</button>}{internship.can_create_tasks && <Link className="admin-button admin-primary" to={`/supervisor/internships/${id}/tasks/new`}>Krijo detyrë</Link>}</PageHeading>
    {notice && <p role="status" className="form-alert">{notice}</p>}{location.state?.conflict && <p role="alert" className="form-alert">{location.state.conflict}</p>}{confirming && <ActivationDialog internship={internship} onClose={() => setConfirming(false)} onUpdated={() => { setNotice('Praktika filloi me sukses.'); reload() }} onConflict={(message) => { setConfirming(false); setNotice(message); reload() }} />}
    {internship.status === 'APPROVED' && <p className="internship-help">Fillimi nuk është automatik. Data e serverit: {internship.server_date}. {internship.can_activate ? 'Praktika mund të fillojë tani.' : internship.activation_reason}</p>}
    <div className="internship-detail-grid"><section className="internship-panel"><h2>Studenti dhe periudha</h2><dl className="internship-facts">{[['Studenti', `${internship.student.first_name} ${internship.student.last_name}`], ['Programi', internship.student.study_program], ['Fillimi', internship.start_date], ['Përfundimi', internship.end_date], ['Koordinatori', internship.coordinator ? `${internship.coordinator.first_name} ${internship.coordinator.last_name}` : 'Nuk është caktuar'], ['Miratuar më', internship.approved_at ? new Date(internship.approved_at).toLocaleString('sq-AL') : 'Nuk është miratuar']].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl></section><section className="internship-panel"><h2>Përshkrimi i praktikës</h2><p className="task-description">{internship.description || 'Nuk është dhënë përshkrim.'}</p></section></div>
    <TaskList internshipId={internship.id} />
  </div>
}
