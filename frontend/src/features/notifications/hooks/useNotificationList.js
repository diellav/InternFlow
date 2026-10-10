import { useEffect, useState } from 'react'
import { isNotificationId, listNotifications, notificationErrorMessage } from '../api/notificationsApi'
import { useNotifications } from '../context/NotificationContext'

export function useNotificationList({ status = 'all', page = 1, per_page = 15 } = {}, enabled = true) {
  const { revision, busy } = useNotifications()
  const [reload, setReload] = useState(0)
  const [state, setState] = useState({ data: [], meta: null, loading: true, error: '' })
  useEffect(() => {
    if (!enabled || busy) return
    let active = true
    const controller = new AbortController()
    queueMicrotask(() => { if (active) setState(previous => ({ ...previous, loading: true, error: '' })) })
    listNotifications({ status, page, per_page }, controller.signal).then(result => {
      if (!active) return
      const seen = new Set()
      const data = (Array.isArray(result.data) ? result.data : []).filter(item => {
        if (!item || !isNotificationId(item.id) || seen.has(item.id)) return false
        seen.add(item.id)
        return true
      })
      setState({ data, meta: result.meta, loading: false, error: '' })
    }).catch(error => {
      if (active && !controller.signal.aborted) setState(previous => ({ ...previous, loading: false, error: notificationErrorMessage(error) }))
    })
    return () => { active = false; controller.abort() }
  }, [status, page, per_page, enabled, busy, revision, reload])
  return { ...state, retry: () => setReload(value => value + 1) }
}
