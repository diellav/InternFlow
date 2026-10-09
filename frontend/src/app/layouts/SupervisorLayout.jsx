import { useRef, useState } from 'react'
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom'
import { useAuth } from '../../features/auth/hooks/useAuth'
import '../../features/users/styles/admin.css'
import '../../features/users/styles/profile.css'
import '../../features/internships/styles/internships.css'
import '../../features/tasks/styles/tasks.css'

export function SupervisorLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const pending = useRef(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function exit() {
    if (pending.current) return
    pending.current = true; setBusy(true); setError('')
    try { await logout(); navigate('/login', { replace: true }) }
    catch { setError('Dalja nuk u krye. Provoni përsëri.'); pending.current = false; setBusy(false) }
  }
  return <div className="student-shell supervisor-shell"><header className="student-header"><Link className="student-brand" to="/supervisor/internships"><span aria-hidden="true">IF</span>InternFlow</Link><nav aria-label="Navigimi i mbikëqyrësit"><NavLink to="/supervisor/internships">Praktikat e caktuara</NavLink><NavLink to="/supervisor/verification">Verifikimi</NavLink><NavLink to="/profile">Profili</NavLink><NavLink to="/session">Llogaria ime</NavLink></nav><div className="student-account"><span>{user.first_name} {user.last_name}<small>Mbikëqyrës kompanie</small></span><button className="admin-button" disabled={busy} onClick={exit}>{busy ? 'Duke dalë…' : 'Dil'}</button></div></header><main className="student-main">{error && <p className="form-alert" role="alert">{error}</p>}<Outlet /></main><footer className="student-footer">InternFlow · Mbikëqyrja e praktikës profesionale</footer></div>
}
