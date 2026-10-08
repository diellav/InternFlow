import { verificationLabels } from '../constants/verificationLabels'
import '../styles/companies.css'

export function VerificationBadge({ status }) {
  return <span className={`verification-badge verification-${status.toLowerCase()}`}>{verificationLabels[status] ?? status}</span>
}
