<template>
  <div class="leaderboard-page">
    <header class="header">
      <router-link to="/" class="back">← Home</router-link>
      <h1>Daily Leaderboard</h1>
      <p class="date">{{ gameStore.leaderboardDate || '—' }}</p>
    </header>
    <div class="table-wrap">
      <table v-if="gameStore.leaderboard.length">
        <thead>
          <tr>
            <th>#</th>
            <th>Player</th>
            <th>Time</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in gameStore.leaderboard" :key="row.rank">
            <td>{{ row.rank }}</td>
            <td>{{ row.user_name }}</td>
            <td>{{ formatTime(row.completion_time_ms) }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else class="empty">No scores yet for this day.</p>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useGameStore } from '../stores/game'

const gameStore = useGameStore()

function formatTime(ms) {
  const s = (ms / 1000).toFixed(2)
  return `${s}s`
}

onMounted(() => {
  gameStore.fetchLeaderboard()
})
</script>

<style scoped>
.leaderboard-page {
  max-width: 560px;
  margin: 0 auto;
  padding: 2rem 1.5rem;
}
.header { margin-bottom: 2rem; }
.back {
  display: inline-block;
  color: var(--muted);
  text-decoration: none;
  margin-bottom: 1rem;
  font-size: 0.95rem;
}
.back:hover { color: var(--accent); }
.header h1 { font-size: 1.75rem; margin-bottom: 0.25rem; }
.date { color: var(--muted); font-size: 1rem; }
.table-wrap {
  background: var(--surface);
  border-radius: 12px;
  overflow: hidden;
  border: 1px solid rgba(255,255,255,0.06);
}
table { width: 100%; border-collapse: collapse; }
th, td { padding: 0.75rem 1rem; text-align: left; }
th {
  background: rgba(0,0,0,0.2);
  font-weight: 600;
  color: var(--muted);
  font-size: 0.85rem;
}
tr:not(:last-child) td { border-bottom: 1px solid rgba(255,255,255,0.05); }
td:nth-child(1) { color: var(--muted); width: 48px; }
td:nth-child(3) { font-family: var(--font-mono); color: var(--accent); }
.empty {
  padding: 2rem;
  text-align: center;
  color: var(--muted);
}
</style>
