import { apiClient } from '../../../shared/api/apiClient'
import { normalizeApiError } from '../../../shared/api/apiError'

export const isNotificationId = id => typeof id === 'string' && id.length === 36 && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id)

export async function listNotifications(params = {}, signal) {
  try { return (await apiClient.get('/api/notifications', { params, signal })).data }
  catch (error) { throw normalizeApiError(error) }
}

export async function getUnreadNotificationCount(signal) {
  try { return (await apiClient.get('/api/notifications/unread-count', { signal })).data.data.unread_count }
  catch (error) { throw normalizeApiError(error) }
}

export async function markNotificationAsRead(id) {
  if (!isNotificationId(id)) throw { type: 'validation', status: 422 }
  try { return (await apiClient.patch(`/api/notifications/${id}/read`, {})).data.data }
  catch (error) { throw normalizeApiError(error) }
}

export async function markAllNotificationsAsRead() {
  try { return (await apiClient.patch('/api/notifications/read-all', {})).data.data.updated_count }
  catch (error) { throw normalizeApiError(error) }
}

export function notificationErrorMessage(error) {
  if (error?.status === 401) return 'Sesioni ka përfunduar. Kyçuni përsëri.'
  if (error?.status === 403) return 'Nevojitet një llogari aktive për njoftimet.'
  if (error?.status === 404) return 'Njoftimi nuk është më i disponueshëm.'
  return 'Njoftimet nuk u përditësuan. Provoni përsëri.'
}

export function safeNotificationUrl(url) {
  return typeof url === 'string' && url.length <= 255 && !/\s/.test(url) && /^\/(?:student|supervisor|coordinator|admin)\/(?:internships|tasks|activities|companies|users|supervisors|monitoring\/internships|monitoring\/tasks|monitoring\/activities)(?:\/[1-9][0-9]*)?(?:\/(?:activities|final-evaluation))?$/.test(url) ? url : null
}
