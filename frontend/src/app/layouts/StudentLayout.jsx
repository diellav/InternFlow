import { useState } from 'react'
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../../features/auth/hooks/useAuth'
import '../../features/users/styles/admin.css'
import '../../features/internships/styles/internships.css'

export function StudentLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function exit() {
    if (busy) return
    setBusy(true)
    try { await logout(); navigate('/login', { replace: true }) }
    catch { setError('Dalja nuk u krye. Provoni përsëri.'); setBusy(false) }
  }

  return <div className="student-shell">
    <header className="student-header">
      <Link className="student-brand" to="/student/internships"><span aria-hidden="true">IF</span>InternFlow</Link>
      <nav aria-label="Navigimi i studentit"><NavLink to="/student/internships">Praktikat e mia</NavLink><NavLink to="/student/activities">Aktivitetet e mia</NavLink><NavLink to="/profile">Profili</NavLink><NavLink to="/session">Llogaria</NavLink></nav>
      <div className="student-account"><span>{user.first_name} {user.last_name}<small>Student</small></span><button className="admin-button" disabled={busy} onClick={exit}>{busy ? 'Duke dalë…' : 'Dil'}</button></div>
    </header>
    <main className="student-main">{error && <p className="form-alert" role="alert">{error}</p>}<Outlet /></main>
    <footer className="student-footer">InternFlow · Praktika profesionale dhe procesi akademik</footer>
  </div>
}
