import { apiClient } from '../../../shared/api/apiClient'
import { normalizeApiError } from '../../../shared/api/apiError'
import { initializeCsrf } from '../../auth/api/authApi'

async function request(operation) {
  try {
    return (await operation()).data
  } catch (error) {
    const normalized = normalizeApiError(error)
    throw { ...normalized, message: error.response?.data?.message }
  }
}

export function listUsers(params, signal) {
  return request(() => apiClient.get('/api/admin/users', { params, signal }))
}

export async function getUser(id, signal) {
  return (await request(() => apiClient.get(`/api/admin/users/${id}`, { signal }))).data
}

export async function getProfile(signal) {
  return (await request(() => apiClient.get('/api/profile', { signal }))).data
}

export async function updateProfile(payload) {
  return (await request(async () => {
    await initializeCsrf()
    return apiClient.patch('/api/profile', payload)
  })).data
}

export async function updateActivation(id, isActive) {
  return (await request(async () => {
    await initializeCsrf()
    return apiClient.patch(`/api/admin/users/${id}/activation`, { is_active: isActive })
  })).data
}

export async function saveCoordinator(id, payload) {
  const response = await request(async () => {
    await initializeCsrf()
    return id
      ? apiClient.patch(`/api/admin/academic-coordinators/${id}`, payload)
      : apiClient.post('/api/admin/academic-coordinators', payload)
  })
  return response.data
}

export function errorMessage(error) {
  if (error.status === 404) return 'Përdoruesi nuk u gjet.'
  if (error.type === 'unauthenticated') return 'Sesioni ka përfunduar. Kyçuni përsëri.'
  if (error.type === 'forbidden') return 'Nuk keni qasje në këtë veprim ose llogaria nuk është aktive.'
  if (error.type === 'csrf') return 'Sesioni nuk mund të verifikohej. Rifreskoni faqen dhe provoni përsëri.'
  if (error.validationErrors?.is_active) return error.validationErrors.is_active[0]
  return 'Veprimi nuk u krye. Provoni përsëri.'
}
