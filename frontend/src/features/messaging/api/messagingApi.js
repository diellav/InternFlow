import { apiClient } from '../../../shared/api/apiClient'
import { normalizeApiError } from '../../../shared/api/apiError'

const request = async (method, path, data, config) => {
  try { return (await apiClient[method](path, ...(method === 'get' ? [config] : [data, config]))).data }
  catch (error) { throw normalizeApiError(error) }
}
export const listConversations = (params, signal) => request('get', '/api/messaging/conversations', null, { params, signal })
export const getConversation = (id, signal) => request('get', `/api/messaging/conversations/${id}`, null, { signal }).then(result => result.data)
export const listEligibleInternships = (params, signal) => request('get', '/api/messaging/internships', null, { params, signal })
export const openConversation = (id, type) => request('post', `/api/internships/${id}/conversations`, { type }).then(result => result.data)
export const getUnreadMessageCount = signal => request('get', '/api/messaging/unread-count', null, { signal }).then(result => result.data)
export const listMessages = (id, params = {}, signal) => request('get', `/api/messaging/conversations/${id}/messages`, null, { params: { latest: 'true', ...params }, signal })
export const sendMessage = (id, content) => request('post', `/api/messaging/conversations/${id}/messages`, { content }).then(result => result.data)
export const markConversationRead = (id, lastId) => request('patch', `/api/messaging/conversations/${id}/read`, { last_read_message_id: lastId }).then(result => result.data)
export const messagingError = error => error?.status === 404 ? 'Biseda nuk është e disponueshme për këtë llogari.' : error?.status === 403 ? 'Nuk keni të drejtë për mesazhe në këtë praktikë.' : error?.status === 401 ? 'Sesioni ka përfunduar. Kyçuni përsëri.' : error?.status === 409 ? 'Kjo praktikë nuk lejon këtë veprim.' : 'Mesazhet nuk u përditësuan. Provoni përsëri.'
export const positiveId = id => typeof id === 'string' && /^[1-9][0-9]*$/.test(id) && Number.isSafeInteger(Number(id))
export const mergeMessages = (current, incoming) => [...new Map([...current, ...incoming].map(item => [item.id, item])).values()].sort((a, b) => new Date(a.sent_at) - new Date(b.sent_at) || a.id - b.id)
