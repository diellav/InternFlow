import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { useEffect } from 'react'
import { PageHeading } from '../../users/components/UserUi'
import { activeActivityInternships } from '../api/activitiesApi'
import { useActivityRecord } from '../hooks/useActivityRecord'
import { ActivityState } from '../components/ActivityState'
import { ActivityListPanel } from '../components/ActivityListPanel'
import '../styles/activities.css'

export function ActivitiesPage() {
  const [params, setParams] = useSearchParams()
  const location = useLocation()
  const { data: internships, error, reload } = useActivityRecord(activeActivityInternshipsLoader, 'active')
  const selected = params.get('internship') ?? ''
  useEffect(() => { if (internships?.length && !selected) setParams((current) => { const next = new URLSearchParams(current); next.set('internship', String(internships[0].id)); return next }, { replace: true }) }, [internships, selected, setParams])
  if (error || !internships) return <ActivityState error={error} reload={reload} />
  const eligible = internships.some((record) => String(record.id) === selected)
  return <div className="activity-feature"><PageHeading eyebrow="Ditari i praktikës" title="Aktivitetet e mia" description="Regjistroni punën e kryer në secilën praktikë aktive." />{location.state?.conflict && <p role="alert" className="form-alert">{location.state.conflict}</p>}{!internships.length ? <section className="internship-empty"><h2>Nuk keni praktikë aktive</h2><p>Aktivitetet regjistrohen pasi praktika të jetë aktive.</p><Link to="/student/internships">Praktikat e mia</Link></section> : <><div className="activity-toolbar"><div className="form-field"><label htmlFor="activity-internship">Praktika aktive</label><select id="activity-internship" value={selected} onChange={(event) => setParams({ internship: event.target.value })}>{!eligible && <option value={selected}>Zgjidhni një praktikë aktive</option>}{internships.map((record) => <option key={record.id} value={record.id}>{record.position_title} · #{record.id}</option>)}</select></div>{eligible && <Link className="admin-button admin-primary" to={`/student/internships/${selected}/activities/new`} state={{ activityList: location.pathname + location.search }}>Regjistro aktivitet</Link>}</div>{eligible ? <ActivityListPanel key={selected} internshipId={selected} /> : <p role="alert">Praktika e zgjedhur nuk është e disponueshme. Zgjidhni një praktikë aktive.</p>}</>}</div>
}

const activeActivityInternshipsLoader = (_, signal) => activeActivityInternships(signal)
