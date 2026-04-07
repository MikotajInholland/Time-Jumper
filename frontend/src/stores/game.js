import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

const API = import.meta.env.VITE_API_URL || '/api'

function apiFetch(url, options = {}) {
  const headers = { Accept: 'application/json', ...options?.headers }
  return fetch(url, { ...options, headers }).catch((err) => {
    if (err.name === 'TypeError' && err.message.includes('fetch')) {
      throw new Error('Cannot reach server. Is the API running? Check Docker: api and nginx containers must be up.')
    }
    throw err
  })
}

export const useGameStore = defineStore('game', () => {
  const dailyLevel = ref(null)
  const dailyError = ref(null)
  const leaderboard = ref([])
  const leaderboardDate = ref('')

  const hasLevel = computed(() => !!dailyLevel.value)

  const gameTimeMs = ref(0)
  const gameTimeState = ref('past')
  const gameRunning = ref(false)
  function setGameState(running, timeMs, timeState) {
    gameRunning.value = running
    gameTimeMs.value = timeMs ?? gameTimeMs.value
    if (timeState !== undefined) gameTimeState.value = timeState
  }
  function resetGameState() {
    gameRunning.value = false
    gameTimeMs.value = 0
    gameTimeState.value = 'past'
  }

  async function fetchDaily(seed = null) {
    dailyError.value = null
    const url = seed != null ? `${API}/daily?seed=${seed}` : `${API}/daily`
    try {
      const res = await apiFetch(url, { credentials: 'include' })
      const data = await res.json().catch(() => ({}))
      if (!res.ok) {
        dailyError.value = data.message || data.error || 'Failed to load daily level'
        dailyLevel.value = null
        return
      }
      dailyLevel.value = data
      return dailyLevel.value
    } catch (e) {
      dailyError.value = e.message || 'Failed to load daily level'
      dailyLevel.value = null
    }
  }

  async function fetchLeaderboard(date = null) {
    const url = date ? `${API}/leaderboard?date=${date}` : `${API}/leaderboard`
    const res = await apiFetch(url, { credentials: 'include' })
    if (!res.ok) return
    const d = await res.json()
    leaderboard.value = d.leaderboard || []
    leaderboardDate.value = d.date || ''
    return d
  }

  async function submitScore(token, replay, seed) {
    const res = await apiFetch(`${API}/scores`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
      },
      credentials: 'include',
      body: JSON.stringify({ replay, seed }),
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) {
      let msg = data.message || 'Submit failed'
      if (data.errors && typeof data.errors === 'object') {
        const flat = Object.values(data.errors).flat()
        const first = flat[0]
        if (typeof first === 'string') msg = first
        else if (Array.isArray(first) && first[0]) msg = String(first[0])
      }
      if (data.replay_error?.context && typeof data.replay_error.context === 'object') {
        const ctx = JSON.stringify(data.replay_error.context)
        msg += ' Details: ' + (ctx.length > 800 ? ctx.slice(0, 800) + '…' : ctx)
      }
      throw new Error(msg)
    }
    return data
  }

  function clearLevel() {
    dailyLevel.value = null
    dailyError.value = null
  }

  return {
    dailyLevel,
    dailyError,
    hasLevel,
    leaderboard,
    leaderboardDate,
    gameTimeMs,
    gameTimeState,
    gameRunning,
    setGameState,
    resetGameState,
    fetchDaily,
    fetchLeaderboard,
    submitScore,
    clearLevel,
  }
})
