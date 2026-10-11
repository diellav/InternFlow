import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, expect, it, vi } from 'vitest'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { AuthContext } from '../auth/context/AuthContext'
import { NotificationContext } from '../notifications/context/NotificationContext'
import { MessagingContext, useMessaging } from './context/MessagingContext'
import { useEffect } from 'react'
import { MessagingProvider } from './providers/MessagingProvider'
import { MessagesNavigation } from './components/MessagesNavigation'
import { MessagesPage } from './pages/MessagesPage'
import { RequireRole } from '../auth/guards/RequireRole'
import { RequireAuth } from '../auth/guards/RequireAuth'
import * as api from './api/messagingApi'
import { connectMessaging } from './realtime/messagingRealtime'

vi.mock('./realtime/messagingRealtime', () => ({ connectMessaging: vi.fn() }))
vi.mock('./api/messagingApi', async original => ({ ...await original(), listConversations: vi.fn(), getConversation: vi.fn(), listEligibleInternships: vi.fn(), openConversation: vi.fn(), listMessages: vi.fn(), sendMessage: vi.fn(), markConversationRead: vi.fn(), getUnreadMessageCount: vi.fn() }))
const stamp = '2026-10-10T10:00:00Z'
const student = { id: 1, role: 'STUDENT', first_name: 'Student', last_name: 'One' }
const supervisor = { id: 2, role: 'COMPANY_SUPERVISOR', first_name: 'Supervisor', last_name: 'Two' }
const conversation = (id = 12, canSend = true) => ({ id, internship_id: 4, type: 'direct', can_send: canSend, unread_messages: 1, has_unread_messages: true, created_at: stamp, participants: [student, supervisor], latest_message: { preview: 'First message', sent_at: stamp }, read_state: { last_read_message_id: null } })
const message = (id = 1, content = 'Incoming message', sender = supervisor) => ({ id, conversation_id: 12, sender_id: sender.id, sender, content, sent_at: stamp })
let bus, refresh, unsubscribe, connection, notificationInvalidate
function wrap(children, entry = '/messages', user = student, context = {}) {
  return <AuthContext.Provider value={{ user, isAuthenticated: !!user, isLoading: false }}><MemoryRouter initialEntries={[entry]}><MessagingContext.Provider value={{ count: 3, revision: 0, recovery: 0, status: 'connected', refresh, subscribe: (id, callback) => { bus[id] = callback; return unsubscribe }, ...context }}>{children}</MessagingContext.Provider></MemoryRouter></AuthContext.Provider>
}
function page(entry = '/messages/conversations/12', user = student, context = {}) {
  return render(wrap(<Routes><Route path="/messages" element={<MessagesPage />} /><Route path="/messages/conversations/:conversationId" element={<MessagesPage />} /></Routes>, entry, user, context))
}
beforeEach(() => {
  vi.clearAllMocks(); bus = {}; refresh = vi.fn(); unsubscribe = vi.fn(); notificationInvalidate = vi.fn()
  connection = { disconnect: vi.fn(), subscribe: vi.fn(() => unsubscribe) }
  connectMessaging.mockReturnValue(connection)
  api.listConversations.mockResolvedValue({ data: [conversation()], meta: { last_page: 2 } })
  api.getConversation.mockImplementation(async id => conversation(Number(id)))
  api.listMessages.mockResolvedValue({ data: [message()], meta: { next_cursor: null } })
  api.markConversationRead.mockResolvedValue({ ...conversation(), unread_messages: 0 })
  api.getUnreadMessageCount.mockResolvedValue({ unread_messages: 20, unread_conversations: 3 })
  api.listEligibleInternships.mockResolvedValue({ data: [{ id: 4, position_title: 'Developer', company_name: 'Company', available_types: ['student_supervisor', 'group'] }], last_page: 1 })
  api.openConversation.mockResolvedValue(conversation())
  api.sendMessage.mockResolvedValue(message(2, 'Sent text', student))
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' })
})

