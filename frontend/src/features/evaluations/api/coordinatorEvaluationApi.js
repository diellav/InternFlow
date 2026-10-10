import { apiClient } from '../../../shared/api/apiClient'
import { normalizeApiError } from '../../../shared/api/apiError'
import { initializeCsrf } from '../../auth/api/authApi'

export async function getCoordinatorEvaluation(id, signal) {
  try { return (await apiClient.get(`/api/coordinator/monitoring/internships/${id}/final-evaluation`, { signal })).data.data }
  catch (error) { throw normalizeApiError(error) }
}

export async function completeInternship(id, evaluationId) {
  try {
    await initializeCsrf()
    return (await apiClient.post(`/api/coordinator/internships/${id}/complete`, { expected_evaluation_id: evaluationId })).data.data
  } catch (error) { throw normalizeApiError(error) }
}

export function completionErrorMessage(error) {
  if (error.status === 401) return 'Sesioni ka përfunduar. Kyçuni përsëri.'
  if (error.status === 403) return 'Nevojitet një llogari aktive me profil koordinatori akademik.'
  if (error.status === 404) return 'Praktika ose vlerësimi nuk është i disponueshëm në monitorimin tuaj.'
  if (error.status === 409) return 'Gjendja ose vlerësimi ka ndryshuar. Kontrolloni të dhënat e rifreskuara përpara veprimit tjetër.'
  if (error.status === 422) return 'Të dhënat e konfirmimit nuk janë të vlefshme. Rifreskoni vlerësimin.'
  if (error.status === 419) return 'Sesioni nuk mund të verifikohej. Rifreskoni faqen.'
  return 'Vlerësimi ose përfundimi nuk u ngarkua. Kontrolloni lidhjen dhe provoni përsëri.'
}
