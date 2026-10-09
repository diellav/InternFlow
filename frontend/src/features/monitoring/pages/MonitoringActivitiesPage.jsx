import { Link, useLocation, useParams } from 'react-router-dom'
import { PageHeading } from '../../users/components/UserUi'
import { ActivityListPanel } from '../../activities/components/ActivityListPanel'
import { ActivityState } from '../../activities/components/ActivityState'
import { useActivityRecord } from '../../activities/hooks/useActivityRecord'
import { getMonitoringActivity, listMonitoringActivities, monitoringErrorMessage } from '../api/monitoringApi'
import '../styles/monitoring.css'

export function MonitoringActivitiesPage() {
  const { id } = useParams()
  return <div className="activity-feature monitoring-feature"><Link className="internship-back" to={`/coordinator/monitoring/internships/${id}`}>← Monitorimi i praktikës</Link><PageHeading eyebrow="Vetëm lexim" title="Aktivitetet dhe orët" description="Orët e raportuara nga studenti nuk janë vijueshmëri e verifikuar." /><ActivityListPanel key={id} internshipId={id} listRequest={listMonitoringActivities} errorMessage={monitoringErrorMessage} recordsBase="/coordinator/monitoring" /></div>
}

export function MonitoringActivityPage() {
  const { id } = useParams()
  return <Details key={id} id={id} />
}

function Details({ id }) {
  const location = useLocation()
  const { data: activity, error, reload } = useActivityRecord(getMonitoringActivity, id, monitoringErrorMessage)
  if (error || !activity) return <ActivityState error={error} reload={reload} />
  return <div className="activity-feature monitoring-feature"><Link className="internship-back" to={location.state?.activityList ?? `/coordinator/monitoring/internships/${activity.internship.id}/activities`}>← Aktivitetet dhe orët</Link><PageHeading eyebrow="Vetëm lexim" title={activity.title} description={activity.internship.position_title} /><section className="internship-panel"><h2>Puna e kryer</h2><p className="activity-description">{activity.description}</p><dl className="internship-facts"><div><dt>Data e aktivitetit</dt><dd>{activity.activity_date}</dd></div><div><dt>Orët e punës</dt><dd>{activity.hours ?? 'Nuk janë shënuar'}</dd></div></dl></section></div>
}
