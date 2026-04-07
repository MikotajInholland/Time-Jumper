<template>
  <div class="hud">
    <div class="top">
      <span class="timer" ref="timerEl">0.00</span>
      <span class="time-state" :class="gameStore.gameTimeState">{{ gameStore.gameTimeState }}</span>
    </div>
    <div class="center">
      <span class="crosshair"></span>
    </div>
    <div v-if="showSubmit" class="finish-overlay">
      <p class="final-time">Time: {{ formatTime(finalTimeMs) }}</p>
      <button v-if="ranked && auth.isAuthenticated" class="btn primary" @click="submit" :disabled="submitting">
        {{ submitting ? 'Submitting…' : 'Submit score' }}
      </button>
      <p v-if="ranked && submitOk" class="ok">Score submitted! Check the leaderboard.</p>
      <router-link v-if="ranked && submitOk" to="/leaderboard" class="btn primary">View leaderboard</router-link>
      <p v-if="ranked && !auth.isAuthenticated" class="login-prompt">Log in to submit your score.</p>
      <p v-if="!ranked" class="login-prompt">Casual run: leaderboard submission is disabled.</p>
      <p v-if="ranked && submitError" class="error">{{ submitError }}</p>
      <router-link to="/" class="btn ghost">Back to menu</router-link>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onBeforeUnmount } from 'vue'
import { useGameStore } from '../stores/game'
import { useAuthStore } from '../stores/auth'

const props = defineProps({
  ranked: { type: Boolean, default: true },
})

const gameStore = useGameStore()
const auth = useAuthStore()
const timerEl = ref(null)
const finalTimeMs = ref(0)
const showSubmit = ref(false)
const replay = ref(null)
const seed = ref(null)
const submitting = ref(false)
const submitError = ref('')
const submitOk = ref(false)

function formatTime(ms) {
  return (ms / 1000).toFixed(2) + 's'
}

function setFinalTime(ms) {
  finalTimeMs.value = ms
}
function setReplay(r) {
  replay.value = r
}
function setSeed(s) {
  seed.value = s
}
function showSubmitPanel() {
  showSubmit.value = true
  submitOk.value = false
}

defineExpose({
  setFinalTime,
  setReplay,
  setSeed,
  showSubmitPanel,
})

watch(() => gameStore.gameTimeMs, (ms) => {
  if (!timerEl.value) return
  timerEl.value.textContent = formatTime(ms)
}, { immediate: true })

watch(() => gameStore.gameRunning, (running) => {
  if (!running) return
  showSubmit.value = false
  submitError.value = ''
  submitOk.value = false
})

onBeforeUnmount(() => {
  gameStore.resetGameState()
})

async function submit() {
  if (!props.ranked || !replay.value || seed.value == null || !auth.token) return
  submitting.value = true
  submitError.value = ''
  try {
    await gameStore.submitScore(auth.token, replay.value, seed.value)
    submitError.value = ''
    submitOk.value = true
    showSubmit.value = true
  } catch (e) {
    submitOk.value = false
    submitError.value = e.message || 'Submit failed'
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.hud {
  position: fixed;
  inset: 0;
  pointer-events: none;
  z-index: 10;
}
.hud > * {
  pointer-events: auto;
}
.top {
  position: absolute;
  top: 1rem;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: 1.5rem;
}
.timer {
  font-family: var(--font-mono);
  font-size: 1.75rem;
  font-weight: 600;
  color: var(--accent);
  text-shadow: 0 0 12px rgba(0, 212, 170, 0.5);
}
.time-state {
  font-size: 0.9rem;
  padding: 0.25rem 0.6rem;
  border-radius: 6px;
  text-transform: uppercase;
  font-weight: 600;
}
.time-state.past {
  background: rgba(107, 127, 215, 0.3);
  color: var(--past);
}
.time-state.future {
  background: rgba(215, 107, 127, 0.3);
  color: var(--future);
}
.center {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
}
.crosshair {
  width: 12px;
  height: 12px;
  border: 2px solid rgba(255,255,255,0.6);
  border-radius: 50%;
}
.finish-overlay {
  position: absolute;
  inset: 0;
  background: rgba(0,0,0,0.75);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  pointer-events: auto;
}
.final-time {
  font-family: var(--font-mono);
  font-size: 2rem;
  color: var(--accent);
}
.finish-overlay .btn {
  padding: 0.75rem 1.5rem;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  border: none;
  font-size: 1rem;
  text-decoration: none;
  display: inline-block;
}
.finish-overlay .btn.primary {
  background: var(--accent);
  color: var(--bg);
}
.finish-overlay .btn.ghost {
  background: transparent;
  color: var(--muted);
}
.finish-overlay .error { color: #f87171; }
.finish-overlay .ok { color: #4ade80; }
.finish-overlay .login-prompt { color: var(--muted); }
</style>
