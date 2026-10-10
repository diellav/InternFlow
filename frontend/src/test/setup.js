import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, vi } from 'vitest'

vi.mock('../features/notifications/api/notificationsApi', async original => ({
  ...await original(),
  getUnreadNotificationCount: vi.fn(async () => 0),
}))

afterEach(cleanup)
