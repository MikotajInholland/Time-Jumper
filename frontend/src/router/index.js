import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const routes = [
  {
    path: '/',
    name: 'Home',
    component: () => import('../views/HomeView.vue'),
    meta: { guest: true },
  },
  {
    path: '/login',
    name: 'Login',
    component: () => import('../views/LoginView.vue'),
    meta: { guest: true },
  },
  {
    path: '/register',
    name: 'Register',
    component: () => import('../views/RegisterView.vue'),
    meta: { guest: true },
  },
  {
    path: '/play',
    name: 'PlayDaily',
    component: () => import('../views/PlayView.vue'),
    props: { mode: 'daily' },
    meta: { requiresAuth: false },
  },
  {
    path: '/play/random',
    name: 'PlayRandom',
    component: () => import('../views/PlayView.vue'),
    props: { mode: 'random' },
    meta: { requiresAuth: false },
  },
  {
    path: '/leaderboard',
    name: 'Leaderboard',
    component: () => import('../views/LeaderboardView.vue'),
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach((to, _from, next) => {
  const auth = useAuthStore()
  if (to.meta.requiresAuth && !auth.token) {
    next({ name: 'Login', query: { redirect: to.fullPath } })
    return
  }
  if (to.meta.guest && auth.token && (to.name === 'Login' || to.name === 'Register')) {
    next({ name: 'Home' })
    return
  }
  next()
})

export default router
