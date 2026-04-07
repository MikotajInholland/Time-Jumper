import pastWallUrl from '../assets/textures/walls/past-wall.svg'
import futureWallUrl from '../assets/textures/walls/future-wall.svg'
import checkpointWallUrl from '../assets/textures/walls/checkpoint-wall.svg'
import goalWallUrl from '../assets/textures/walls/goal-wall.svg'
import pastObstacleWallUrl from '../assets/textures/walls/obstacle-wall.svg'
import futureObstacleWallUrl from '../assets/textures/walls/future-obstacle-wall.svg'

/**
 * Placeholder Textures
 *
 * A fallback texture is generated on canvas first, then replaced by a file-based
 * placeholder from `src/assets/textures` once the image finishes loading.
 */

export const TEX_SIZE = 128

// ??? internal helpers ????????????????????????????????????????????????????????

function makeCanvas() {
  const c = document.createElement('canvas')
  c.width = c.height = TEX_SIZE
  return c
}

/** Two-tone checker fill covering the full tile. */
function fillChecker(ctx, size, light, dark) {
  const half = size >> 1
  ctx.fillStyle = light
  ctx.fillRect(0, 0, size, size)
  ctx.fillStyle = dark
  ctx.fillRect(0,    0,    half, half)
  ctx.fillRect(half, half, half, half)
}

/** Horizontal grid lines (bricks / panels). */
function drawHLines(ctx, size, color, rows) {
  ctx.strokeStyle = color
  ctx.lineWidth = 1
  for (let i = 0; i <= rows; i++) {
    const y = ((i / rows) * size) | 0
    ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(size, y); ctx.stroke()
  }
}

function hydrateTextureFromAsset(canvas, url) {
  const img = new Image()
  const applyImage = () => {
    const ctx = canvas.getContext('2d')
    if (!ctx) return
    ctx.clearRect(0, 0, TEX_SIZE, TEX_SIZE)
    ctx.drawImage(img, 0, 0, TEX_SIZE, TEX_SIZE)
  }

  img.onload = applyImage
  img.src = url

  if (img.complete && img.naturalWidth > 0) {
    applyImage()
  }
}

/** Full grid (horizontal + vertical). */
function drawGrid(ctx, size, color, divisions) {
  ctx.strokeStyle = color
  ctx.lineWidth = 1
  for (let i = 0; i <= divisions; i++) {
    const p = ((i / divisions) * size) | 0
    ctx.beginPath(); ctx.moveTo(0, p); ctx.lineTo(size, p); ctx.stroke()
    ctx.beginPath(); ctx.moveTo(p, 0); ctx.lineTo(p, size); ctx.stroke()
  }
}

// ??? public API ??????????????????????????????????????????????????????????????

/**
 * Generates one canvas element per wall type.
 * Returns an object keyed by wall type name.
 */
