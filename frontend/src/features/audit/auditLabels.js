import { internshipStatusLabels } from '../internships/constants/internshipStatusLabels'

export const auditActionLabels = {
  'coordinator.created': 'Koordinatori u krijua', 'coordinator.updated': 'Koordinatori u përditësua',
  'user.activated': 'Llogaria u aktivizua', 'user.deactivated': 'Llogaria u çaktivizua',
  'company.approved': 'Kompania u miratua', 'company.rejected': 'Kompania u refuzua',
  'supervisor.approved': 'Mbikëqyrësi u miratua', 'supervisor.rejected': 'Mbikëqyrësi u refuzua',
  'company.application_updated': 'Aplikimi i kompanisë u korrigjua', 'company.resubmitted': 'Kompania riaplikoi',
  'supervisor.application_updated': 'Aplikimi i mbikëqyrësit u korrigjua', 'supervisor.resubmitted': 'Mbikëqyrësi riaplikoi',
  'internship.submitted': 'Aplikimi u dorëzua', 'internship.resubmitted': 'Aplikimi u ridorëzua',
  'internship.claimed': 'Aplikimi u mor për shqyrtim', 'internship.review_started': 'Shqyrtimi filloi',
  'internship.approved': 'Praktika u miratua', 'internship.rejected': 'Praktika u refuzua',
  'internship.revision_required': 'U kërkua korrigjim i aplikimit', 'internship.activated': 'Praktika u aktivizua',
  'internship.completed': 'Praktika u përfundua',
  'task.created': 'Detyra u krijua', 'task.updated': 'Detyra u përditësua', 'task.started': 'Detyra filloi',
  'task.submitted': 'Detyra u dorëzua', 'task.approved': 'Detyra u miratua', 'task.revision_required': 'U kërkua korrigjim i detyrës',
  'submission.created': 'Dorëzimi u krijua', 'activity.created': 'Aktiviteti u regjistrua', 'activity.updated': 'Aktiviteti u përditësua',
  'evaluation.submitted': 'Vlerësimi përfundimtar u dorëzua', 'internflow.demo.seed.v1': 'Të dhënat demo u shtuan',
}
export const auditEntityLabels = { user: 'Përdorues', company: 'Kompani', company_supervisor_profile: 'Mbikëqyrës', internship: 'Praktikë', task: 'Detyrë', task_submission: 'Dorëzim', activity_log: 'Aktivitet', final_evaluation: 'Vlerësim përfundimtar', demo_dataset: 'Të dhëna demo' }
const statuses = { ...internshipStatusLabels, PENDING: 'Në pritje', ASSIGNED: 'E caktuar', IN_PROGRESS: 'Në punim', CANCELLED: 'E anuluar' }
const fields = { status: 'Statusi', is_active: 'Llogaria', coordinator_id: 'Koordinatori', hours: 'Orët', version_no: 'Versioni', submitted: 'Dorëzimi' }

export function auditDetails(details) {
  const before = details?.before ?? {}, after = details?.after ?? {}
  const format = (key, value) => {
    if (value === null || value === undefined) return 'Pa caktuar'
    if (key === 'status') return statuses[value] ?? 'E panjohur'
    if (key === 'is_active') return value ? 'Aktive' : 'Joaktive'
    if (key === 'submitted') return value ? 'I dorëzuar' : 'Draft'
    if (key === 'coordinator_id') return `#${value}`
    return String(value)
  }
  return Object.entries(fields).filter(([key]) => Object.hasOwn(before, key) || Object.hasOwn(after, key))
    .map(([key, label]) => `${label}: ${Object.hasOwn(before, key) ? `${format(key, before[key])} → ` : ''}${format(key, after[key])}`)
}
