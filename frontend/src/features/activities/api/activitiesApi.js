import { apiClient } from '../../../shared/api/apiClient'
import { normalizeApiError } from '../../../shared/api/apiError'
import { initializeCsrf } from '../../auth/api/authApi'
import { listInternships } from '../../internships/api/internshipsApi'

async function request(method, path, options) {
  try {
    if (['post', 'patch'].includes(method)) await initializeCsrf()
    return (await apiClient[method](path, options)).data
  } catch (failure) { throw normalizeApiError(failure) }
}

export const listActivities = (id, params, signal) => request('get', `/api/student/internships/${id}/activities`, { params, signal })
export const getActivity = async (id, signal) => (await request('get', `/api/student/activities/${id}`, { signal })).data
export const getActivityInternship = async (id, signal) => (await listActivities(id, { per_page: 1 }, signal)).internship
export const saveActivity = async (internshipId, id, payload) => (await request(id ? 'patch' : 'post', id ? `/api/student/activities/${id}` : `/api/student/internships/${internshipId}/activities`, payload)).data
export const listSupervisorActivities = (id, params, signal) => request('get', `/api/supervisor/internships/${id}/activities`, { params, signal })
export const getSupervisorActivity = async (id, signal) => (await request('get', `/api/supervisor/activities/${id}`, { signal })).data

export function supervisorActivityErrorMessage(error) {
  if (error.status === 403) return 'Nevojitet një llogari aktive mbikëqyrësi me profil dhe kompani të miratuar.'
  if (error.status === 404) return 'Aktiviteti ose praktika aktive nuk u gjet në praktikat tuaja të caktuara.'
  return activityErrorMessage(error)
}

export async function activeActivityInternships(signal) {
  const records = []
  let page = 1
  while (!signal?.aborted) {
    const response = await listInternships({ status: 'ACTIVE', per_page: 100, page }, signal)
    records.push(...response.data)
    if (page >= response.meta.last_page) break
    page++
  }
  return records
}

export function activityErrorMessage(error) {
  if (error.status === 401) return 'Sesioni ka përfunduar. Kyçuni përsëri.'
  if (error.status === 403) return 'Nevojitet një llogari aktive me profil studenti.'
  if (error.status === 404) return 'Aktiviteti ose praktika nuk u gjet ose nuk është aktive për ju.'
  if (error.status === 409) return 'Gjendja e praktikës ka ndryshuar. Aktivitetet ndryshohen vetëm në praktikë aktive.'
  if (error.status === 422) return 'Kontrolloni fushat dhe provoni përsëri.'
  if (error.status === 419) return 'Rifreskoni faqen për të verifikuar sesionin.'
  return 'Veprimi nuk u krye. Kontrolloni lidhjen dhe provoni përsëri.'
}
