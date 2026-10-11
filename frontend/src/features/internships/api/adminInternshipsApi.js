import { apiClient } from '../../../shared/api/apiClient'
import { normalizeApiError } from '../../../shared/api/apiError'

async function get(path, options) {
  try { return (await apiClient.get(`/api/admin/internships${path}`, options)).data }
  catch (error) { throw normalizeApiError(error) }
}

export const listAdminInternships = (params, signal) => get('', { params, signal })
export const getAdminInternship = async (id, signal) => (await get(`/${id}`, { signal })).data
export const getAdminInternshipStatistics = async signal => (await get('/statistics', { signal })).data

export function adminInternshipError(error) {
  if (error.status === 404) return 'Praktika nuk u gjet.'
  if (error.status === 401) return 'Sesioni ka përfunduar. Kyçuni përsëri.'
  if (error.status === 403) return 'Nevojitet një llogari aktive administratori.'
  return 'Të dhënat nuk u ngarkuan. Provoni përsëri.'
}