it.each(['STUDENT', 'COMPANY_SUPERVISOR', 'ACADEMIC_COORDINATOR'])('renders protected messaging for role %s', async role => {
  page('/messages', { ...student, role })
  expect(await screen.findByRole('heading', { name: /Mesazhet/ })).toBeInTheDocument()
  expect(await screen.findByText('First message')).toBeInTheDocument()
})
it('shows unread conversation badge capped at 99+', () => {
  render(wrap(<MessagesNavigation />, '/messages', student, { count: 120 }))
  expect(screen.getByLabelText('120 biseda të palexuara')).toHaveTextContent('99+')
})
it('filters, server searches and paginates without pretending to search one loaded page', async () => {
  page('/messages')
  await screen.findByText('First message')
  fireEvent.click(screen.getByRole('button', { name: 'Të palexuara' }))
  await waitFor(() => expect(api.listConversations).toHaveBeenLastCalledWith(expect.objectContaining({ filter: 'unread' }), expect.anything()))
  fireEvent.change(screen.getByLabelText('Kërko emër ose praktikë'), { target: { value: 'Developer' } })
  fireEvent.click(screen.getByRole('button', { name: 'Kërko' }))
  await waitFor(() => expect(api.listConversations).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'Developer', page: 1 }), expect.anything()))
  await screen.findByText('First message')
  fireEvent.click(screen.getByRole('button', { name: 'Pas' }))
  await waitFor(() => expect(api.listConversations).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }), expect.anything()))
  expect(api.markConversationRead).not.toHaveBeenCalled()
})
it('opens only authoritative internship-specific participant types', async () => {
  page('/messages')
  fireEvent.click(screen.getByRole('button', { name: 'Bisedë e re' }))
  await screen.findByRole('option', { name: /Developer/ })
  fireEvent.change(screen.getByLabelText('Praktika'), { target: { value: '4' } })
  expect(screen.queryByRole('option', { name: 'Studenti dhe koordinatori' })).not.toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('Pjesëmarrësit'), { target: { value: 'group' } })
  fireEvent.click(screen.getByRole('button', { name: 'Hap bisedën' }))
  await waitFor(() => expect(api.openConversation).toHaveBeenCalledWith('4', 'group'))
  await screen.findByText('Incoming message')
})
it('loads latest history, renders literal multiline text and acknowledges only after display', async () => {
  let resolve
  api.listMessages.mockImplementationOnce(() => new Promise(done => { resolve = done }))
  page()
  await waitFor(() => expect(resolve).toBeTypeOf('function'))
  expect(api.markConversationRead).not.toHaveBeenCalled()
  await act(async () => resolve({ data: [message(5, '<script>literal</script>\nsecond line')], meta: { next_cursor: null } }))
  expect(screen.getByText(/<script>literal/)).toBeInTheDocument()
  expect(document.querySelector('script')).toBe(null)
  await waitFor(() => expect(api.markConversationRead).toHaveBeenCalledWith('12', 5))
  expect(refresh).toHaveBeenCalled()
})
it('does not acknowledge hidden conversations or failed history loading', async () => {
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' })
  page()
  await screen.findByText('Incoming message')
  expect(api.markConversationRead).not.toHaveBeenCalled()
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' })
  fireEvent(document, new Event('visibilitychange'))
  await waitFor(() => expect(api.markConversationRead).toHaveBeenCalledWith('12', 1))
})
it('shows history error without acknowledging and offers retry', async () => {
  api.listMessages.mockRejectedValueOnce({ status: 500 })
  page()
  await screen.findByRole('alert')
  expect(api.markConversationRead).not.toHaveBeenCalled()
  fireEvent.click(screen.getByRole('button', { name: 'Provo përsëri' }))
  await screen.findByText('Incoming message')
})
it('sends on Enter, leaves Shift+Enter for multiline and clears only after success', async () => {
  page(); await screen.findByText('Incoming message')
  const input = screen.getByLabelText('Mesazhi juaj')
  fireEvent.change(input, { target: { value: 'Sent text' } })
  fireEvent.keyDown(input, { key: 'Enter', shiftKey: true })
  expect(api.sendMessage).not.toHaveBeenCalled()
  fireEvent.keyDown(input, { key: 'Enter' })
  await waitFor(() => expect(api.sendMessage).toHaveBeenCalledWith('12', 'Sent text'))
  await waitFor(() => expect(input).toHaveValue(''))
  expect(screen.getAllByText('Sent text')).toHaveLength(1)
})
it('prevents blank and oversized sends and retains text after a definite failure', async () => {
  page(); await screen.findByText('Incoming message')
  const input = screen.getByLabelText('Mesazhi juaj')
  fireEvent.change(input, { target: { value: '   ' } })
  expect(screen.getByRole('button', { name: 'Dërgo' })).toBeDisabled()
  fireEvent.change(input, { target: { value: 'x'.repeat(2001) } })
  expect(screen.getByRole('button', { name: 'Dërgo' })).toBeDisabled()
  api.sendMessage.mockRejectedValueOnce({ status: 422 })
  fireEvent.change(input, { target: { value: 'Retained' } })
  fireEvent.click(screen.getByRole('button', { name: 'Dërgo' }))
  await screen.findByRole('alert')
  expect(input).toHaveValue('Retained')
})
it('does not retry an ambiguous committed send and reconciles it by history', async () => {
  api.sendMessage.mockRejectedValueOnce({ status: 500 })
  page(); await screen.findByText('Incoming message')
  const input = screen.getByLabelText('Mesazhi juaj')
  fireEvent.change(input, { target: { value: 'Possibly sent' } })
  fireEvent.click(screen.getByRole('button', { name: 'Dërgo' }))
  await screen.findByText(/Dorëzimi nuk u konfirmua/)
  expect(api.sendMessage).toHaveBeenCalledTimes(1)
  expect(screen.getByRole('button', { name: 'Dërgo' })).toBeDisabled()
  api.listMessages.mockResolvedValue({ data: [message(), message(2, 'Possibly sent', student)], meta: { next_cursor: null } })
  fireEvent.click(screen.getByRole('button', { name: 'Kontrollo dorëzimin' }))
  await waitFor(() => expect(input).toHaveValue(''))
  expect(api.sendMessage).toHaveBeenCalledTimes(1)
})
it('merges realtime arrivals without duplicate bubbles and acknowledges the displayed ID', async () => {
  page(); await screen.findByText('Incoming message')
  api.listMessages.mockResolvedValue({ data: [message(2, 'Realtime arrival'), message()], meta: { next_cursor: null } })
  await act(async () => bus[12]())
  await screen.findByText('Realtime arrival')
  await act(async () => bus[12]())
  expect(screen.getAllByText('Realtime arrival')).toHaveLength(1)
  await waitFor(() => expect(api.markConversationRead).toHaveBeenLastCalledWith('12', 2))
})
it('loads older cursor pages and preserves chronological duplicate-free history', async () => {
  api.listMessages.mockResolvedValueOnce({ data: [message(3, 'Newest'), message(2, 'Middle')], meta: { next_cursor: 'older' } })
  page(); await screen.findByText('Newest')
  api.listMessages.mockResolvedValueOnce({ data: [message(1, 'Oldest'), message(2, 'Middle')], meta: { next_cursor: null } })
  fireEvent.click(screen.getByRole('button', { name: 'Mesazhe më të vjetra' }))
  await screen.findByText('Oldest')
  expect(api.listMessages).toHaveBeenLastCalledWith('12', { cursor: 'older' })
  expect(screen.getAllByText('Middle')).toHaveLength(1)
  const content = [...document.querySelectorAll('.messages-bubble p')].map(node => node.textContent)
  expect(content).toEqual(['Oldest', 'Middle', 'Newest'])
})
it('keeps completed conversation read-only and handles unauthorized deep links', async () => {
  api.getConversation.mockResolvedValueOnce(conversation(12, false))
  api.markConversationRead.mockResolvedValueOnce({ ...conversation(12, false), unread_messages: 0 })
  page(); await screen.findByText(/Praktika ka përfunduar/)
  expect(screen.queryByLabelText('Mesazhi juaj')).not.toBeInTheDocument()
  expect(api.sendMessage).not.toHaveBeenCalled()
})
it('rejects foreign conversation access without showing a composer or acknowledging', async () => {
  api.getConversation.mockRejectedValueOnce({ status: 404 })
  page(); await screen.findByText(/Biseda nuk është e disponueshme për këtë llogari/)
  expect(screen.queryByLabelText('Mesazhi juaj')).not.toBeInTheDocument()
  expect(api.markConversationRead).not.toHaveBeenCalled()
})
it('ignores stale search responses', async () => {
  let resolve
  api.listConversations.mockImplementationOnce(() => new Promise(done => { resolve = done }))
  page('/messages')
  await waitFor(() => expect(resolve).toBeTypeOf('function'))
  fireEvent.click(screen.getByRole('button', { name: 'Të lexuara' }))
  await screen.findByText('First message')
  await act(async () => resolve({ data: [{ ...conversation(), latest_message: { preview: 'Stale response' } }], meta: { last_page: 1 } }))
  expect(screen.queryByText('Stale response')).not.toBeInTheDocument()
})
it('provider refreshes unread conversations/notifications on events and recovery, then disconnects on logout', async () => {
  const fixture = <AuthContext.Provider value={{ user: student }}><NotificationContext.Provider value={{ invalidate: notificationInvalidate }}><MemoryRouter><MessagingProvider><MessagesNavigation /></MessagingProvider></MemoryRouter></NotificationContext.Provider></AuthContext.Provider>
  const result = render(fixture)
  await screen.findByLabelText('3 biseda të palexuara')
  const [, changed, recovered] = connectMessaging.mock.calls[0]
  api.getUnreadMessageCount.mockResolvedValue({ unread_messages: 400, unread_conversations: 101 })
  await act(async () => changed())
  await screen.findByText('99+')
  expect(notificationInvalidate).toHaveBeenCalled()
  await act(async () => recovered())
  expect(api.getUnreadMessageCount).toHaveBeenCalledTimes(3)
  result.unmount()
  expect(connection.disconnect).toHaveBeenCalledTimes(1)
})
it('does not start realtime or unread requests for administrators', () => {
  render(<AuthContext.Provider value={{ user: { id: 9, role: 'ADMIN' } }}><NotificationContext.Provider value={{}}><MessagingProvider><span>Admin page</span></MessagingProvider></NotificationContext.Provider></AuthContext.Provider>)
  expect(connectMessaging).not.toHaveBeenCalled()
  expect(api.getUnreadMessageCount).not.toHaveBeenCalled()
})
it('existing guards deny administrators and guests from messaging routes', async () => {
  render(wrap(<Routes><Route element={<RequireRole allowedRoles={['STUDENT', 'COMPANY_SUPERVISOR', 'ACADEMIC_COORDINATOR']} />}><Route path="/messages" element={<MessagesPage />} /></Route><Route path="/" element={<p>Denied</p>} /></Routes>, '/messages', { id: 9, role: 'ADMIN' }))
  expect(await screen.findByText('Denied')).toBeInTheDocument()
  expect(api.listConversations).not.toHaveBeenCalled()
})
it('recovers missed pages until the previous history overlaps', async () => {
  page(); await screen.findByText('Incoming message')
  api.listMessages.mockResolvedValueOnce({ data: [message(5, 'Missed newest')], meta: { next_cursor: 'gap' } })
    .mockResolvedValueOnce({ data: [message(4, 'Missed middle')], meta: { next_cursor: 'gap2' } })
    .mockResolvedValueOnce({ data: [message(3, 'Missed oldest'), message()], meta: { next_cursor: null } })
  await act(async () => bus[12]())
  await screen.findByText('Missed middle')
  expect(screen.getAllByText('Incoming message')).toHaveLength(1)
  expect(api.listMessages).toHaveBeenCalledWith('12', { cursor: 'gap2' }, expect.anything())
})

