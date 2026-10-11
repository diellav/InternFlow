import { NavLink } from 'react-router-dom'
import { useMessaging } from '../context/MessagingContext'
import '../styles/messaging.css'

export function MessagesNavigation() {
  const { count } = useMessaging()
  return <NavLink className="messages-navigation" to="/messages"><svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18"><path d="M3 4h18v13H8l-5 4V4Z" fill="none" stroke="currentColor" strokeWidth="1.6" /></svg>Mesazhet{count > 0 && <span className="messages-badge" aria-label={`${count} biseda të palexuara`}>{count > 99 ? '99+' : count}</span>}</NavLink>
}
