import { useNavigate } from 'react-router-dom'
import { safeNotificationUrl } from '../api/notificationsApi'
import { useNotifications } from '../context/NotificationContext'

export function NotificationList({ data, loading, error, retry, onNavigate }) {
  const state = useNotifications()
  const navigate = useNavigate()
  async function open(item, url) {
    if (state.busy) return
    if (!item.read_at && !(await state.markRead(item.id))) return
    onNavigate?.()
    navigate(url)
  }
  if (loading) return <p className="notification-state" role="status">Duke ngarkuar njoftimet…</p>
  if (error) return <div className="notification-state" role="alert"><p>{error}</p><button className="admin-button" onClick={retry}>Provo përsëri</button></div>
  if (!data.length) return <p className="notification-state">Nuk ka njoftime në këtë listë.</p>
  return <ul className="notification-list">{data.map(item => {
    const payload = item.payload ?? {}
    const url = safeNotificationUrl(payload.action_url)
    const title = typeof payload.title === 'string' ? payload.title : 'Njoftim'
    const message = typeof payload.message === 'string' ? payload.message : ''
    const date = new Date(item.created_at)
    const unread = !item.read_at
    return <li key={item.id} className={`notification-item${unread ? ' is-unread' : ''}`}>
      <div className="notification-item-heading"><span className="notification-read-status">{unread ? 'I palexuar' : 'I lexuar'}</span>{item.created_at && !Number.isNaN(date.getTime()) && <time dateTime={item.created_at}>{date.toLocaleString('sq-AL')}</time>}</div>
      {url ? <button className="notification-open" disabled={state.busy} aria-label={`${title}: Hap njoftimin`} onClick={() => open(item, url)}><strong>{title}</strong><span>{message}</span><span className="notification-open-label">Hap njoftimin →</span></button> : <div className="notification-content"><strong>{title}</strong><p>{message}</p></div>}
      {unread && <button className="notification-read" disabled={state.busy} aria-label={`Shëno si të lexuar: ${title}`} onClick={() => state.markRead(item.id)}>Shëno si të lexuar</button>}
    </li>
  })}</ul>
}
