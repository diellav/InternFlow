import { Link } from 'react-router-dom'
import { useAuth } from '../../auth/hooks/useAuth'
import { PageHeading } from '../components/UserUi'

export function AdminHomePage() {
  const { user } = useAuth()
  return <><PageHeading title={`Mirë se vini, ${user.first_name}`} description="Një hapësirë për të menaxhuar llogaritë dhe ekipin akademik." />
    <section className="admin-welcome"><div><span className="admin-eyebrow">Qasje dhe organizim</span><h2>Njerëzit në qendër të sistemit.</h2><p>Shikoni përdoruesit, administroni qasjen dhe krijoni llogaritë e koordinatorëve akademikë.</p><Link className="admin-button admin-primary" to="/admin/users">Shiko përdoruesit <span aria-hidden="true">→</span></Link></div><div className="welcome-emblem" aria-hidden="true">IF</div></section>
    <div className="admin-home-grid"><Link className="admin-navigation-card" to="/admin/users"><span className="card-number">01 / Përdoruesit</span><h2>Llogaritë e sistemit</h2><p>Kërkoni sipas emrit, filtroni sipas rolit dhe menaxhoni statusin e llogarive.</p><span className="card-link">Hap listën →</span></Link><Link className="admin-navigation-card" to="/admin/academic-coordinators/new"><span className="card-number">02 / Ekipi akademik</span><h2>Shtoni një koordinator</h2><p>Krijoni një llogari të sigurt për koordinimin akademik të praktikave.</p><span className="card-link">Krijo llogari →</span></Link></div>
  </>
}
