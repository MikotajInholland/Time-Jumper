<template>
  <div class="game-minimap-root">
    <div
      v-if="mode === 'center'"
      class="minimap-backdrop"
      aria-hidden="true"
      @pointerdown="onBackdropDown"
    />
    <div class="minimap-panel" :class="mode" @pointerdown.stop>
      <canvas
        ref="cvRef"
        class="minimap-cv"
        @contextmenu.prevent="onMapContextMenu"
      />
      <p v-if="mode === 'center'" class="minimap-legend">
        Back to corner: <strong>M</strong> · <strong>Esc</strong> · <strong>click outside</strong> · <strong>right-click</strong> the map
      </p>
      <p v-else class="minimap-legend corner">
        <strong>M</strong> — enlarge (center). Corner map stays on while you play.
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'

const C_EMPTY = 0
const C_WALL = 1
const C_CP = 2
const C_GOAL = 3
const C_OBS = 4
const C_PIT = 5

const props = defineProps({
  mode: { type: String, required: true },
  levelSeed: { type: Number, default: 0 },
  getSnapshot: { type: Function, required: true },
})

const emit = defineEmits(['setMode'])

const cvRef = ref(null)

let fogPast = null
let fogFuture = null
let fogW = 0
let fogH = 0
let lastSeed = -1

let rafId = 0

function revealCheb(fog, cx, cy, rad, w, h) {
  for (let dy = -rad; dy <= rad; dy++) {
    for (let dx = -rad; dx <= rad; dx++) {
      if (Math.max(Math.abs(dx), Math.abs(dy)) > rad) continue
      const x = cx + dx
      const y = cy + dy
      if (x >= 0 && x < w && y >= 0 && y < h) fog[y * w + x] = 1
    }
  }
}

function resetFog(snap) {
  const w = snap.mapWidth
  const h = snap.mapHeight
  fogPast = new Uint8Array(w * h)
  fogFuture = new Uint8Array(w * h)
  fogW = w
  fogH = h
  const sx = Math.floor(snap.startX)
  const sy = Math.floor(snap.startY)
  revealCheb(fogPast, sx, sy, 3, w, h)
  revealCheb(fogFuture, sx, sy, 3, w, h)
}

watch(
  () => props.levelSeed,
  () => {
    lastSeed = -1
  }
)

function cellColor(c, era) {
  if (c === C_WALL) return era === 'past' ? '#3d2e22' : '#152a38'
  if (c === C_PIT) return era === 'past' ? '#7a2040' : '#4a1866'
  if (c === C_OBS) return era === 'past' ? '#6b4420' : '#2244aa'
  if (c === C_CP) return '#22c55e'
  if (c === C_GOAL) return '#eab308'
  return era === 'past' ? '#6b5344' : '#2d3d52'
}

