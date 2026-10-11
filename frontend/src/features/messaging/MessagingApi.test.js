import { beforeEach, expect, it, vi } from 'vitest'
import { apiClient } from '../../shared/api/apiClient'
import { getConversation, getUnreadMessageCount, listConversations, listEligibleInternships, listMessages, markConversationRead, mergeMessages, messagingError, openConversation, positiveId, sendMessage } from './api/messagingApi'

vi.unmock('./api/messagingApi')
vi.mock('../../shared/api/apiClient', () => ({ apiClient: { get: vi.fn(), post: vi.fn(), patch: vi.fn() } }))
beforeEach(() => vi.resetAllMocks())

it('uses exact authenticated endpoints, filters and explicit read cursors', async () => {
  const signal = new AbortController().signal
  apiClient.get.mockResolvedValue({ data: { data: { unread_messages: 5, unread_conversations: 2 } } })
  await listConversations({ filter: 'unread', search: 'Emri', page: 2 }, signal)
  expect(apiClient.get).toHaveBeenLastCalledWith('/api/messaging/conversations', { params: { filter: 'unread', search: 'Emri', page: 2 }, signal })
  await listMessages(12, { cursor: 'cursor' }, signal)
  expect(apiClient.get).toHaveBeenLastCalledWith('/api/messaging/conversations/12/messages', { params: { latest: 'true', cursor: 'cursor' }, signal })
  expect(await getUnreadMessageCount()).toEqual({ unread_messages: 5, unread_conversations: 2 })
  await getConversation(12, signal)
  await listEligibleInternships({ page: 1 }, signal)
  apiClient.post.mockResolvedValue({ data: { data: { id: 12 } } })
  expect(await openConversation(4, 'group')).toEqual({ id: 12 })
  expect(apiClient.post).toHaveBeenLastCalledWith('/api/internships/4/conversations', { type: 'group' }, undefined)
  await sendMessage(12, 'Text\nmultiline')
  expect(apiClient.post).toHaveBeenLastCalledWith('/api/messaging/conversations/12/messages', { content: 'Text\nmultiline' }, undefined)
  apiClient.patch.mockResolvedValue({ data: { data: { id: 12 } } })
  await markConversationRead(12, 22)
  expect(apiClient.patch).toHaveBeenCalledWith('/api/messaging/conversations/12/read', { last_read_message_id: 22 }, undefined)
})

it.each([401, 403, 404, 409, 422, 500])('normalizes API errors %s', async status => {
  apiClient.get.mockRejectedValue({ isAxiosError: true, response: { status, data: { message: 'Private information' } } })
  await expect(listConversations({})).rejects.toMatchObject({ status })
  expect(messagingError({ status })).not.toContain('Private information')
})

it.each(['0', '01', '-1', '1?x', '9007199254740992', null])('rejects unsafe route ID %s', id => expect(positiveId(id)).toBe(false))
it('merges realtime/history duplicates and deterministically orders timestamp ties', () => {
  const first = { id: 1, sent_at: '2026-10-10T10:00:00Z', content: 'A' }
  const second = { id: 2, sent_at: '2026-10-10T10:00:00Z', content: 'B' }
  expect(mergeMessages([second, first], [second])).toEqual([first, second])
  expect(positiveId('12')).toBe(true)
})
