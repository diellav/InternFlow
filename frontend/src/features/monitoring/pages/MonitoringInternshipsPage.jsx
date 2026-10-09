import { Link } from 'react-router-dom'
import { PageHeading } from '../../users/components/UserUi'
import { MonitoringRecords } from '../components/MonitoringRecords'
import { listMonitoringInternships } from '../api/monitoringApi'
import '../styles/monitoring.css'

export function MonitoringInternshipsPage() {
  return <div className="monitoring-feature"><PageHeading eyebrow="Monitorim akademik" title="Monitorimi i praktikave" description="Praktikat e miratuara, aktive dhe të përfunduara që ju janë caktuar · vetëm lexim." /><MonitoringRecords loader={listMonitoringInternships} id="assigned" empty="Nuk ka praktika për monitorim.">{(records) => <div className="internship-cards">{records.map((record) => <article className="internship-card" key={record.id}><h2><Link to={`/coordinator/monitoring/internships/${record.id}`}>{record.position_title}</Link></h2><p>{record.student?.first_name} {record.student?.last_name} · {record.company?.name}</p><p>{record.status} · {record.start_date} — {record.end_date}</p><p>{record.approved_tasks_count} / {record.tasks_count} detyra të miratuara</p></article>)}</div>}</MonitoringRecords></div>
}
