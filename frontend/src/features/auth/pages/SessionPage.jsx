import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { USER_ROLES, VERIFICATION_STATUSES } from '../constants/authConstants'
import { useAuth } from '../hooks/useAuth'
import '../styles/auth.css'

function statusText(status) {
  return {
    [VERIFICATION_STATUSES.PENDING]: 'Në pritje të verifikimit',
    [VERIFICATION_STATUSES.APPROVED]: 'E verifikuar',
    [VERIFICATION_STATUSES.REJECTED]: 'E refuzuar',
  }[status]
}

export function SessionPage() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [isLoggingOut, setIsLoggingOut] = useState(false)
  const [logoutError, setLogoutError] = useState('')
  const supervisorStatus =
    user.role === USER_ROLES.COMPANY_SUPERVISOR
      ? user.profile?.verification_status
      : null

  async function handleLogout() {
    setIsLoggingOut(true)
    setLogoutError('')

    try {
      await logout()
      navigate('/login', { replace: true })
    } catch {
      setLogoutError('Nuk ishte e mundur të dilni. Ju lutem provoni përsëri.')
      setIsLoggingOut(false)
    }
  }

  return (
    <main className="auth-shell">
      <section className="auth-card session-card" aria-labelledby="session-heading">
        <p className="auth-brand">InternFlow</p>
        <p className="session-label">Sesioni i autentikuar</p>
        <h1 id="session-heading">
          {user.first_name} {user.last_name}
        </h1>

        <dl className="session-details">
          <div>
            <dt>Email</dt>
            <dd>{user.email}</dd>
          </div>
          <div>
            <dt>Roli</dt>
            <dd>{user.role}</dd>
          </div>
          {supervisorStatus ? (
            <div>
              <dt>Statusi i mbikëqyrësit</dt>
              <dd>{statusText(supervisorStatus) ?? supervisorStatus}</dd>
            </div>
          ) : null}
        </dl>

        {supervisorStatus === VERIFICATION_STATUSES.PENDING ? (
          <p className="status-note" role="status">
            Regjistrimi juaj është në pritje të miratimit. Mund të qëndroni të
            kyçur, por funksionet operative do të jenë të kufizuara.
          </p>
        ) : null}

        {logoutError ? (
          <div className="form-alert" role="alert">
            {logoutError}
          </div>
        ) : null}

        <Link className="primary-button session-profile-link" to="/profile">Profili im</Link>

        <button
          className="secondary-button"
          type="button"
          onClick={handleLogout}
          disabled={isLoggingOut}
        >
          {isLoggingOut ? 'Duke dalë…' : 'Dil nga llogaria'}
        </button>
      </section>
    </main>
  )
}
