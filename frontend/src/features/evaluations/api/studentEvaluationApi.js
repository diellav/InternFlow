import { apiClient } from '../../../shared/api/apiClient'
import { normalizeApiError } from '../../../shared/api/apiError'

export async function getStudentEvaluation(id, signal) {
  try { return (await apiClient.get(`/api/student/internships/${id}/final-evaluation`, { signal })).data.data }
  catch (failure) { throw normalizeApiError(failure) }
}

export function studentEvaluationErrorMessage(error) {
  if (error.status === 404) return 'Vlerësimi përfundimtar nuk është ende i disponueshëm për ju.'
  if (error.status === 401) return 'Sesioni ka përfunduar. Kyçuni përsëri.'
  if (error.status === 403) return 'Nevojitet një llogari aktive me profil studenti.'
  return 'Vlerësimi nuk u ngarkua. Kontrolloni lidhjen dhe provoni përsëri.'
}
