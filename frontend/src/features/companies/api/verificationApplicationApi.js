import { apiClient } from '../../../shared/api/apiClient'
import { normalizeApiError } from '../../../shared/api/apiError'
import { initializeCsrf } from '../../auth/api/authApi'

const path = '/api/supervisor/verification-application'

export async function getVerificationApplication(signal) {
  try { return (await apiClient.get(path, { signal })).data.data }
  catch (error) { throw normalizeApiError(error) }
}

async function mutate(method, url, payload) {
  try {
    await initializeCsrf()
    return (await apiClient[method](url, payload)).data.data
  } catch (error) { throw normalizeApiError(error) }
}

export const saveVerificationApplication = (payload) => mutate('patch', path, payload)
export const resubmitVerificationApplication = (targets) => mutate('post', `${path}/resubmit`, { targets })
