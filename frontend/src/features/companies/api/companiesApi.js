import { apiClient } from '../../../shared/api/apiClient'
import { normalizeApiError } from '../../../shared/api/apiError'
import { initializeCsrf } from '../../auth/api/authApi'

async function request(path, options) {
  try {
    return (await apiClient.get(path, options)).data
  } catch (error) {
    throw normalizeApiError(error)
  }
}

export function listCompanies(params, signal) {
  return request('/api/admin/companies', { params, signal })
}

export async function getCompany(id, signal) {
  return (await request(`/api/admin/companies/${id}`, { signal })).data
}

export function companyErrorMessage(error) {
  if (error.status === 409) return 'Kompania nuk është në pritje të shqyrtimit. Të dhënat po rifreskohen.'
  if (error.status === 404) return 'Kompania nuk u gjet.'
  if (error.type === 'unauthenticated') return 'Sesioni ka përfunduar. Kyçuni përsëri.'
  if (error.type === 'forbidden') return 'Nuk keni qasje në këtë veprim ose llogaria nuk është aktive.'
  if (error.type === 'csrf') return 'Sesioni nuk mund të verifikohej. Rifreskoni faqen dhe provoni përsëri.'
  if (error.type === 'validation') return 'Filtrat nuk janë të vlefshëm. Kontrolloni vlerat dhe provoni përsëri.'
  return 'Të dhënat nuk mund të ngarkoheshin. Provoni përsëri.'
}

export async function verifyCompany(id, payload) {
  try {
    await initializeCsrf()
    return (await apiClient.patch(`/api/admin/companies/${id}/verification`, payload)).data.data
  } catch (error) {
    throw normalizeApiError(error)
  }
}
