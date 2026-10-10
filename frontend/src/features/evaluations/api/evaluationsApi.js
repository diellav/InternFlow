import { apiClient } from '../../../shared/api/apiClient'
import { normalizeApiError } from '../../../shared/api/apiError'
import { initializeCsrf } from '../../auth/api/authApi'

async function request(method, id, payload, signal) {
  const path = `/api/supervisor/internships/${id}/final-evaluation`
  try {
    if (method === 'get') return (await apiClient.get(path, { signal })).data.data
    await initializeCsrf()
    return (await apiClient[method](method === 'post' ? `${path}/submit` : path, payload)).data.data
  } catch (error) { throw normalizeApiError(error) }
}

export const getEvaluation = (id, signal) => request('get', id, undefined, signal)
export const saveEvaluation = (id, payload) => request('put', id, payload)
export const submitEvaluation = (id, token) => request('post', id, { draft_token: token })

export function evaluationErrorMessage(error) {
  if (error.status === 401) return 'Sesioni ka përfunduar. Kyçuni përsëri.'
  if (error.status === 403) return 'Nevojitet një mbikëqyrës aktiv dhe i miratuar, me kompani aktive e të miratuar.'
  if (error.status === 404) return 'Praktika ose vlerësimi nuk është i disponueshëm për ju.'
  if (error.status === 409) return 'Gjendja ose drafti ka ndryshuar. Kontrolloni të dhënat e rifreskuara.'
  if (error.status === 422) return 'Kontrolloni kriteret dhe komentin përfundimtar.'
  if (error.status === 419) return 'Sesioni nuk mund të verifikohej. Rifreskoni faqen.'
  return 'Vlerësimi nuk u ruajt ose nuk u ngarkua. Kontrolloni lidhjen dhe provoni përsëri.'
}
