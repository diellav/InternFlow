import { beforeEach, expect, it, vi } from 'vitest'
import { apiClient } from '../../shared/api/apiClient'
import { getAdminInternship, getAdminInternshipStatistics, listAdminInternships } from './api/adminInternshipsApi'

vi.mock('../../shared/api/apiClient', () => ({ apiClient: { get: vi.fn() } }))
beforeEach(() => vi.resetAllMocks())

it('uses only authenticated read endpoints with filters and cancellation', async () => {
  const signal = new AbortController().signal
  apiClient.get.mockResolvedValue({ data: { data: { id: 12 } } })
  await listAdminInternships({ status: 'ACTIVE', search: 'Company', page: 2 }, signal)
  expect(apiClient.get).toHaveBeenLastCalledWith('/api/admin/internships', { params: { status: 'ACTIVE', search: 'Company', page: 2 }, signal })
  expect(await getAdminInternship(12, signal)).toEqual({ id: 12 })
  expect(apiClient.get).toHaveBeenLastCalledWith('/api/admin/internships/12', { signal })
  await getAdminInternshipStatistics(signal)
  expect(apiClient.get).toHaveBeenLastCalledWith('/api/admin/internships/statistics', { signal })
})
it('normalizes authorization failures', async () => {
  apiClient.get.mockRejectedValue({ isAxiosError: true, response: { status: 403 } })
  await expect(getAdminInternship(12)).rejects.toMatchObject({ status: 403, type: 'forbidden' })
})
