import { apiClient } from '../../../shared/api/apiClient'

export async function initializeCsrf() {
  await apiClient.get('/sanctum/csrf-cookie')
}

export async function getCurrentUser() {
  const response = await apiClient.get('/api/auth/me')

  return response.data.data
}

export async function login(credentials) {
  await initializeCsrf()

  const response = await apiClient.post('/api/auth/login', credentials)

  return response.data.data
}

export async function registerStudent(payload) {
  await initializeCsrf()

  const response = await apiClient.post('/api/auth/register/student', payload)

  return response.data.data
}

export async function getSupervisorRegistrationCompanies() {
  const response = await apiClient.get('/api/auth/register/supervisor/companies')

  return response.data.data
}

export async function registerSupervisor(payload) {
  await initializeCsrf()

  const response = await apiClient.post('/api/auth/register/supervisor', payload)

  return response.data.data
}

export async function logout() {
  await apiClient.post('/api/auth/logout')
}
