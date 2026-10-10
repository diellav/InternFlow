import { Link, useParams } from 'react-router-dom'
import { PageHeading } from '../../users/components/UserUi'
import { getInternship } from '../../internships/api/internshipsApi'
import { useActivityRecord } from '../hooks/useActivityRecord'
import { ActivityState } from '../components/ActivityState'
import { ActivityListPanel } from '../components/ActivityListPanel'
import '../styles/activities.css'

export function InternshipActivitiesPage() {
  const { internshipId } = useParams()
  return <History key={internshipId} id={internshipId} />
}

function History({ id }) {
  const { data: internship, error, reload } = useActivityRecord(getInternship, id)
  if (error || !internship) return <ActivityState error={error} reload={reload} />
  if (!['ACTIVE', 'COMPLETED'].includes(internship.status)) return <ActivityState error="Historiku i aktiviteteve nuk është i disponueshëm për këtë praktikë." reload={reload} />
  return <div className="activity-feature"><Link className="internship-back" to={`/student/internships/${id}`}>← Detajet e praktikës</Link><PageHeading eyebrow={internship.status === 'COMPLETED' ? 'Vetëm lexim' : 'Ditari i praktikës'} title="Aktivitetet dhe orët" description={internship.position_title} />{internship.status === 'COMPLETED' && <p>Praktika e përfunduar: aktivitetet dhe orët e raportuara ruhen vetëm për lexim.</p>}<ActivityListPanel internshipId={id} recordsBase="/student" /></div>
}
