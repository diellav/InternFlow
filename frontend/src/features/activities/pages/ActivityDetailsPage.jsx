import { Link, useLocation, useParams } from 'react-router-dom'
import { PageHeading } from '../../users/components/UserUi'
import { activityErrorMessage, getActivity, getSupervisorActivity, supervisorActivityErrorMessage } from '../api/activitiesApi'
import { useActivityRecord } from '../hooks/useActivityRecord'
import { ActivityState } from '../components/ActivityState'
import '../styles/activities.css'

export function ActivityDetailsPage({ supervisor = false }) {
  const { id } = useParams()
  return <Details key={`${supervisor}-${id}`} id={id} supervisor={supervisor} />
}

function Details({ id, supervisor }) {
  const { data: activity, error, reload } = useActivityRecord(supervisor ? getSupervisorActivity : getActivity, id, supervisor ? supervisorActivityErrorMessage : activityErrorMessage)
  const location = useLocation()
  if (error || !activity) return <ActivityState error={error} reload={reload} />
  return <div className="activity-feature"><Link className="internship-back" to={location.state?.activityList ?? (supervisor ? `/supervisor/internships/${activity.internship.id}/activities` : `/student/activities?internship=${activity.internship.id}`)}>← Aktivitetet e praktikës</Link><PageHeading eyebrow={`Aktiviteti #${activity.id}`} title={activity.title} description={activity.internship.position_title}>{!supervisor && activity.can_edit && <Link className="admin-button admin-primary" to={`/student/activities/${id}/edit`} state={{ activityList: location.state?.activityList }}>Ndrysho aktivitetin</Link>}</PageHeading>{!supervisor && location.state?.notice && <p className="form-success" role="status">{location.state.notice}</p>}<section className="internship-panel"><h2>Puna e kryer</h2><p className="activity-description">{activity.description}</p><dl className="internship-facts">{[['Data e aktivitetit', activity.activity_date], ['Orët e punës', activity.hours ?? 'Nuk janë shënuar'], ['Regjistruar më', new Date(activity.created_at).toLocaleString('sq-AL')]].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl></section></div>
}
