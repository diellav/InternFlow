import { useEffect, useId, useRef, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useNotifications } from '../context/NotificationContext'
import { useNotificationList } from '../hooks/useNotificationList'
import { NotificationList } from './NotificationList'
import '../styles/notifications.css'

export function NotificationBell() {
  const state = useNotifications()
  return state ? <Bell state={state} /> : null
}

function Bell({ state }) {
  const [openKey, setOpenKey] = useState(null)
  const root = useRef(null)
  const button = useRef(null)
  const panel = useRef(null)
  const id = useId()
  const location = useLocation()
  const open = openKey === location.key
  const list = useNotificationList({ per_page: 5 }, open)
  useEffect(() => {
    if (openKey !== null && openKey !== location.key) queueMicrotask(() => setOpenKey(current => current === location.key ? current : null))
  }, [location.key, openKey])
  useEffect(() => {
    if (!open) return
    panel.current?.focus()
    const outside = event => { if (!root.current?.contains(event.target)) setOpenKey(null) }
    const escape = event => {
      if (event.key === 'Escape') { setOpenKey(null); button.current?.focus() }
    }
    document.addEventListener('pointerdown', outside)
    document.addEventListener('keydown', escape)
    return () => { document.removeEventListener('pointerdown', outside); document.removeEventListener('keydown', escape) }
  }, [open])
  function toggle() {
    setOpenKey(open ? null : location.key)
    if (!open) state.refreshCount()
  }
  return <div className="notification-feature notification-bell" ref={root}>
    <button ref={button} className="notification-bell-button" aria-expanded={open} aria-controls={id} aria-label={`Njoftimet, ${state.count === null ? (state.countError ? 'numri i padisponueshëm' : 'duke ngarkuar numrin') : `${state.count} të palexuara`}`} onClick={toggle}>
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Z" /><path d="M10 21h4" /></svg>
      {state.count > 0 && <span className="notification-badge" aria-hidden="true">{state.count > 99 ? '99+' : state.count}</span>}
    </button>
    <span className="notification-sr-only" aria-live="polite" aria-atomic="true">{state.count !== null && `${state.count} njoftime të palexuara`}</span>
    {open && <section className="notification-dropdown" ref={panel} tabIndex={-1} id={id} aria-label="Njoftimet e fundit">
      <div className="notification-dropdown-heading"><h2>Njoftimet</h2><button className="notification-read" disabled={state.busy || state.count === 0} onClick={state.markAll}>Lexoji të gjitha</button></div>
      {state.countError && <div role="alert"><p>{state.countError}</p><button className="admin-button" onClick={() => state.refreshCount()}>Përditëso numrin</button></div>}
      {state.mutationError && <p role="alert">{state.mutationError}</p>}
      <NotificationList {...list} onNavigate={() => setOpenKey(null)} />
      <Link className="notification-view-all" to="/notifications" onClick={() => setOpenKey(null)}>Shiko të gjitha njoftimet →</Link>
    </section>}
  </div>
}