it('redirects guests to login before mounting messaging', async () => {
  render(wrap(<Routes><Route element={<RequireAuth />}><Route path="/messages" element={<MessagesPage />} /></Route><Route path="/login" element={<p>Login required</p>} /></Routes>, '/messages', null))
  expect(await screen.findByText('Login required')).toBeInTheDocument()
  expect(api.listConversations).not.toHaveBeenCalled()
})

it('attaches direct-entry child subscriptions after the provider initializes Echo', async () => {
  const callback = vi.fn()
  function DirectEntry() {
    const { subscribe } = useMessaging()
    useEffect(() => subscribe('12', callback), [subscribe])
    return <span>Direct entry</span>
  }
  const result = render(<AuthContext.Provider value={{ user: student }}><NotificationContext.Provider value={{}}><MessagingProvider><DirectEntry /></MessagingProvider></NotificationContext.Provider></AuthContext.Provider>)
  await waitFor(() => expect(connection.subscribe).toHaveBeenCalledWith('12', callback))
  expect(connection.subscribe).toHaveBeenCalledTimes(1)
  result.unmount()
  expect(unsubscribe).toHaveBeenCalledTimes(1)
})

it('keeps incoming messages unread while the user is reading older history', async () => {
  page(); await screen.findByText('Incoming message')
  await waitFor(() => expect(api.markConversationRead).toHaveBeenCalledWith('12', 1))
  api.markConversationRead.mockClear()
  const region = screen.getByRole('region', { name: 'Historia e mesazheve' })
  Object.defineProperty(region, 'scrollHeight', { configurable: true, value: 1000 })
  Object.defineProperty(region, 'clientHeight', { configurable: true, value: 100 })
  region.scrollTop = 0
  fireEvent.scroll(region)
  api.listMessages.mockResolvedValue({ data: [message(2, 'Unread arrival'), message()], meta: { next_cursor: null } })
  await act(async () => bus[12]())
  await screen.findByText('Unread arrival')
  expect(api.markConversationRead).not.toHaveBeenCalled()
  fireEvent.click(screen.getByRole('button', { name: 'Shko te mesazhet e fundit ↓' }))
  await waitFor(() => expect(api.markConversationRead).toHaveBeenCalledWith('12', 2))
})