function draw() {
  const canvas = cvRef.value
  if (!canvas) return

  const snap = props.getSnapshot()
  if (!snap || !snap.mapWidth) return

  if (lastSeed !== props.levelSeed || fogW !== snap.mapWidth || fogH !== snap.mapHeight) {
    resetFog(snap)
    lastSeed = props.levelSeed
  }

  const mx = Math.floor(snap.px)
  const my = Math.floor(snap.py)
  const fog = snap.era === 'past' ? fogPast : fogFuture
  const map = snap.era === 'past' ? snap.mapPast : snap.mapFuture
  revealCheb(fog, mx, my, 2, snap.mapWidth, snap.mapHeight)

  const vw = window.innerWidth
  const vh = window.innerHeight
  const Rcss = props.mode === 'center' ? Math.min(vw, vh) * 0.36 : Math.min(vw, vh) * 0.14
  const dpr = Math.min(2, window.devicePixelRatio || 1)
  const side = Math.ceil((Rcss + 6) * 2 * dpr)
  if (canvas.width !== side || canvas.height !== side) {
    canvas.width = side
    canvas.height = side
  }
  canvas.style.width = `${Rcss * 2 + 12}px`
  canvas.style.height = `${Rcss * 2 + 12}px`

  const ctx = canvas.getContext('2d')
  const cx = side / 2
  const cy = side / 2
  const R = Rcss * dpr
  ctx.setTransform(1, 0, 0, 1, 0, 0)
  ctx.clearRect(0, 0, side, side)

  ctx.save()
  ctx.beginPath()
  ctx.arc(cx, cy, R, 0, Math.PI * 2)
  ctx.clip()

  ctx.fillStyle = 'rgba(12,12,18,0.94)'
  ctx.fillRect(0, 0, side, side)

  const mw = snap.mapWidth
  const mh = snap.mapHeight
  // Scale so the whole grid (and markers at corners) fits inside the circular clip.
  // Using max(mw,mh) oversizes square maps: corner cells sit outside radius R and get clipped,
  // so the player / checkpoints can disappear in the void.
  const mapHalfDiag = Math.hypot(mw, mh) / 2
  const inset = 0.88
  const u = (R * inset) / mapHalfDiag
  const ox = cx - (mw * u) / 2
  const oy = cy - (mh * u) / 2

  const sx0 = Math.floor(snap.startX)
  const sy0 = Math.floor(snap.startY)

  for (let y = 0; y < mh; y++) {
    for (let x = 0; x < mw; x++) {
      const i = y * mw + x
      const px0 = ox + x * u
      const py0 = oy + y * u
      const pw = Math.ceil(u) + 1
      const ph = Math.ceil(u) + 1

      const revealed = fog[i] === 1
      const c = map[y][x]

      if (!revealed) {
        ctx.fillStyle = '#1e1e2a'
        ctx.fillRect(px0, py0, pw, ph)
      } else {
        ctx.fillStyle = cellColor(c, snap.era)
        ctx.fillRect(px0, py0, pw, ph)
        if (c === C_PIT) {
          ctx.fillStyle = ((x + y) & 1) ? 'rgba(255,90,60,0.35)' : 'rgba(180,40,120,0.25)'
          ctx.fillRect(px0, py0, pw, ph)
        }
      }
    }
  }

  // Start (always)
  {
    const x = sx0
    const y = sy0
    const px0 = ox + x * u + u / 2
    const py0 = oy + y * u + u / 2
    const rr = Math.max(3 * dpr, u * 0.45)
    ctx.strokeStyle = '#22d3ee'
    ctx.lineWidth = 2 * dpr
    ctx.beginPath()
    ctx.arc(px0, py0, rr, 0, Math.PI * 2)
    ctx.stroke()
    ctx.fillStyle = 'rgba(34,211,238,0.25)'
    ctx.fill()
  }

  // Checkpoints (always visible)
  const cps = snap.checkpoints || []
  for (let i = 0; i < cps.length; i++) {
    const [cx0, cy0] = cps[i]
    const px0 = ox + cx0 * u + u / 2
    const py0 = oy + cy0 * u + u / 2
    const s = Math.max(2.5 * dpr, u * 0.35)
    ctx.fillStyle = '#4ade80'
    ctx.beginPath()
    ctx.moveTo(px0, py0 - s)
    ctx.lineTo(px0 + s, py0)
    ctx.lineTo(px0, py0 + s)
    ctx.lineTo(px0 - s, py0)
    ctx.closePath()
    ctx.fill()
    ctx.strokeStyle = '#14532d'
    ctx.lineWidth = dpr
    ctx.stroke()
  }

  // Goal when revealed
  if (snap.goalX >= 0 && snap.goalY >= 0) {
    const gi = snap.goalY * mw + snap.goalX
    if (fog[gi] === 1) {
      const px0 = ox + snap.goalX * u
      const py0 = oy + snap.goalY * u
      const pw = Math.ceil(u) + 1
      ctx.strokeStyle = '#fde047'
      ctx.lineWidth = 2 * dpr
      ctx.strokeRect(px0 + dpr, py0 + dpr, pw - 2 * dpr, pw - 2 * dpr)
    }
  }

  // Player + facing
  const ppx = ox + snap.px * u
  const ppy = oy + snap.py * u
  ctx.fillStyle = '#ffffff'
  ctx.beginPath()
  ctx.arc(ppx, ppy, Math.max(2.2 * dpr, u * 0.28), 0, Math.PI * 2)
  ctx.fill()

  const ang = Math.atan2(snap.dirY, snap.dirX)
  const alen = Math.max(6 * dpr, u * 0.9)
  ctx.strokeStyle = '#f472b6'
  ctx.lineWidth = 2 * dpr
  ctx.lineCap = 'round'
  ctx.beginPath()
  ctx.moveTo(ppx, ppy)
  ctx.lineTo(ppx + Math.cos(ang) * alen, ppy + Math.sin(ang) * alen)
  ctx.stroke()
  const aw = 0.45
  ctx.beginPath()
  ctx.moveTo(ppx + Math.cos(ang) * alen, ppy + Math.sin(ang) * alen)
  ctx.lineTo(ppx + Math.cos(ang + Math.PI - aw) * alen * 0.45, ppy + Math.sin(ang + Math.PI - aw) * alen * 0.45)
  ctx.lineTo(ppx + Math.cos(ang + Math.PI + aw) * alen * 0.45, ppy + Math.sin(ang + Math.PI + aw) * alen * 0.45)
  ctx.closePath()
  ctx.fillStyle = '#f472b6'
  ctx.fill()

  ctx.restore()

  ctx.beginPath()
  ctx.arc(cx, cy, R, 0, Math.PI * 2)
  ctx.strokeStyle = 'rgba(255,255,255,0.45)'
  ctx.lineWidth = 2 * dpr
  ctx.stroke()
}

function tick() {
  draw()
  rafId = requestAnimationFrame(tick)
}

function onBackdropDown() {
  emit('setMode', 'corner')
}

function onMapContextMenu() {
  emit('setMode', 'corner')
}

watch(
  () => props.mode,
  () => requestAnimationFrame(draw)
)

onMounted(() => {
  rafId = requestAnimationFrame(tick)
  window.addEventListener('resize', draw)
})

onBeforeUnmount(() => {
  cancelAnimationFrame(rafId)
  window.removeEventListener('resize', draw)
})
</script>

<style scoped>
.game-minimap-root {
  pointer-events: none;
}

.minimap-backdrop {
  position: fixed;
  inset: 0;
  z-index: 40;
  pointer-events: auto;
  cursor: default;
  /* Game stays visible around the map */
  background: transparent;
}

.minimap-panel {
  position: fixed;
  z-index: 50;
  pointer-events: none;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
}

.minimap-panel.corner {
  right: 12px;
  bottom: 12px;
}

.minimap-panel.center {
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
}

.minimap-cv {
  display: block;
  border-radius: 50%;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.55);
  pointer-events: auto;
}

.minimap-legend {
  margin: 0;
  max-width: min(90vw, 28rem);
  text-align: center;
  font-size: 0.78rem;
  line-height: 1.35;
  color: rgba(255, 255, 255, 0.88);
  text-shadow: 0 1px 4px #000;
  pointer-events: none;
}

.minimap-legend.corner {
  max-width: 11rem;
  font-size: 0.68rem;
  color: rgba(255, 255, 255, 0.75);
}

.minimap-legend strong {
  color: #a5f3fc;
  font-weight: 700;
}
</style>
