import { apiClient } from '../../../shared/api/apiClient'
import { normalizeApiError } from '../../../shared/api/apiError'
import { initializeCsrf } from '../../auth/api/authApi'

async function request(method, path, options) {
  try {
    if (['post', 'patch'].includes(method)) await initializeCsrf()
    return (await apiClient[method](path, options)).data
  } catch (error) { throw normalizeApiError(error) }
}

export const listInternships = (params, signal) => request('get', '/api/student/internships', { params, signal })
export const getInternship = async (id, signal) => (await request('get', `/api/student/internships/${id}`, { signal })).data
export const saveInternship = async (id, payload) => (await request(id ? 'patch' : 'post', `/api/student/internships${id ? `/${id}` : ''}`, payload)).data
export const getInternshipCompanies = async (signal) => (await request('get', '/api/student/internship-options/companies', { signal })).data
export const getInternshipSupervisors = async (id, signal) => (await request('get', `/api/student/internship-options/companies/${id}/supervisors`, { signal })).data
export const submitInternship = async (id) => (await request('post', `/api/student/internships/${id}/submit`, {})).data
export const resubmitInternship = async (id) => (await request('post', `/api/student/internships/${id}/resubmit`, {})).data
export const listCoordinatorInternships = (params, signal) => request('get', '/api/coordinator/internships', { params, signal })
export const getCoordinatorInternship = async (id, signal) => (await request('get', `/api/coordinator/internships/${id}`, { signal })).data
export const claimInternship = async (id) => (await request('post', `/api/coordinator/internships/${id}/claim`, {})).data
export const startInternshipReview = async (id) => (await request('post', `/api/coordinator/internships/${id}/start-review`, {})).data
export const decideInternship = async (id, payload) => (await request('post', `/api/coordinator/internships/${id}/decision`, payload)).data

export function reviewErrorMessage(error) {
  if (error.status === 409) return 'Statusi ose caktimi i aplikimit ka ndryshuar. Të dhënat po rifreskohen.'
  return coordinatorErrorMessage(error)
}

export function coordinatorErrorMessage(error) {
  if (error.status === 403) return 'Nevojitet një llogari aktive me profil koordinatori akademik.'
  if (error.status === 409) return 'Aplikimi nuk është më i disponueshëm për marrje. Rifreskoni kutinë hyrëse.'
  return internshipErrorMessage(error)
}

export function internshipErrorMessage(error) {
  if (error.status === 401) return 'Sesioni ka përfunduar. Kyçuni përsëri.'
  if (error.status === 403) return 'Nuk keni qasje në këtë veprim. Nevojitet një llogari aktive me profil studenti.'
  if (error.status === 404) return 'Aplikimi ose opsioni i përzgjedhur nuk u gjet ose nuk është i disponueshëm.'
  if (error.status === 409) return 'Statusi i aplikimit ka ndryshuar dhe veprimi nuk është më i disponueshëm. Të dhënat po rifreskohen.'
  if (error.status === 422) return 'Kontrolloni fushat dhe provoni përsëri.'
  if (error.status === 419) return 'Sesioni nuk mund të verifikohej. Rifreskoni faqen dhe provoni përsëri.'
  return 'Veprimi nuk u krye. Kontrolloni lidhjen dhe provoni përsëri.'
}
