<template>
  <div class="home">
    <header class="hero">
      <h1>Time Jumper</h1>
        <p class="tagline">Parkour-first levels: leaps, drops, chasms, and forced timeline swaps—daily ranked or random casual.</p>
      <nav class="actions">
        <router-link to="/play" class="btn primary">Play Daily (Ranked)</router-link>
        <router-link to="/play/random" class="btn secondary">Play Random (Casual)</router-link>
        <router-link to="/leaderboard" class="btn secondary">Leaderboard</router-link>
        <template v-if="auth.isAuthenticated">
          <span class="user">{{ auth.user?.name }}</span>
          <button type="button" class="btn ghost" @click="auth.logout()">Log out</button>
        </template>
        <template v-else>
          <router-link to="/login" class="btn ghost">Log in</router-link>
          <router-link to="/register" class="btn outline">Register</router-link>
        </template>
      </nav>
    </header>
    <section class="api-status" :class="{ offline: !apiOk }">
      <span class="dot"></span>
      {{ apiOk ? 'Backend connected' : 'Backend offline — start Docker (api + nginx)' }}
    </section>
    <section class="about">
      <h2>How to play</h2>
      <ul>
        <li><kbd>W</kbd> <kbd>A</kbd> <kbd>S</kbd> <kbd>D</kbd> — Move</li>
        <li><kbd>Space</kbd> — Jump (clear low walls; cross chasms while moving)</li>
        <li><kbd>Ctrl</kbd> — Sprint (longer jumps)</li>
        <li><kbd>Shift</kbd> — Time jump (swap past ↔ future)</li>
        <li><kbd>M</kbd> — Minimap is always in the corner; <kbd>M</kbd> toggles a large center map (game visible around it). <kbd>M</kbd> again shrinks to the corner. From the big map you can also use <kbd>Esc</kbd>, click outside the circle, or right-click the map to return to the corner.</li>
        <li>Daily mode uses one shared map and supports leaderboard submission.</li>
        <li>Random mode generates a fresh map every run and does not submit scores.</li>
      </ul>
    </section>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const apiOk = ref(false)

onMounted(async () => {
  try {
    const r = await fetch((import.meta.env.VITE_API_URL || '/api') + '/ping', { credentials: 'include' })
    apiOk.value = r.ok
  } catch {
    apiOk.value = false
  }
})
</script>

<style scoped>
.home {
  max-width: 720px;
  margin: 0 auto;
  padding: 3rem 1.5rem;
}
.hero {
  text-align: center;
  margin-bottom: 3rem;
}
.hero h1 {
  font-family: var(--font-mono);
  font-size: clamp(2.5rem, 6vw, 4rem);
  font-weight: 800;
  background: linear-gradient(135deg, var(--accent), var(--past));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  margin-bottom: 0.5rem;
}
.tagline {
  color: var(--muted);
  font-size: 1.15rem;
  margin-bottom: 2rem;
}
.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  justify-content: center;
  align-items: center;
}
.btn {
  display: inline-block;
  padding: 0.65rem 1.25rem;
  border-radius: 8px;
  font-weight: 600;
  text-decoration: none;
  transition: transform 0.15s, box-shadow 0.15s;
  border: none;
  cursor: pointer;
  font-family: inherit;
  font-size: 1rem;
}
.btn.primary {
  background: var(--accent);
  color: var(--bg);
}
.btn.primary:hover { box-shadow: 0 0 24px rgba(0, 212, 170, 0.4); }
.btn.secondary {
  background: var(--surface);
  color: var(--accent);
  border: 1px solid var(--accent-dim);
}
.btn.outline {
  background: transparent;
  color: var(--text);
  border: 1px solid var(--muted);
}
.btn.ghost {
  background: transparent;
  color: var(--muted);
}
.btn:hover { transform: translateY(-1px); }
.user {
  color: var(--muted);
  font-size: 0.95rem;
}
.api-status {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  font-size: 0.9rem;
  color: var(--muted);
  border-top: 1px solid var(--surface);
}
.api-status .dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--accent);
}
.api-status.offline .dot {
  background: #f87171;
}
.api-status.offline {
  color: #f87171;
}
.about {
  padding: 2rem 0;
  border-top: 1px solid var(--surface);
}
.about h2 {
  font-size: 1.25rem;
  margin-bottom: 1rem;
  color: var(--text);
}
.about ul {
  list-style: none;
}
.about li {
  padding: 0.35rem 0;
  color: var(--muted);
}
kbd {
  display: inline-block;
  padding: 0.15rem 0.5rem;
  background: var(--surface);
  border-radius: 4px;
  font-family: var(--font-mono);
  font-size: 0.85em;
  margin-right: 0.25rem;
}
</style>
