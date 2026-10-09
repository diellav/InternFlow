import { taskStatusLabels } from '../constants/taskLabels'

export function TaskBadge({ status }) {
  return <span className={`internship-status task-status-${status?.toLowerCase()}`}>{taskStatusLabels[status] ?? status}</span>
}

export function RecordLoading({ error, reload }) {
  return error ? <section className="internship-empty"><p role="alert">{error}</p><button className="admin-button" onClick={reload}>Provo përsëri</button></section> : <p role="status">Duke ngarkuar të dhënat…</p>
}
