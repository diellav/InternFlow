import { internshipStatusLabels } from '../constants/internshipStatusLabels'

export function InternshipStatusBadge({ status }) {
  return <span className={`internship-status internship-status-${status?.toLowerCase()}`}>{internshipStatusLabels[status] ?? status}</span>
}
