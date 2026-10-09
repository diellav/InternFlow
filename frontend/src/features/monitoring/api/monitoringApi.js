import { apiClient } from '../../../shared/api/apiClient'
import { normalizeApiError } from '../../../shared/api/apiError'

async function get(path, options) {
  try { return (await apiClient.get(`/api/coordinator/monitoring${path}`, options)).data }
  catch (failure) { throw normalizeApiError(failure) }
}

export const listMonitoringInternships = (_, params, signal) => get('/internships', { params, signal })
export const getMonitoringInternship = async (id, signal) => (await get(`/internships/${id}`, { signal })).data
export const listMonitoringActivities = (id, params, signal) => get(`/internships/${id}/activities`, { params, signal })
export const getMonitoringActivity = async (id, signal) => (await get(`/activities/${id}`, { signal })).data
export const listMonitoringTasks = (id, params, signal) => get(`/internships/${id}/tasks`, { params, signal })
export const getMonitoringTask = async (id, signal) => (await get(`/tasks/${id}`, { signal })).data
export const listMonitoringSubmissions = (id, params, signal) => get(`/tasks/${id}/submissions`, { params, signal })

export function monitoringErrorMessage(error) {
  if (error.status === 401) return 'Sesioni ka përfunduar. Kyçuni përsëri.'
  if (error.status === 403) return 'Nevojitet një llogari aktive me profil koordinatori akademik.'
  if (error.status === 404) return 'Praktika ose regjistri nuk është i disponueshëm në monitorimin tuaj.'
  if (error.status === 422) return 'Kontrolloni fushat dhe provoni përsëri.'
  return 'Monitorimi nuk u ngarkua. Provoni përsëri.'
}
