import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, vi } from 'vitest'

vi.mock('../features/notifications/api/notificationsApi', async original => ({
  ...await original(),
  getUnreadNotificationCount: vi.fn(async () => 0),
}))

afterEach(cleanup)

vi.mock('../features/messaging/api/messagingApi', async original => ({
  ...await original(),
  getUnreadMessageCount: vi.fn(async () => ({ unread_messages: 0, unread_conversations: 0 })),
}))
