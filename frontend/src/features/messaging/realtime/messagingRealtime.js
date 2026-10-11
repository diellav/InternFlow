import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { apiClient } from '../../../shared/api/apiClient'

export function connectMessaging(userId, changed, recovered, status) {
  const key = import.meta.env.VITE_REVERB_APP_KEY
  if (!key) { status('offline'); return { disconnect() {}, subscribe: () => () => {} } }
  let disposed = false
  const abort = new AbortController()
  const echo = new Echo({
    broadcaster: 'reverb', client: new Pusher(key, {
      wsHost: import.meta.env.VITE_REVERB_HOST || '127.0.0.1',
      wsPort: Number(import.meta.env.VITE_REVERB_PORT || 8080), wssPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
      forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https', enabledTransports: ['ws', 'wss'], disableStats: true, cluster: '',
      authorizer: channel => ({ authorize: (socketId, callback) => {
        apiClient.post('/api/broadcasting/auth', { socket_id: socketId, channel_name: channel.name }, { signal: abort.signal })
          .then(result => { if (!disposed) callback(null, result.data) })
          .catch(error => { if (!disposed) { status('offline'); callback(error, null) } })
      } }),
    }),
  })
  const connection = echo.connector.pusher.connection
  const connected = () => { if (!disposed) { status('connected'); recovered() } }
  const stateChanged = state => { if (!disposed && state.current !== 'connected') status('offline') }
  connection.bind('connected', connected)
  connection.bind('state_change', stateChanged)
  echo.private(`messaging.user.${userId}`).listen('.messaging.changed', changed).error(() => status('offline'))
  return {
    subscribe(id, callback) {
      const name = `messaging.conversation.${id}.user.${userId}`
      echo.private(name).listen('.messaging.changed', callback).error(() => status('offline'))
      return () => echo.leave(name)
    },
    disconnect() {
      disposed = true; abort.abort()
      connection.unbind('connected', connected); connection.unbind('state_change', stateChanged)
      echo.disconnect()
    },
  }
}
