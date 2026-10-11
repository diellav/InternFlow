import { apiClient } from '../../../shared/api/apiClient'
import { normalizeApiError } from '../../../shared/api/apiError'

export async function listAuditLogs(params, signal) {
  try { return (await apiClient.get('/api/admin/audit-logs', { params, signal })).data }
  catch (error) { throw normalizeApiError(error) }
}

export function auditLogsError(error) {
  if (error.status === 401) return 'Sesioni ka përfunduar. Kyçuni përsëri.'
  if (error.status === 403) return 'Nevojitet një llogari aktive administratori.'
  if (error.status === 422) return 'Kontrolloni filtrat dhe periudhën e zgjedhur.'
  return 'Historiku nuk u ngarkua. Provoni përsëri.'
}
