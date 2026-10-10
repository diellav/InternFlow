import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/hooks/useAuth'
import { listTasks, taskErrorMessage } from '../api/tasksApi'
import { TaskBadge } from './TaskUi'
import { priorityLabels, taskStatusLabels } from '../constants/taskLabels'
import '../styles/tasks.css'

export function TaskList({ internshipId, student = false, readOnly = false }) {
  const portal = student ? 'student' : 'supervisor'
  const { refreshUser } = useAuth()
  const [filters, setFilters] = useState({ page: 1, per_page: 15, status: '', search: '' })
  const [search, setSearch] = useState('')
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')
  const [revision, setRevision] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(async () => {
      if (controller.signal.aborted) return
      setResult(null); setError('')
      try {
        const data = await listTasks(portal, internshipId, Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')), controller.signal)
        if (!controller.signal.aborted) {
          if (data.meta.current_page > data.meta.last_page) setFilters((value) => ({ ...value, page: data.meta.last_page }))
          else setResult(data)
        }
      } catch (failure) {
        if (controller.signal.aborted) return
        setError(taskErrorMessage(failure))
        if ([401, 403].includes(failure.status)) await refreshUser()
      }
    })
    return () => controller.abort()
  }, [portal, internshipId, filters, revision, refreshUser])
  return <section className="task-feature internship-record" aria-label="Detyrat e praktikës"><h2>Detyrat e praktikës</h2>{student && <p className="internship-help">{readOnly ? 'Historiku i detyrave, dorëzimeve dhe komenteve është vetëm për lexim.' : 'Hapni detajet për të filluar punën ose për të dorëzuar një detyrë në progres.'}</p>}
    <div className="task-filters"><form onSubmit={(event) => { event.preventDefault(); setFilters({ ...filters, search: search.trim(), page: 1 }) }}><label htmlFor={`task-search-${internshipId}`}>Kërko detyra</label><input id={`task-search-${internshipId}`} value={search} maxLength={255} onChange={(event) => setSearch(event.target.value)} /><button className="admin-button">Kërko</button></form><div><label htmlFor={`task-status-${internshipId}`}>Statusi i detyrës</label><select id={`task-status-${internshipId}`} value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value, page: 1 })}><option value="">Të gjitha statuset</option>{Object.entries(taskStatusLabels).map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></div></div>
    {error ? <div className="internship-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRevision((value) => value + 1)}>Provo përsëri</button></div> : !result ? <p role="status">Duke ngarkuar detyrat…</p> : !result.data.length ? <div className="internship-empty"><h3>Nuk ka detyra për t’u shfaqur</h3><p>{filters.status || filters.search ? 'Provoni filtra të tjerë.' : 'Detyrat e caktuara nga mbikëqyrësi do të shfaqen këtu.'}</p></div> : <div className="internship-cards">{result.data.map((task) => <article className="internship-card" key={task.id}><TaskBadge status={task.status} /><h3><Link to={`/${portal}/tasks/${task.id}`}>{task.title}</Link></h3><p className="task-description">{task.description}</p><p>Prioriteti: {priorityLabels[task.priority]} · Afati: {task.due_date ?? 'Pa afat'}</p><Link className="internship-back" to={`/${portal}/tasks/${task.id}`}>Detajet e detyrës →</Link></article>)}</div>}
    {result && <div className="internship-pagination"><span>{result.meta.total} detyra · Faqja {result.meta.current_page} nga {result.meta.last_page}</span><div><button className="admin-button" disabled={filters.page <= 1} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>Para</button><button className="admin-button" disabled={filters.page >= result.meta.last_page} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>Pas</button></div></div>}
  </section>
}
