import { useCallback, useEffect, useMemo, useState } from 'react'
import { normalizeApiError } from '../../../shared/api/apiError'
import {
  getCurrentUser,
  login as loginRequest,
  logout as logoutRequest,
} from '../api/authApi'
import { AuthContext } from '../context/AuthContext'

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState(null)

  const refreshUser = useCallback(async () => {
    try {
      const currentUser = await getCurrentUser()
      setUser(currentUser)
      setError(null)

      return currentUser
    } catch (requestError) {
      const apiError = normalizeApiError(requestError)
      setUser(null)
      setError(apiError.type === 'unauthenticated' ? null : apiError)

      return null
    } finally {
      setIsLoading(false)
    }
  }, [])

  const login = useCallback(async (credentials) => {
    try {
      const authenticatedUser = await loginRequest(credentials)
      setUser(authenticatedUser)
      setError(null)

      return authenticatedUser
    } catch (requestError) {
      const apiError = normalizeApiError(requestError)
      setUser(null)
      setError(apiError)

      throw apiError
    }
  }, [])

  const logout = useCallback(async () => {
    try {
      await logoutRequest()
      setUser(null)
      setError(null)
    } catch (requestError) {
      const apiError = normalizeApiError(requestError)

      if (apiError.type === 'unauthenticated') {
        setUser(null)
        setError(null)

        return
      }

      setError(apiError)
      throw apiError
    }
  }, [])

  useEffect(() => {
    let isActive = true

    queueMicrotask(() => {
      if (isActive) {
        refreshUser()
      }
    })

    return () => {
      isActive = false
    }
  }, [refreshUser])

  const value = useMemo(
    () => ({
      user,
      isAuthenticated: user !== null,
      isLoading,
      error,
      login,
      logout,
      refreshUser,
    }),
    [error, isLoading, login, logout, refreshUser, user],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
