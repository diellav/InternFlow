import { apiClient } from '../../../shared/api/apiClient'
import { normalizeApiError } from '../../../shared/api/apiError'
import { initializeCsrf } from '../../auth/api/authApi'

async function request(method, path, options, config) {
  try {
    if (['post', 'patch'].includes(method)) await initializeCsrf()
    return (await apiClient[method](path, options, ...(config ? [config] : []))).data
  } catch (error) { throw normalizeApiError(error) }
}

export const listSupervisorInternships = (params, signal) => request('get', '/api/supervisor/internships', { params, signal })
export const getSupervisorInternship = async (id, signal) => (await request('get', `/api/supervisor/internships/${id}`, { signal })).data
export const activateInternship = async (id) => (await request('post', `/api/supervisor/internships/${id}/activate`, {})).data
export const listTasks = (portal, id, params, signal) => request('get', `/api/${portal}/internships/${id}/tasks`, { params, signal })
export const getSupervisorTask = async (id, signal) => (await request('get', `/api/supervisor/tasks/${id}`, { signal })).data
export const getStudentTask = async (id, signal) => (await request('get', `/api/student/tasks/${id}`, { signal })).data
export const saveTask = async (internshipId, id, payload) => (await request(id ? 'patch' : 'post', id ? `/api/supervisor/tasks/${id}` : `/api/supervisor/internships/${internshipId}/tasks`, payload)).data
export const startTask = async (id) => (await request('post', `/api/student/tasks/${id}/start`, {})).data
function submissionBody(payload) {
  if (!payload.files?.length) return { body: payload }
  const body = new FormData()
  body.append('submission_text', payload.submission_text)
  if (payload.resource_url) body.append('resource_url', payload.resource_url)
  if (payload.expected_submission_id) body.append('expected_submission_id', String(payload.expected_submission_id))
  payload.files.forEach((file) => body.append('files[]', file))
  return { body, config: { headers: { 'Content-Type': undefined } } }
}

async function sendSubmission(path, payload, onUploadProgress) {
  const { body, config } = submissionBody(payload)
  return (await request('post', path, body, config ? { ...config, onUploadProgress } : undefined)).data
}

export const submitTask = (id, payload, onUploadProgress) => sendSubmission(`/api/student/tasks/${id}/submissions`, payload, onUploadProgress)
export const listTaskSubmissions = (portal, id, page, signal) => request('get', `/api/${portal}/tasks/${id}/submissions`, { params: { page }, signal })
export const reviewTask = async (id, payload) => (await request('post', `/api/supervisor/tasks/${id}/review`, payload)).data
export const resubmitTask = (id, payload, onUploadProgress) => sendSubmission(`/api/student/tasks/${id}/resubmit`, payload, onUploadProgress)

export const taskFileUrl = (id) => apiClient.getUri({ url: `/api/task-submission-files/${id}/download` })

export async function downloadTaskFile(file) {
  try {
    const response = await apiClient.get(`/api/task-submission-files/${file.id}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data)
    const anchor = document.createElement('a')
    anchor.href = url; anchor.download = file.original_name; document.body.append(anchor); anchor.click(); anchor.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  } catch (error) { throw normalizeApiError(error) }
}

export function taskErrorMessage(error) {
  if (error.status === 401) return 'Sesioni ka përfunduar. Kyçuni përsëri.'
  if (error.status === 403) return 'Nuk keni qasje operative. Mbikëqyrësi dhe kompania duhet të jenë aktivë e të miratuar; studentit i nevojitet profil i vlefshëm.'
  if (error.status === 404) return 'Praktika ose detyra nuk u gjet ose nuk është e disponueshme për ju.'
  if (error.status === 409) return 'Gjendja ka ndryshuar dhe veprimi nuk është më i disponueshëm. Të dhënat po rifreskohen.'
  if (error.status === 422) return 'Kontrolloni fushat dhe kushtet e veprimit, pastaj provoni përsëri.'
  if (error.status === 419) return 'Sesioni nuk mund të verifikohej. Rifreskoni faqen.'
  return 'Veprimi nuk u krye. Kontrolloni lidhjen dhe provoni përsëri.'
}
