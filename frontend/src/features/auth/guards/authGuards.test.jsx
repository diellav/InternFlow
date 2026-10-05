import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { AuthContext } from '../context/AuthContext'
import { PublicOnlyRoute } from './PublicOnlyRoute'
import { RequireAuth } from './RequireAuth'

function renderWithAuth(ui, authValue, initialPath = '/') {
  return render(
    <AuthContext.Provider value={authValue}>
      <MemoryRouter initialEntries={[initialPath]}>{ui}</MemoryRouter>
    </AuthContext.Provider>,
  )
}

describe('auth route guards', () => {
  it('waits for auth initialization before rendering a public-only route', () => {
    renderWithAuth(
      <Routes>
        <Route element={<PublicOnlyRoute />}>
          <Route path="/login" element={<p>Login form</p>} />
        </Route>
      </Routes>,
      { isAuthenticated: false, isLoading: true },
      '/login',
    )

    expect(screen.getByText('Checking your session…')).toBeInTheDocument()
    expect(screen.queryByText('Login form')).not.toBeInTheDocument()
  })

  it('redirects an unauthenticated visitor away from a protected route', () => {
    renderWithAuth(
      <Routes>
        <Route path="/login" element={<p>Login destination</p>} />
        <Route element={<RequireAuth />}>
          <Route path="/session" element={<p>Protected session</p>} />
        </Route>
      </Routes>,
      { isAuthenticated: false, isLoading: false },
      '/session',
    )

    expect(screen.getByText('Login destination')).toBeInTheDocument()
    expect(screen.queryByText('Protected session')).not.toBeInTheDocument()
  })
})
