<template>
  <div class="play-view">
    <div v-if="!levelReady" class="loading">
      <p v-if="gameStore.dailyError">{{ gameStore.dailyError }}</p>
      <p v-else>{{ isDailyMode ? 'Loading daily level…' : 'Generating random level…' }}</p>
      <router-link v-if="gameStore.dailyError" to="/">Back home</router-link>
    </div>
    <template v-else>
      <GameCanvas
        :level="gameStore.dailyLevel"
        @finished="onFinished"
      />
      <GameHUD ref="hudRef" :ranked="isDailyMode" />
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useGameStore } from '../stores/game'
import GameCanvas from '../components/GameCanvas.vue'
import GameHUD from '../components/GameHUD.vue'

const props = defineProps({
  mode: { type: String, default: 'daily' },
})

const gameStore = useGameStore()
const levelReady = ref(false)
const hudRef = ref(null)

const isDailyMode = computed(() => props.mode !== 'random')

onMounted(async () => {
  const seed = isDailyMode.value ? null : Math.floor(Math.random() * 2_000_000_000)
  await gameStore.fetchDaily(seed)
  levelReady.value = !!gameStore.dailyLevel
})

onBeforeUnmount(() => {
  gameStore.clearLevel()
})

function onFinished(payload) {
  if (hudRef.value) {
    hudRef.value.setFinalTime(payload.completionTimeMs)
    hudRef.value.setReplay(payload.replay)
    hudRef.value.setSeed(isDailyMode.value ? gameStore.dailyLevel?.seed : null)
    hudRef.value.showSubmitPanel()
  }
}
</script>

<style scoped>
.play-view {
  position: relative;
  width: 100vw;
  height: 100vh;
  overflow: hidden;
  background: #000;
}
.loading {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  color: var(--text);
}
.loading a { color: var(--accent); }
</style>
