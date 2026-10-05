import axios from 'axios'

const ERROR_TYPES = {
  401: 'unauthenticated',
  403: 'forbidden',
  419: 'csrf',
  422: 'validation',
  429: 'rate_limited',
}

export function normalizeApiError(error) {
  if (!axios.isAxiosError(error)) {
    return { type: 'unexpected', status: null }
  }

  const status = error.response?.status ?? null

  return {
    type: ERROR_TYPES[status] ?? (status && status >= 500 ? 'server' : 'network'),
    status,
    validationErrors: status === 422 ? error.response?.data?.errors ?? {} : {},
  }
}
