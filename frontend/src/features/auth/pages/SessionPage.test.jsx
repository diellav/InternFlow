import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { AuthContext } from '../context/AuthContext'
import { SessionPage } from './SessionPage'
import { RootRedirect } from '../components/RootRedirect'
import { PublicOnlyRoute } from '../guards/PublicOnlyRoute'

function Destination() {
  const location = useLocation()
  const navigate = useNavigate()
  return <><p>{location.pathname}</p><button onClick={() => navigate(-1)}>Back</button></>
}

describe('role landing redirects', () => {
  it.each([
    ['STUDENT', '/student/internships'],
    ['ACADEMIC_COORDINATOR', '/coordinator/internships'],
    ['COMPANY_SUPERVISOR', '/supervisor/internships'],
    ['ADMIN', '/admin'],
  ])('redirects %s from session, root and authenticated login without loops', (role, target) => {
    for (const entry of ['/session', '/', '/login']) {
      const view = render(<AuthContext.Provider value={{ user: { id: 1, role }, isAuthenticated: true, isLoading: false }}>
        <MemoryRouter initialEntries={['/previous', entry]} initialIndex={1}><Routes>
          <Route path="/" element={<RootRedirect />} />
          <Route path="/session" element={<SessionPage />} />
          <Route element={<PublicOnlyRoute />}><Route path="/login" element={<p>Login</p>} /></Route>
          <Route path={target} element={<Destination />} />
          <Route path="/previous" element={<p>Previous</p>} />
        </Routes></MemoryRouter>
      </AuthContext.Provider>)
      expect(screen.getByText(target)).toBeInTheDocument()
      expect(screen.queryByText('Sesioni i autentikuar')).not.toBeInTheDocument()
      fireEvent.click(screen.getByRole('button', { name: 'Back' }))
      expect(screen.getByText('Previous')).toBeInTheDocument()
      view.unmount()
    }
  })
})
