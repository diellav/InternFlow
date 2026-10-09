import { useEffect, useState } from 'react'
import { useAuth } from '../../auth/hooks/useAuth'
import { activityErrorMessage } from '../api/activitiesApi'

export function useActivityRecord(loader, id, errorMessage = activityErrorMessage) {
  const { refreshUser } = useAuth()
  const [result, setResult] = useState(null)
  const [revision, setRevision] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(async () => {
      if (controller.signal.aborted) return
      setResult({ loader, id, data: null, error: '' })
      try { const response = await loader(id, controller.signal); if (!controller.signal.aborted) setResult({ loader, id, data: response, error: '' }) }
      catch (failure) { if (!controller.signal.aborted) { setResult({ loader, id, data: null, error: errorMessage(failure) }); if ([401, 403].includes(failure.status)) await refreshUser() } }
    })
    return () => controller.abort()
  }, [loader, id, revision, refreshUser, errorMessage])
  const current = result?.loader === loader && result?.id === id ? result : null
  return { data: current?.data ?? null, error: current?.error ?? '', reload: () => setRevision((value) => value + 1) }
}
