import { beforeEach, expect, it, vi } from 'vitest'
import { apiClient } from '../../shared/api/apiClient'
import { listAuditLogs } from './api/auditLogsApi'

vi.mock('../../shared/api/apiClient', () => ({ apiClient: { get: vi.fn() } }))
beforeEach(() => vi.resetAllMocks())
it('uses only the read endpoint with server filters and cancellation', async () => {
  const params = { search: 'Arta', action: 'task.created', entity_type: 'task', date_from: '2026-10-01', page: 2, per_page: 15 }
  const signal = new AbortController().signal
  apiClient.get.mockResolvedValue({ data: { data: [], meta: { total: 0 } } })
  expect(await listAuditLogs(params, signal)).toEqual({ data: [], meta: { total: 0 } })
  expect(apiClient.get).toHaveBeenCalledWith('/api/admin/audit-logs', { params, signal })
})
it('normalizes API authorization errors', async () => {
  apiClient.get.mockRejectedValue({ isAxiosError: true, response: { status: 403 } })
  await expect(listAuditLogs({})).rejects.toMatchObject({ status: 403, type: 'forbidden' })
})
