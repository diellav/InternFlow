import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../../auth/hooks/useAuth'
import { PageHeading } from '../../users/components/UserUi'
import { getInternship, internshipErrorMessage } from '../api/internshipsApi'
import { InternshipForm } from '../components/InternshipForm'
import { InternshipDecisionSummary } from '../components/InternshipDecisionSummary'

export function InternshipDraftPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { refreshUser } = useAuth()
  const [internship, setInternship] = useState(null)
  const [loading, setLoading] = useState(Boolean(id))
  const [error, setError] = useState('')
  const [revision, setRevision] = useState(0)
  useEffect(() => {
    if (!id) return
    const controller = new AbortController()
    queueMicrotask(async () => {
      if (controller.signal.aborted) return
      setLoading(true); setError('')
      try {
        const data = await getInternship(id, controller.signal)
        if (!controller.signal.aborted) {
          if (!['DRAFT', 'REVISION_REQUIRED'].includes(data.status)) navigate(`/student/internships/${id}`, { replace: true, state: { conflict: 'Ky aplikim nuk është i redaktueshëm dhe është vetëm për lexim.' } })
          else setInternship(data)
        }
      } catch (failure) {
        if (controller.signal.aborted) return
        setError(internshipErrorMessage(failure))
        if ([401, 403].includes(failure.status)) await refreshUser()
      } finally { if (!controller.signal.aborted) setLoading(false) }
    })
    return () => controller.abort()
  }, [id, revision, navigate, refreshUser])
  if (id && (loading || (!error && internship?.id !== Number(id)))) return <p role="status">Duke ngarkuar draftin…</p>
  if (error) return <section className="internship-empty"><p role="alert">{error}</p><button className="admin-button" onClick={() => setRevision((current) => current + 1)}>Provo përsëri</button><Link to="/student/internships">Kthehu te aplikimet</Link></section>
  return <>
    <Link className="internship-back" to={id ? `/student/internships/${id}` : '/student/internships'}>← {id ? 'Detajet e aplikimit' : 'Praktikat e mia'}</Link>
    <PageHeading eyebrow="Përgatitja e aplikimit" title={internship?.status === 'REVISION_REQUIRED' ? 'Korrigjo aplikimin' : id ? 'Ndrysho draftin' : 'Regjistro praktikën tënde'} description="Praktikën e keni organizuar jashtë platformës. Këtu ruani informacionin për procesin akademik." />
    {internship?.status === 'REVISION_REQUIRED' && <InternshipDecisionSummary internship={internship} student />}
    <InternshipForm key={id ?? 'new'} internship={id ? internship : null} />
  </>
}
