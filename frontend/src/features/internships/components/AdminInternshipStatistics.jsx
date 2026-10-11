import { useEffect, useState } from 'react'
import { adminInternshipError, getAdminInternshipStatistics } from '../api/adminInternshipsApi'
import '../styles/adminInternships.css'

export function AdminInternshipStatistics() {
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    getAdminInternshipStatistics(controller.signal).then(result => {
      if (!controller.signal.aborted) { setData(result); setError('') }
    }).catch(failure => { if (!controller.signal.aborted) setError(adminInternshipError(failure)) })
    return () => controller.abort()
  }, [retry])
  return <section className="admin-internship-summary" aria-label="Statistikat e praktikave">
    <h2>Praktikat</h2>
    <p className="admin-muted">Në pritje të shqyrtimit: aplikimet e dorëzuara (SUBMITTED) dhe në shqyrtim (UNDER_REVIEW).</p>
    {error ? <div role="alert">{error} <button className="admin-button" onClick={() => setRetry(value => value + 1)}>Provo përsëri</button></div> : !data ? <p role="status">Duke ngarkuar statistikat…</p> : <dl className="admin-internship-stats">
      {[['total', 'Gjithsej praktika'], ['pending_review', 'Në pritje të shqyrtimit'], ['active', 'Praktika aktive'], ['completed', 'Praktika të përfunduara']].map(([key, label]) => <div className="admin-panel" key={key}><dt>{label}</dt><dd>{data[key]}</dd></div>)}
    </dl>}
  </section>
}
