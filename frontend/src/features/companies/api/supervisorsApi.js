import { apiClient } from '../../../shared/api/apiClient'
import { normalizeApiError } from '../../../shared/api/apiError'
import { initializeCsrf } from '../../auth/api/authApi'
import { companyErrorMessage } from './companiesApi'

export async function listSupervisors(params, signal) {
  try {
    return (await apiClient.get('/api/admin/supervisors', { params, signal })).data
  } catch (error) { throw normalizeApiError(error) }
}

export async function getSupervisor(id, signal) {
  try {
    return (await apiClient.get(`/api/admin/supervisors/${id}`, { signal })).data.data
  } catch (error) { throw normalizeApiError(error) }
}

export async function verifySupervisor(id, payload) {
  try {
    await initializeCsrf()
    return (await apiClient.patch(`/api/admin/supervisors/${id}/verification`, payload)).data.data
  } catch (error) { throw normalizeApiError(error) }
}

export function supervisorErrorMessage(error) {
  if (error.status === 404) return 'Mbikëqyrësi nuk u gjet.'
  if (error.status === 409) return 'Mbikëqyrësi nuk është në pritje të shqyrtimit ose profili mungon. Të dhënat po rifreskohen.'
  return companyErrorMessage(error)
}
