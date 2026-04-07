import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

const API = import.meta.env.VITE_API_URL || '/api'

function apiFetch(url, options = {}) {
  const headers = { Accept: 'application/json', ...options.headers }
  return fetch(url, { ...options, headers }).catch((err) => {
    if (err.name === 'TypeError' && err.message.includes('fetch')) {
      throw new Error('Cannot reach server. Is the API running? Check Docker: api and nginx containers must be up.')
    }
    throw err
  })
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref(localStorage.getItem('timejumper_token') || '')
  const user = ref(JSON.parse(localStorage.getItem('timejumper_user') || 'null'))

  const isAuthenticated = computed(() => !!token.value)

  function setAuth(t, u) {
    token.value = t
    user.value = u
    if (t) {
      localStorage.setItem('timejumper_token', t)
      localStorage.setItem('timejumper_user', JSON.stringify(u || {}))
    } else {
      localStorage.removeItem('timejumper_token')
      localStorage.removeItem('timejumper_user')
    }
  }

  async function login(email, password) {
    const res = await apiFetch(`${API}/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password }),
      credentials: 'include',
    })
    const d = await res.json().catch(() => ({}))
    if (!res.ok) {
      throw new Error(d.message || (d.errors && Object.values(d.errors).flat().join(' ')) || 'Login failed')
    }
    setAuth(d.token, d.user)
    return d
  }

  async function register(name, email, password, password_confirmation) {
    const res = await apiFetch(`${API}/register`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name, email, password, password_confirmation }),
      credentials: 'include',
    })
    const d = await res.json().catch(() => ({}))
    if (!res.ok) {
      const msg = d.message || (d.errors && Object.values(d.errors).flat().join(' ')) || 'Registration failed'
      throw new Error(msg)
    }
    setAuth(d.token, d.user)
    return d
  }

  async function logout() {
    try {
      await apiFetch(`${API}/logout`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token.value}` },
        credentials: 'include',
      })
    } catch (_) {}
    setAuth('', null)
  }

  async function fetchUser() {
    if (!token.value) return
    const res = await apiFetch(`${API}/user`, {
      headers: { Authorization: `Bearer ${token.value}` },
      credentials: 'include',
    })
    if (res.ok) {
      const d = await res.json()
      user.value = d.user
      localStorage.setItem('timejumper_user', JSON.stringify(d.user))
    }
  }

  return {
    token,
    user,
    isAuthenticated,
    setAuth,
    login,
    register,
    logout,
    fetchUser,
  }
})
