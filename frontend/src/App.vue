<template>
  <div class="app">
    <router-view v-slot="{ Component }">
      <transition name="fade" mode="out-in">
        <component :is="Component" />
      </transition>
    </router-view>
  </div>
</template>

<script setup>
</script>

<style>
:root {
  --bg: #0a0b0f;
  --surface: #12141c;
  --accent: #00d4aa;
  --accent-dim: #00a884;
  --past: #6b7fd7;
  --future: #d76b7f;
  --text: #e8eaef;
  --muted: #6b7280;
  --font-sans: 'Outfit', system-ui, sans-serif;
  --font-mono: 'JetBrains Mono', monospace;
}

* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

html, body, #app, .app {
  min-height: 100vh;
  background: var(--bg);
  color: var(--text);
  font-family: var(--font-sans);
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
