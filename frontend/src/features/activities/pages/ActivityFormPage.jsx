import { Link, useLocation, useParams } from 'react-router-dom'
import { PageHeading } from '../../users/components/UserUi'
import { getActivity, getActivityInternship } from '../api/activitiesApi'
import { useActivityRecord } from '../hooks/useActivityRecord'
import { ActivityState } from '../components/ActivityState'
import { ActivityForm } from '../components/ActivityForm'
import '../styles/activities.css'

export function ActivityFormPage() {
  const { id, internshipId } = useParams()
  return <FormPage key={id ? `edit-${id}` : `new-${internshipId}`} id={id} internshipId={internshipId} />
}

function FormPage({ id, internshipId }) {
  const location = useLocation()
  const { data, error, reload } = useActivityRecord(id ? getActivity : getActivityInternship, id ?? internshipId)
  if (error || !data) return <ActivityState error={error} reload={reload} />
  const internship = id ? data.internship : data
  if (internship.status !== 'ACTIVE' || (id && !data.can_edit)) return <ActivityState error="Praktika nuk është më aktive." reload={reload} />
  return <div className="activity-feature"><Link className="internship-back" to={location.state?.activityList ?? `/student/activities?internship=${internship.id}`}>← Aktivitetet e praktikës</Link><PageHeading eyebrow={internship.position_title} title={id ? 'Ndrysho aktivitetin' : 'Regjistro aktivitet'} description="Ditari i punës së kryer në praktikë." /><ActivityForm activity={id ? data : null} internship={internship} /></div>
}
