import { beforeEach, expect, it, vi } from 'vitest'
import { apiClient } from '../../shared/api/apiClient'
import { getUnreadNotificationCount, isNotificationId, listNotifications, markAllNotificationsAsRead, markNotificationAsRead, notificationErrorMessage, safeNotificationUrl } from './api/notificationsApi'

vi.unmock('./api/notificationsApi')
vi.mock('../../shared/api/apiClient', () => ({ apiClient: { get: vi.fn(), patch: vi.fn() } }))
const id = '00000000-0000-4000-8000-000000000001'
beforeEach(() => vi.resetAllMocks())

it('uses the existing authenticated client and exact list/count/resource contracts', async () => {
  const signal = new AbortController().signal
  const result = { data: [{ id, payload: { title: 'Njoftim' } }], meta: { current_page: 1 } }
  apiClient.get.mockResolvedValueOnce({ data: result }).mockResolvedValueOnce({ data: { data: { unread_count: 4 } } })
  expect(await listNotifications({ status: 'unread', page: 2 }, signal)).toEqual(result)
  expect(apiClient.get).toHaveBeenNthCalledWith(1, '/api/notifications', { params: { status: 'unread', page: 2 }, signal })
  expect(await getUnreadNotificationCount(signal)).toBe(4)
  expect(apiClient.get).toHaveBeenNthCalledWith(2, '/api/notifications/unread-count', { signal })
  apiClient.patch.mockResolvedValueOnce({ data: { data: { id, read_at: '2026-10-10T10:00:00Z' } } }).mockResolvedValueOnce({ data: { data: { updated_count: 4 } } })
  expect(await markNotificationAsRead(id)).toEqual({ id, read_at: '2026-10-10T10:00:00Z' })
  expect(apiClient.patch).toHaveBeenNthCalledWith(1, `/api/notifications/${id}/read`, {})
  expect(await markAllNotificationsAsRead()).toBe(4)
  expect(apiClient.patch).toHaveBeenNthCalledWith(2, '/api/notifications/read-all', {})
})

it.each([401, 403, 404, 422, 500])('normalizes failures %s without exposing backend details', async status => {
  const failure = { isAxiosError: true, response: { status, data: { message: 'Private details' } } }
  apiClient.get.mockRejectedValue(failure)
  apiClient.patch.mockRejectedValue(failure)
  for (const request of [() => listNotifications(), () => getUnreadNotificationCount(), () => markNotificationAsRead(id), () => markAllNotificationsAsRead()]) {
    await expect(request()).rejects.toMatchObject({ status })
  }
  expect(notificationErrorMessage({ status })).not.toContain('Private details')
})

it.each(['../read-all', 'invalid', `${id}\n`, null])('rejects invalid UUID %s without issuing a mutation', async value => {
  expect(isNotificationId(value)).toBe(false)
  await expect(markNotificationAsRead(value)).rejects.toMatchObject({ status: 422 })
  expect(apiClient.patch).not.toHaveBeenCalled()
})

it.each(['https://example.com', '//example.com', 'javascript:alert(1)', 'data:text/html,bad', '/api/task-submission-files/1/download', '/storage/file', '/student/../storage/file', '/student/tasks/%31', '/student/tasks/1?next=evil', '/student/tasks/1#evil', '/student/tasks/1\n', '/student\\tasks\\1', '/unknown/page', null])('rejects unsafe or malformed destination %s', url => {
  expect(safeNotificationUrl(url)).toBe(null)
})

it.each(['/student/tasks/12', '/student/internships/12', '/coordinator/internships/12', '/coordinator/monitoring/internships/12', '/supervisor/tasks/12', '/admin/users/12'])('accepts allowlisted internal destination %s', url => {
  expect(safeNotificationUrl(url)).toBe(url)
})
