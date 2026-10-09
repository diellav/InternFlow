import { Link, useParams } from 'react-router-dom'
import { PageHeading } from '../../users/components/UserUi'
import { ActivityListPanel } from '../components/ActivityListPanel'
import '../styles/activities.css'

export function SupervisorActivitiesPage() {
  const { internshipId } = useParams()
  return <div className="activity-feature"><Link className="internship-back" to={`/supervisor/internships/${internshipId}`}>← Detajet e praktikës</Link><PageHeading eyebrow="Ditari i praktikës" title="Aktivitetet e studentit" description="Ditari i punës së regjistruar nga studenti · vetëm lexim." /><ActivityListPanel key={internshipId} internshipId={internshipId} supervisor /></div>
}
