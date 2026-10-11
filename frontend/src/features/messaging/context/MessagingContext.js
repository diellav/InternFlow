import { createContext, useContext } from 'react'

export const MessagingContext = createContext(null)
const empty = { count: 0, revision: 0, recovery: 0, status: 'offline', refresh: async () => {}, subscribe: () => () => {} }
export const useMessaging = () => useContext(MessagingContext) ?? empty
