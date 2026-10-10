import { useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../../features/auth/hooks/useAuth'
import { NotificationBell } from '../../features/notifications/components/NotificationBell'
import '../../features/auth/styles/auth.css'
import '../../features/users/styles/admin.css'

export function AdminLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)
  const [loggingOut, setLoggingOut] = useState(false)
  const [error, setError] = useState('')

  async function exit() {
    setLoggingOut(true)
    try {
      await logout()
      navigate('/login', { replace: true })
    } catch {
      setError('Dalja nuk u krye. Provoni përsëri.')
      setLoggingOut(false)
    }
  }

  return <div className="admin-shell">
    <aside className={`admin-sidebar ${mobileOpen ? 'mobile-open' : ''}`} id="admin-navigation">
      <NavLink to="/admin" className="admin-brand" onClick={() => setMobileOpen(false)}><span className="brand-mark" aria-hidden="true">IF</span>InternFlow</NavLink>
      <p className="sidebar-caption">Hapësira e administrimit</p>
      <nav aria-label="Navigimi i administratorit">
        <NavLink to="/admin" end onClick={() => setMobileOpen(false)}><span aria-hidden="true">01</span>Përmbledhja</NavLink>
        <NavLink to="/admin/users" onClick={() => setMobileOpen(false)}><span aria-hidden="true">02</span>Përdoruesit</NavLink>
        <NavLink to="/admin/companies" onClick={() => setMobileOpen(false)}><span aria-hidden="true">03</span>Kompanitë</NavLink>
        <NavLink to="/admin/academic-coordinators/new" onClick={() => setMobileOpen(false)}><span aria-hidden="true">04</span>Krijo koordinator</NavLink>
        <NavLink to="/profile" onClick={() => setMobileOpen(false)}><span aria-hidden="true">05</span>Profili im</NavLink>
        <NavLink to="/admin/supervisors" onClick={() => setMobileOpen(false)}><span aria-hidden="true">06</span>Mbikëqyrësit</NavLink>
      </nav>
      <div className="sidebar-footer"><p>Praktika të organizuara.<br />Qasje të qarta.</p><span>InternFlow · Administrimi</span></div>
    </aside>
    <div className="admin-workspace">
      <header className="admin-topbar"><button className="admin-button mobile-menu" aria-expanded={mobileOpen} aria-controls="admin-navigation" onClick={() => setMobileOpen(!mobileOpen)}>{mobileOpen ? 'Mbyll menynë' : 'Menyja'}</button><span className="topbar-label">Menaxhimi i përdoruesve</span><div className="topbar-account"><span className="user-avatar" aria-hidden="true">{user.first_name?.[0]}{user.last_name?.[0]}</span><div><strong>{user.first_name} {user.last_name}</strong><small>Administrator</small></div><NotificationBell /><button className="admin-button" onClick={exit} disabled={loggingOut}>{loggingOut ? 'Duke dalë…' : 'Dil'}</button></div></header>
      <main className="admin-main" id="admin-content">{error && <p className="form-alert" role="alert">{error}</p>}<Outlet /></main>
    </div>
  </div>
}
