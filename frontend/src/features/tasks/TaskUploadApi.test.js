import { beforeEach, expect, it, vi } from 'vitest'
import { apiClient } from '../../shared/api/apiClient'
import { initializeCsrf } from '../auth/api/authApi'
import { resubmitTask, submitTask } from './api/tasksApi'

vi.mock('../../shared/api/apiClient', () => ({ apiClient: { post: vi.fn() } }))
vi.mock('../auth/api/authApi', () => ({ initializeCsrf: vi.fn() }))
beforeEach(() => { vi.resetAllMocks(); apiClient.post.mockResolvedValue({ data: { data: { id: 3 } } }) })

it('uses FormData without a manual multipart boundary for new submission and resubmission', async () => {
  const file = new File(['pdf'], 'Report.pdf', { type: 'application/pdf' })
  const progress = vi.fn()
  await submitTask(3, { submission_text: 'Work', resource_url: 'https://example.com', files: [file] }, progress)
  const [path, body, config] = apiClient.post.mock.calls[0]
  expect(path).toBe('/api/student/tasks/3/submissions'); expect(body).toBeInstanceOf(FormData)
  expect(body.get('submission_text')).toBe('Work'); expect(body.get('resource_url')).toBe('https://example.com')
  expect(body.getAll('files[]')).toEqual([file]); expect(config.headers['Content-Type']).toBeUndefined()
  expect(config.onUploadProgress).toBe(progress); expect(initializeCsrf).toHaveBeenCalledTimes(1)
  await resubmitTask(3, { submission_text: 'Correction', expected_submission_id: 11, files: [file] })
  expect(apiClient.post.mock.calls[1][1].get('expected_submission_id')).toBe('11')
})

it('keeps existing text-only JSON request behavior', async () => {
  const payload = { submission_text: 'Work', resource_url: null }
  await submitTask(3, payload)
  expect(apiClient.post).toHaveBeenCalledExactlyOnceWith('/api/student/tasks/3/submissions', payload)
})
