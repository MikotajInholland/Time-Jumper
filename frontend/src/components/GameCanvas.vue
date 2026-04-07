<template>
  <div class="game-root">
    <canvas ref="canvasEl" class="game-canvas" tabindex="0" @click="requestLock" />
    <GameMinimap
      v-if="level"
      :key="minimapKey"
      :mode="minimapMode"
      :level-seed="levelSeed"
      :get-snapshot="getMinimapSnapshot"
      @set-mode="minimapMode = $event"
    />
  </div>
</template>

<script setup>
import { ref, watch, onMounted, onBeforeUnmount, computed } from 'vue'
import { createEngine } from '../game/engine.js'
import { useGameStore } from '../stores/game'
import GameMinimap from './GameMinimap.vue'

const props = defineProps({
  level: { type: Object, default: null },
})

const emit = defineEmits(['finished'])

const canvasEl = ref(null)
const gameStore = useGameStore()
const minimapMode = ref('corner')
const minimapKey = ref(0)
const levelSeed = computed(() => (props.level?.seed != null ? Number(props.level.seed) : 0))

let engine = null

function getMinimapSnapshot() {
  return engine?.getMinimapSnapshot?.() ?? null
}

function startEngine() {
  if (!canvasEl.value || !props.level) return
  minimapMode.value = 'corner'
  minimapKey.value += 1
  engine = createEngine(canvasEl.value, {
    onTimeState(state) {
      gameStore.setGameState(true, undefined, state)
    },
    onTimeMs(ms) {
      gameStore.setGameState(true, ms, undefined)
    },
    onFinish(payload) {
      gameStore.setGameState(false, payload.completionTimeMs, undefined)
      emit('finished', payload)
    },
  })
  engine.resize()
  const onResize = () => engine?.resize()
  window.addEventListener('resize', onResize)
  window.addEventListener('keydown', handleKeyDown)
  window.addEventListener('keyup', handleKeyUp)
  engine._cleanup = () => {
    window.removeEventListener('resize', onResize)
    window.removeEventListener('keydown', handleKeyDown)
    window.removeEventListener('keyup', handleKeyUp)
  }
  engine.start(props.level)
}

function requestLock() {
  if (canvasEl.value) canvasEl.value.requestPointerLock()
}

function handleKeyDown(e) {
  if (e.key === 'm' || e.key === 'M') {
    e.preventDefault()
    minimapMode.value = minimapMode.value === 'center' ? 'corner' : 'center'
    return
  }
  if (e.key === 'Escape' && minimapMode.value === 'center') {
    e.preventDefault()
    minimapMode.value = 'corner'
    return
  }
  if (engine) engine.keyDown(e)
}

function handleKeyUp(e) {
  if (engine) engine.keyUp(e)
}

function stopEngine() {
  if (engine) {
    engine.stop()
    if (engine._cleanup) engine._cleanup()
    engine = null
  }
  gameStore.resetGameState()
}

watch(
  () => props.level,
  (level) => {
    minimapMode.value = 'corner'
    if (engine && level) {
      minimapKey.value += 1
      engine.stop()
      engine.start(level)
    }
  },
  { immediate: false }
)

onMounted(() => {
  if (props.level) startEngine()
})

onBeforeUnmount(() => {
  stopEngine()
})
</script>

<style scoped>
.game-root {
  position: relative;
  width: 100%;
  height: 100%;
}

.game-canvas {
  display: block;
  width: 100%;
  height: 100%;
  outline: none;
}
</style>