export function createPlaceholderTextures() {

  // ?? Past wall: amber brick ??????????????????????????????????????????????????
  const pastWall = makeCanvas()
  {
    const ctx = pastWall.getContext('2d')
    fillChecker(ctx, TEX_SIZE, '#c89456', '#835c34')
    drawHLines(ctx, TEX_SIZE, '#5a3b1f', 7)
    // Roman limestone blocks with staggered vertical joints.
    ctx.strokeStyle = '#3d1f06'
    ctx.lineWidth = 1
    for (let row = 0; row < 7; row++) {
      const offset = (row % 2 === 0) ? 0 : TEX_SIZE / 10
      for (let x = offset; x < TEX_SIZE; x += TEX_SIZE / 5) {
        const y0 = (row * TEX_SIZE / 7) | 0
        const y1 = ((row + 1) * TEX_SIZE / 7) | 0
        ctx.beginPath(); ctx.moveTo(x, y0); ctx.lineTo(x, y1); ctx.stroke()
      }
    }
    ctx.fillStyle = 'rgba(255,235,190,0.12)'
    for (let i = 0; i < 80; i++) {
      const x = Math.random() * TEX_SIZE
      const y = Math.random() * TEX_SIZE
      ctx.fillRect(x | 0, y | 0, 1, 1)
    }
  }
  hydrateTextureFromAsset(pastWall, pastWallUrl)

  // ?? Future wall: neon cyan grid ?????????????????????????????????????????????
  const futureWall = makeCanvas()
  {
    const ctx = futureWall.getContext('2d')
    fillChecker(ctx, TEX_SIZE, '#17304f', '#0b1526')
    drawGrid(ctx, TEX_SIZE, '#233e67', 8)
    ctx.strokeStyle = '#37d9ff'
    ctx.lineWidth = 2
    for (let x = 8; x < TEX_SIZE; x += 20) {
      ctx.beginPath()
      ctx.moveTo(x, 0)
      ctx.lineTo(x, TEX_SIZE)
      ctx.stroke()
    }
    ctx.fillStyle = 'rgba(58, 234, 255, 0.18)'
    for (let y = 6; y < TEX_SIZE; y += 24) {
      ctx.fillRect(0, y, TEX_SIZE, 4)
    }
  }
  hydrateTextureFromAsset(futureWall, futureWallUrl)

  // ?? Checkpoint wall: vivid green ????????????????????????????????????????????
  const cpWall = makeCanvas()
  {
    const ctx = cpWall.getContext('2d')
    const g = ctx.createLinearGradient(0, 0, 0, TEX_SIZE)
    g.addColorStop(0, '#123a2b')
    g.addColorStop(1, '#0a241a')
    ctx.fillStyle = g
    ctx.fillRect(0, 0, TEX_SIZE, TEX_SIZE)
    drawGrid(ctx, TEX_SIZE, '#1f7448', 8)
    ctx.strokeStyle = '#34d17d'
    ctx.lineWidth = 2
    ctx.strokeRect(6, 6, TEX_SIZE - 12, TEX_SIZE - 12)
    ctx.beginPath()
    ctx.arc(TEX_SIZE / 2, TEX_SIZE / 2, TEX_SIZE * 0.25, 0, Math.PI * 2)
    ctx.strokeStyle = '#73ffae'
    ctx.lineWidth = 3
    ctx.stroke()
    ctx.beginPath()
    ctx.arc(TEX_SIZE / 2, TEX_SIZE / 2, TEX_SIZE * 0.14, 0, Math.PI * 2)
    ctx.fillStyle = '#58f08e'
    ctx.fill()
  }
  hydrateTextureFromAsset(cpWall, checkpointWallUrl)

  // ?? Goal wall: gold ?????????????????????????????????????????????????????????
  const goalWall = makeCanvas()
  {
    const ctx = goalWall.getContext('2d')
    const g = ctx.createLinearGradient(0, 0, TEX_SIZE, TEX_SIZE)
    g.addColorStop(0, '#5a4b14')
    g.addColorStop(1, '#2d2408')
    ctx.fillStyle = g
    ctx.fillRect(0, 0, TEX_SIZE, TEX_SIZE)
    drawHLines(ctx, TEX_SIZE, '#8c7424', 6)
    ctx.strokeStyle = '#e2c54d'
    ctx.lineWidth = 2
    ctx.strokeRect(6, 6, TEX_SIZE - 12, TEX_SIZE - 12)
    ctx.beginPath()
    ctx.arc(TEX_SIZE / 2, TEX_SIZE / 2, TEX_SIZE * 0.28, 0, Math.PI * 2)
    ctx.strokeStyle = '#f2d86a'
    ctx.lineWidth = 3
    ctx.stroke()
    ctx.beginPath()
    ctx.arc(TEX_SIZE / 2, TEX_SIZE / 2, TEX_SIZE * 0.2, 0, Math.PI * 2)
    ctx.strokeStyle = '#b38818'
    ctx.lineWidth = 2
    ctx.stroke()
    ctx.beginPath()
    ctx.arc(TEX_SIZE / 2, TEX_SIZE / 2, TEX_SIZE * 0.12, 0, Math.PI * 2)
    ctx.fillStyle = '#ffe168'
    ctx.fill()
  }
  hydrateTextureFromAsset(goalWall, goalWallUrl)

  // ?? Obstacle / hurdle wall: orange ??????????????????????????????????????????
  const pastObsWall = makeCanvas()
  {
    const ctx = pastObsWall.getContext('2d')
    fillChecker(ctx, TEX_SIZE, '#b86d2e', '#6a3f1b')
    drawHLines(ctx, TEX_SIZE, '#3d2310', 6)
    ctx.strokeStyle = '#4c2b12'
    for (let x = 8; x < TEX_SIZE; x += 24) {
      ctx.beginPath()
      ctx.moveTo(x, 0)
      ctx.lineTo(x, TEX_SIZE)
      ctx.stroke()
    }
  }
  hydrateTextureFromAsset(pastObsWall, pastObstacleWallUrl)

  const futureObsWall = makeCanvas()
  {
    const ctx = futureObsWall.getContext('2d')
    fillChecker(ctx, TEX_SIZE, '#143457', '#0a1a2f')
    drawGrid(ctx, TEX_SIZE, '#1e456f', 8)
    ctx.strokeStyle = '#38d7ff'
    ctx.lineWidth = 2
    for (let y = 10; y < TEX_SIZE; y += 22) {
      ctx.beginPath()
      ctx.moveTo(0, y)
      ctx.lineTo(TEX_SIZE, y)
      ctx.stroke()
    }
  }
  hydrateTextureFromAsset(futureObsWall, futureObstacleWallUrl)

  return { pastWall, futureWall, cpWall, goalWall, pastObsWall, futureObsWall }
}
