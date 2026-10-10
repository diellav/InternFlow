import { useEffect } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useNotifications } from '../context/NotificationContext'
import { useNotificationList } from '../hooks/useNotificationList'
import { NotificationList } from '../components/NotificationList'
import '../styles/notifications.css'

const filters = { all: 'Të gjitha', unread: 'Të palexuara', read: 'Të lexuara' }

export function NotificationsPage() {
  const state = useNotifications()
  const [params, setParams] = useSearchParams()
  const status = Object.hasOwn(filters, params.get('status')) ? params.get('status') : 'all'
  const input = params.get('page')
  const page = /^\d+$/.test(input ?? '') && Number.isSafeInteger(Number(input)) && Number(input) > 0 ? Number(input) : 1
  const list = useNotificationList({ status, page })
  function change(nextStatus, nextPage = 1) {
    setParams({ status: nextStatus, ...(nextPage > 1 ? { page: String(nextPage) } : {}) })
  }
  useEffect(() => {
    if (!list.loading && !list.error && list.meta && page > list.meta.last_page) {
      setParams({ status, ...(list.meta.last_page > 1 ? { page: String(list.meta.last_page) } : {}) }, { replace: true })
    }
  }, [list.loading, list.error, list.meta, page, status, setParams])
  return <div className="notification-feature notification-center">
    <div className="notification-center-heading"><div><p className="notification-eyebrow">Llogaria ime</p><h1>Njoftimet</h1><p role="status">{state.count === null ? (state.countError ? 'Numri i njoftimeve është i padisponueshëm.' : 'Duke ngarkuar numrin…') : `${state.count} njoftime të palexuara`}</p></div><button className="admin-button primary" disabled={state.busy || state.count === 0} onClick={state.markAll}>{state.busy ? 'Duke përditësuar…' : 'Lexoji të gjitha'}</button></div>
    <nav className="notification-filters" aria-label="Filtrat e njoftimeve">{Object.entries(filters).map(([value, label]) => <button key={value} aria-current={status === value ? 'page' : undefined} onClick={() => change(value)}>{label}</button>)}</nav>
    {state.countError && <div role="alert"><p>{state.countError}</p><button className="admin-button" onClick={() => state.refreshCount()}>Përditëso numrin</button></div>}
    {state.mutationError && <p role="alert">{state.mutationError}</p>}
    <NotificationList {...list} />
    {!list.loading && !list.error && list.meta && <div className="notification-pagination"><span>{list.meta.total} njoftime · Faqja {list.meta.current_page} nga {list.meta.last_page}</span><div><button className="admin-button" disabled={page <= 1 || state.busy} onClick={() => change(status, page - 1)}>Para</button><button className="admin-button" disabled={page >= list.meta.last_page || state.busy} onClick={() => change(status, page + 1)}>Pas</button></div></div>}
  </div>
}
