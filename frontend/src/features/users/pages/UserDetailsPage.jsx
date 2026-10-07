import { useEffect, useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import { useAuth } from '../../auth/hooks/useAuth'
import { errorMessage, getUser } from '../api/usersApi'
import { ActivationDialog } from '../components/ActivationDialog'
import { AccountBadge, PageHeading } from '../components/UserUi'
import { roleLabels } from '../constants/userLabels'

function Detail({ label, children }) {
  return <div><dt>{label}</dt><dd>{children ?? 'Nuk është dhënë'}</dd></div>
}

export function UserDetailsPage() {
  const { id } = useParams()
  const location = useLocation()
  const { user: currentUser } = useAuth()
  const [user, setUser] = useState(null)
  const [error, setError] = useState('')
  const [target, setTarget] = useState(null)
  const [notice, setNotice] = useState(location.state?.success ?? '')
  const [revision, setRevision] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(() => {
      if (!controller.signal.aborted) { setUser(null); setError('') }
    })
    getUser(id, controller.signal).then((response) => { if (!controller.signal.aborted) setUser(response) }).catch((requestError) => { if (!controller.signal.aborted) setError(errorMessage(requestError)) })
    return () => controller.abort()
  }, [id, revision])

  if (error) return <div className="admin-panel admin-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRevision((value) => value + 1)}>Provo përsëri</button><Link to="/admin/users">Kthehu te përdoruesit</Link></div>
  if (!user) return <p role="status">Duke ngarkuar llogarinë…</p>
  const profile = user.profile
  const company = profile?.company
  return <>
    <Link className="admin-back-link" to="/admin/users">← Të gjithë përdoruesit</Link>
    <PageHeading title={`${user.first_name} ${user.last_name}`} description={roleLabels[user.role]}>{user.role === 'ACADEMIC_COORDINATOR' && <Link className="admin-button" to={`/admin/academic-coordinators/${user.id}/edit`}>Ndrysho koordinatorin</Link>}<button className="admin-button admin-primary" disabled={currentUser.id === user.id && user.is_active} onClick={() => setTarget(user)}>{user.is_active ? 'Çaktivizo llogarinë' : 'Aktivizo llogarinë'}</button></PageHeading>
    {notice && <p className="form-success" role="status">{notice}</p>}
    <div className="admin-detail-grid"><section className="admin-panel detail-panel"><h2>Të dhënat e llogarisë</h2><dl className="admin-detail-list"><Detail label="Emri">{user.first_name}</Detail><Detail label="Mbiemri">{user.last_name}</Detail><Detail label="Email">{user.email}</Detail><Detail label="Telefoni">{user.phone}</Detail><Detail label="Roli">{roleLabels[user.role]}</Detail><Detail label="Statusi"><AccountBadge active={user.is_active} /></Detail></dl></section>
    {user.role !== 'ADMIN' && <section className="admin-panel detail-panel"><h2>Profili {user.role === 'STUDENT' ? 'i studentit' : user.role === 'ACADEMIC_COORDINATOR' ? 'akademik' : 'i mbikëqyrësit'}</h2>{!profile ? <p className="admin-muted">Profili nuk është regjistruar ende.</p> : <dl className="admin-detail-list">{user.role === 'STUDENT' && <><Detail label="Numri i studentit">{profile.student_number}</Detail><Detail label="Programi i studimit">{profile.study_program}</Detail><Detail label="Viti i studimit">{profile.study_year}</Detail></>}{user.role === 'ACADEMIC_COORDINATOR' && <Detail label="Njësia akademike">{profile.academic_unit}</Detail>}{user.role === 'COMPANY_SUPERVISOR' && <><Detail label="Pozita">{profile.job_title}</Detail><Detail label="Verifikimi">{{ PENDING: 'Në pritje', APPROVED: 'I miratuar', REJECTED: 'I refuzuar' }[profile.verification_status]}</Detail><Detail label="Kompania">{company?.name}</Detail>{company && <><Detail label="Statusi i kompanisë"><AccountBadge active={company.is_active} /></Detail><Detail label="Verifikimi i kompanisë">{{ PENDING: 'Në pritje', APPROVED: 'E miratuar', REJECTED: 'E refuzuar' }[company.verification_status]}</Detail></>}</>}</dl>}</section>}</div>
    {target && <ActivationDialog user={target} onClose={() => setTarget(null)} onUpdated={(updated) => { setUser((current) => ({ ...current, ...updated })); setNotice('Statusi i llogarisë u përditësua.') }} />}
  </>
}
