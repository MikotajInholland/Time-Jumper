<template>
  <div class="auth-page">
    <div class="card">
      <h1>Log in</h1>
      <form @submit.prevent="submit">
        <p v-if="error" class="error">{{ error }}</p>
        <input v-model="email" type="email" placeholder="Email" required autocomplete="email" />
        <input v-model="password" type="password" placeholder="Password" required autocomplete="current-password" />
        <button type="submit" class="btn primary" :disabled="loading">Log in</button>
      </form>
      <p class="foot">
        No account? <router-link to="/register">Register</router-link>
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push(route.query.redirect || '/')
  } catch (e) {
    error.value = e.message || 'Login failed'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.auth-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem;
}
.card {
  width: 100%;
  max-width: 360px;
  background: var(--surface);
  border-radius: 12px;
  padding: 2rem;
  border: 1px solid rgba(255,255,255,0.06);
}
.card h1 {
  font-size: 1.5rem;
  margin-bottom: 1.5rem;
  text-align: center;
}
.card input {
  width: 100%;
  padding: 0.75rem 1rem;
  margin-bottom: 1rem;
  background: var(--bg);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 8px;
  color: var(--text);
  font-size: 1rem;
}
.card input::placeholder { color: var(--muted); }
.card .error {
  color: #f87171;
  font-size: 0.9rem;
  margin-bottom: 1rem;
}
.card .btn {
  width: 100%;
  padding: 0.75rem;
  margin-top: 0.5rem;
  border: none;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  background: var(--accent);
  color: var(--bg);
  font-size: 1rem;
}
.card .btn:disabled { opacity: 0.6; cursor: not-allowed; }
.card .foot {
  margin-top: 1.5rem;
  text-align: center;
  color: var(--muted);
  font-size: 0.95rem;
}
.card .foot a { color: var(--accent); }
</style>