it('fetches missed history when the realtime recovery revision changes', async () => {
  const element = context => wrap(<Routes><Route path="/messages/conversations/:conversationId" element={<MessagesPage />} /></Routes>, '/messages/conversations/12', student, context)
  const result = render(element({ recovery: 0 }))
  await screen.findByText('Incoming message')
  api.listMessages.mockResolvedValue({ data: [message(2, 'Recovered message'), message()], meta: { next_cursor: null } })
  result.rerender(element({ recovery: 1 }))
  await screen.findByText('Recovered message')
  expect(screen.getAllByText('Incoming message')).toHaveLength(1)
})

it('does not reload open history when only the unread-count revision changes', async () => {
  const subscribe = (id, callback) => { bus[id] = callback; return unsubscribe }
  const element = revision => wrap(<Routes><Route path="/messages/conversations/:conversationId" element={<MessagesPage />} /></Routes>, '/messages/conversations/12', student, { revision, subscribe })
  const result = render(element(0))
  await screen.findByText('Incoming message')
  await waitFor(() => expect(api.markConversationRead).toHaveBeenCalled())
  expect(api.getConversation).toHaveBeenCalledTimes(1)
  expect(api.listMessages).toHaveBeenCalledTimes(1)
  await act(async () => result.rerender(element(1)))
  expect(api.getConversation).toHaveBeenCalledTimes(1)
  expect(api.listMessages).toHaveBeenCalledTimes(1)
  await waitFor(() => expect(api.listConversations).toHaveBeenCalledTimes(2))
})

it('does not let old-account count responses overwrite a new keyed session', async () => {
  let oldResponse
  api.getUnreadMessageCount.mockImplementationOnce(() => new Promise(resolve => { oldResponse = resolve }))
  const element = user => <AuthContext.Provider value={{ user }}><NotificationContext.Provider value={{}}><MemoryRouter><MessagingProvider key={user.id}><MessagesNavigation /></MessagingProvider></MemoryRouter></NotificationContext.Provider></AuthContext.Provider>
  const result = render(element(student))
  await waitFor(() => expect(oldResponse).toBeTypeOf('function'))
  api.getUnreadMessageCount.mockResolvedValue({ unread_messages: 1, unread_conversations: 1 })
  result.rerender(element(supervisor))
  await screen.findByLabelText('1 biseda të palexuara')
  await act(async () => oldResponse({ unread_messages: 400, unread_conversations: 200 }))
  expect(screen.queryByText('99+')).not.toBeInTheDocument()
  expect(connection.disconnect).toHaveBeenCalled()
})
