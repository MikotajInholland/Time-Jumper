/**
 * Time Jumper — Proper DDA Raycasting Engine v3
 *
 * FIXES & IMPROVEMENTS:
 * - True DDA: dirX/dirY + planeX/planeY vectors (no angle → no shear bug)
 * - Rotation uses temp variable so vectors never corrupt
 * - Per-pixel floor/ceiling casting with checkerboard pattern (ImageData)
 * - Walls drawn on top via drawImage texture strips with fog overlay
 * - Placeholder textures (magenta checker) until real assets are supplied
 * - Jump adjusts the horizon line so floor/ceiling shift correctly
 * - Chromatic aberration on time-swap
 * - Velocity + friction + coyote time + wall sliding
 */

import { createPlaceholderTextures, TEX_SIZE } from './textures.js'

const CELL_EMPTY      = 0
const CELL_WALL       = 1
const CELL_CHECKPOINT = 2
const CELL_GOAL       = 3
const CELL_JUMP_OBS   = 4
/** Unwalkable chasm: not solid — cross only while airborne (parkour gaps). */
const CELL_PIT        = 5

// Physics (Parkour Overhaul)
const ACCEL     = 45         // Much snappier acceleration
const FRICTION  = 0.82       // Slight drift for momentum
const ROT_SPEED = 2.5
const SPRINT_MULT = 1.6
const MOUSE_SENS  = 0.0025
const LOOK_SENS   = 0.33
const JUMP_VEL  = 9.5        // Higher jump
const GRAVITY   = 28         // Faster fall (less floaty)
const RADIUS    = 0.19       // Slightly slimmer — fewer “invisible” wall clips
const COYOTE_T  = 0.22       // Longer coyote — jumps feel less strict
const VOID_LEVEL = -3.2      // More fall room before reset over pits
/** Seconds standing on unwalkable floor before reset (stops flicker insta-deaths). */
const BAD_GROUND_GRACE = 0.18
/** After respawn, ignore fatal floor/void checks briefly. */
const RESPAWN_GRACE = 0.55
const DEATH_FLASH_FRAMES = 78
/** Jump this high to pass low obstacles (lower = easier). */
const JUMP_OBS_CLEAR_Z = 0.38

// Render
const FLOOR_SCALE = 4          // 1 pixel = 4x4 block on screen (retro floor) for performance
const MAX_DIST    = 24
const HORIZON_SCALE = 0.30
const PLANE_BASE  = 0.70      // camera plane length → ~70° FOV (standard)
const PLANE_MAX   = 0.70      // disabled dynamic FOV to keep sensitivity consistent
const SWAP_FRAMES = 18
const MAX_LOOK_RATIO = 0.24

// Head bob
const BOB_AMP = 0.016
const BOB_SPD = 9.0

// Era palettes — deliberately vivid so they're unmistakeable
// PAST  = warm sandstone (amber walls, tan floor, cream ceiling)
// FUTURE = neon cyberpunk (electric cyan walls, dark-blue floor, purple ceiling)
const PALETTES = {
  past: {
    wallLit:  [220, 148,  70],   // lit sandstone amber
    wallShad: [148,  98,  44],   // shadow side (darker amber)
    cpWall:   [ 80, 220,  80],   // vivid green checkpoint
    goalWall: [245, 210,  55],   // gold goal
    obsWall:  [195, 115,  40],   // orange-amber obstacle
    mortar:   [ 85,  52,  22],   // dark mortar
    floor1:   [138, 102,  62],   // travertine cobble
    floor2:   [116,  83,  48],   // darker cobble
    ceil1:    [195, 158, 112],   // warm cream ceiling  ← clearly different from floor
    ceil2:    [158, 125,  85],   // slightly darker cream
    fog:      [ 48,  28,  12],   // warm dark fog
  },
  future: {
    wallLit:  [ 35, 215, 255],   // ELECTRIC CYAN  ← obviously different from past
    wallShad: [ 22, 148, 182],   // darker cyan
    cpWall:   [ 38, 255, 185],   // neon teal checkpoint
    goalWall: [ 48, 255, 110],   // neon green goal
    obsWall:  [ 55, 105, 228],   // electric blue obstacle
    mortar:   [ 18,  72, 115],   // dark cyan mortar
    floor1:   [ 24,  48,  78],   // dark metal
    floor2:   [ 13,  28,  46],   // darker steel
    ceil1:    [ 75,  38, 110],   // PURPLE ceiling  ← different from floor and from PAST
    ceil2:    [ 52,  25,  78],   // darker purple
    fog:      [  8,  18,  38],   // cool dark fog
  },
}

const LIGHTS = {
  past: [
    { x: 2.5, y: 2.5, radius: 3.2, color: [255, 196, 120], intensity: 0.75, flicker: 0.06, pulse: 1.4 },
  ],
  future: [
    { x: 2.5, y: 2.5, radius: 4.0, color: [80, 210, 255], intensity: 0.85, flicker: 0.15, pulse: 3.2 },
  ],
}

export function createEngine(canvas, callbacks = {}) {
  const ctx = canvas.getContext('2d')
  let W = 0, H = 0
  
  // Mixed Resolution buffers
  let floorCanvas = null, floorCtx = null
  let floorData = null, floor32 = null

  // Textures
  let textures = null

  // Map
  let mapPast = [], mapFuture = [], currentMap = null
  let mapWidth = 0, mapHeight = 0

  // Camera (proper DDA vectors, never derived from a single angle)
  let px = 2.5, py = 2.5   // player position
  let startX = 2.5, startY = 2.5
  let dirX = 1.0, dirY = 0.0
  let planeX = 0.0, planeY = PLANE_BASE

  // Movement
  let vx = 0, vy = 0

  // Vertical (jump)
  let jumpZ   = 0       // world-space height
  let jumpVZ  = 0       // vertical velocity
  let renderJumpZ = 0   // smoothed camera jump height (visual only)
  let onGround = true
  let coyoteT  = 0
  let landSquish = 0    // landing camera squish counter

  // Animation
  let walkT = 0
  let swapFrames = 0
  let pendingMouseRot = 0  // mouse yaw (recorded as M in replay)
  let pendingArrowCarry = 0  // arrow turn applied next frame with mouse (matches server sim)
  let lookPitch = 0
  let pendingPitch = 0
  let deathFlash = 0
  let deathLabel = ''
  let deathSubLabel = ''
  /** 'fatal' = respawn; 'warn' = invalid input, no respawn */
  let deathOverlayKind = 'fatal'

  // Game state
  let lastCP = null
  let replay = []
  /** Physics steps executed (sent with replay so server uses the same step count / avg dt as rAF). */
  let replayPhysicsFrames = 0
  /** Wall ms at end of each physics frame (for server input timing + per-frame dt). */
  let frameBoundaryMs = []
  let startT = 0
  let running = false
  let rafId  = null
  let prevT  = 0
  let keys   = {}
  let jumpConsumed = false
  let offscreenCanvas = null
  let offscreenCtx = null
  let badGroundAccum = 0
  let respawnGraceT = 0

  const { onTimeState = () => {}, onTimeMs = () => {}, onFinish = () => {}, onAfterFrame = () => {} } = callbacks

  let levelCheckpoints = []
  let goalCellX = -1
  let goalCellY = -1

  // ───────────────────────────────────────────────────────── helpers ───

  function cellAt(map, x, y) {
    const ix = x | 0, iy = y | 0
    if (iy < 0 || iy >= map.length || ix < 0 || ix >= (map[0]?.length ?? 0)) return CELL_WALL
    return map[iy][ix] ?? CELL_WALL
  }

  function findGoalCell(maps) {
    for (let mi = 0; mi < maps.length; mi++) {
      const map = maps[mi]
      if (!map || !map.length) continue
      for (let iy = 0; iy < map.length; iy++) {
        const row = map[iy]
        for (let ix = 0; ix < row.length; ix++) {
          if (row[ix] === CELL_GOAL) return { x: ix, y: iy }
        }
      }
    }
    return null
  }

  function isBlockingWall(c) {
    return c === CELL_WALL
  }

  function isBlockingJumpObs(c) {
    return c === CELL_JUMP_OBS && jumpZ < JUMP_OBS_CLEAR_Z
  }

  function isWalkable(map, x, y) {
    const c = cellAt(map, x, y)
    return c === CELL_EMPTY || c === CELL_CHECKPOINT || c === CELL_GOAL
  }

  function isRayPassThrough(c) {
    return c === CELL_EMPTY || c === CELL_PIT
  }

  function solidAt(map, x, y) {
    const c = cellAt(map, x, y)
    if (c === CELL_PIT) return false
    return isBlockingWall(c) || isBlockingJumpObs(c)
  }

  function circleHitsWall(map, x, y) {
    const r = RADIUS
    const x0 = Math.floor(x - r), x1 = Math.ceil(x + r)
    const y0 = Math.floor(y - r), y1 = Math.ceil(y + r)
    for (let cy = y0; cy <= y1; cy++) {
      for (let cx = x0; cx <= x1; cx++) {
        if (!solidAt(map, cx + 0.5, cy + 0.5)) continue
        const nearX = Math.max(cx, Math.min(x, cx + 1))
        const nearY = Math.max(cy, Math.min(y, cy + 1))
        const dx = x - nearX, dy = y - nearY
        if (dx * dx + dy * dy < r * r) return true
      }
    }
    return false
  }

  function recordInput(key) {
    if (!running) return
    replay.push([Math.round(performance.now() - startT), key])
  }

  function triggerDeathIndicator(label, subLabel = '') {
    deathFlash = DEATH_FLASH_FRAMES
    deathLabel = label
    deathSubLabel = subLabel
    deathOverlayKind = 'fatal'
  }

  function triggerWarningOverlay(label, subLabel = '') {
    deathFlash = 48
    deathLabel = label
    deathSubLabel = subLabel
    deathOverlayKind = 'warn'
  }

  function respawnPlayer(mainLabel, subLabel) {
    triggerDeathIndicator(mainLabel, subLabel)
    badGroundAccum = 0
    respawnGraceT = RESPAWN_GRACE
    if (lastCP) {
      px = lastCP.x
      py = lastCP.y
      if (lastCP.era === 'past') {
        currentMap = mapPast
        onTimeState('past')
      } else {
        currentMap = mapFuture
        onTimeState('future')
      }
    } else {
      px = startX
      py = startY
      currentMap = mapPast
      onTimeState('past')
    }
    vx = 0
    vy = 0
    jumpZ = 0
    jumpVZ = 0
    renderJumpZ = 0
    onGround = true
    jumpConsumed = false
    landSquish = 0.5
  }

  // ─────────────────────────────────────────────────────── resize ───

  function resize() {
    W = canvas.width  = canvas.clientWidth
    H = canvas.height = canvas.clientHeight
    
    // Low-res floor buffer
    const fW = Math.ceil(W / FLOOR_SCALE)
    const fH = Math.ceil(H / FLOOR_SCALE)
    
    if (!floorCanvas || floorCanvas.width !== fW || floorCanvas.height !== fH) {
        floorCanvas = document.createElement('canvas')
        floorCanvas.width = fW
        floorCanvas.height = fH
        floorCtx = floorCanvas.getContext('2d')
        floorData = floorCtx.createImageData(fW, fH)
        floor32 = new Uint32Array(floorData.data.buffer)
    }
  }

  function getOffscreen() {
    if (!offscreenCanvas || offscreenCanvas.width !== W || offscreenCanvas.height !== H) {
      offscreenCanvas = document.createElement('canvas')
      offscreenCanvas.width = W
      offscreenCanvas.height = H
      offscreenCtx = offscreenCanvas.getContext('2d')
    }
    return offscreenCtx
  }

  // ──────────────────────────────────────────────── ray casting ───

  function castRay(rdx, rdy) {
    let mx = px | 0, my = py | 0
    const ddx = Math.abs(1 / (rdx || 1e-20))
    const ddy = Math.abs(1 / (rdy || 1e-20))
    let sx, sy, sdx, sdy
    if (rdx < 0) { sx = -1; sdx = (px - mx) * ddx }
    else         { sx =  1; sdx = (mx + 1 - px) * ddx }
    if (rdy < 0) { sy = -1; sdy = (py - my) * ddy }
    else         { sy =  1; sdy = (my + 1 - py) * ddy }

    let side = 0
    const hits = []
    
    for (let i = 0; i < MAX_DIST * 2; i++) {
      if (sdx < sdy) { sdx += ddx; mx += sx; side = 0 }
      else           { sdy += ddy; my += sy; side = 1 }
      
      const c = cellAt(currentMap, mx, my)
      if (!isRayPassThrough(c)) {
        const perp = Math.abs(side === 0 ? (mx - px + (1 - sx) / 2) / rdx : (my - py + (1 - sy) / 2) / rdy)
        const dist = Math.max(0.02, perp)
        if (dist > MAX_DIST) break
        
        const wx = side === 0 ? (py + dist * rdy) - my : (px + dist * rdx) - mx
        hits.push({ dist, side, cellType: c, wallX: wx - Math.floor(wx), mx, my })
        
        if (isBlockingWall(c) || c === CELL_GOAL || c === CELL_CHECKPOINT) {
          return hits
        }
      }
    }
    return hits
  }

  // ──────────────────────────────────────────── color utilities ───

  function rgb(c) { return `rgb(${c[0]},${c[1]},${c[2]})` }

  function fogMix(col, fog, dist) {
    const t = Math.max(0, Math.exp(-dist * 0.09))
    return [
      Math.round(col[0] * t + fog[0] * (1 - t)),
      Math.round(col[1] * t + fog[1] * (1 - t)),
      Math.round(col[2] * t + fog[2] * (1 - t)),
    ]
  }

  function clamp(v, min, max) {
    return Math.max(min, Math.min(max, v))
  }

  function floorHash(ix, iy) {
    let n = ((ix * 374761393) ^ (iy * 668265263)) >>> 0
    n = (n ^ (n >> 13)) * 1274126177
    return ((n ^ (n >> 16)) & 1023) / 1023
  }

  function sampleDynamicLight(wx, wy, dist, era, timeS) {
    const list = era === 'past' ? LIGHTS.past : LIGHTS.future
    let brightness = 0.4
    let tintR = 0, tintG = 0, tintB = 0

    for (let i = 0; i < list.length; i++) {
      const l = list[i]
      const dx = wx - l.x
      const dy = wy - l.y
      const d = Math.hypot(dx, dy)
      if (d >= l.radius) continue

      const phase = l.pulse * timeS + i * 1.37
      const flick = 1 + Math.sin(phase) * l.flicker
      const k = Math.pow(1 - d / l.radius, 2) * l.intensity * flick
      brightness += k
      tintR += l.color[0] * k
      tintG += l.color[1] * k
      tintB += l.color[2] * k
    }

    // Follow light attached to the player so lighting feels dynamic while moving.
    const pd = Math.hypot(wx - px, wy - py)
    const pr = 2.6
    if (pd < pr) {
      const pk = Math.pow(1 - pd / pr, 2) * 0.7
      const pCol = era === 'past' ? [255, 205, 132] : [95, 225, 255]
      brightness += pk
      tintR += pCol[0] * pk
      tintG += pCol[1] * pk
      tintB += pCol[2] * pk
    }

    brightness *= Math.max(0.35, Math.exp(-dist * 0.03))
    return {
      brightness: clamp(brightness, 0.2, 1.35),
      tint: [Math.min(255, tintR | 0), Math.min(255, tintG | 0), Math.min(255, tintB | 0)],
    }
  }

  // ─────────────────────────────────────────────── scene draw ───

  function drawScene() {
    if (!W || !H || !currentMap) return
    const pal = currentMap === mapPast ? PALETTES.past : PALETTES.future
    const fog = pal.fog

    const horizonY = H / 2 - renderJumpZ * H * HORIZON_SCALE
    const bobY     = Math.sin(walkT * BOB_SPD) * BOB_AMP * H
    const squish   = landSquish > 0 ? Math.sin(landSquish * Math.PI) * 0.028 * H : 0
    const camY     = Math.round(horizonY + bobY - squish + lookPitch)
    const era = currentMap === mapPast ? 'past' : 'future'
    const timeS = performance.now() * 0.001

    // ── 1. FLOOR / CEILING (Low-Res Buffer) ──────────────────────────────────────────
    const fW = floorCanvas.width
    const fH = floorCanvas.height
    const fCamY = Math.round(camY / FLOOR_SCALE)
    
    const voidInt = (255 << 24) | (0 << 16) | (0 << 8) | 0
    
    for (let y = 0; y < fH; y++) {
      const baseIdx = y * fW
      
      if (y <= fCamY) {
        // Balanced skies:
        // - Past: calm sunny day
        // - Future: overcast rainy night (no stripe artifacts)
        const p = fCamY - y
        const v = Math.max(0, 1 - p / (fH * 0.84))
        const yn = y / Math.max(1, fCamY)

        for (let x = 0; x < fW; x++) {
          const u = x / Math.max(1, fW - 1)
          let r, g, b

          if (era === 'past') {
            const top = [92, 160, 232]
            const bottom = [184, 218, 248]
            r = (top[0] * (1 - v) + bottom[0] * v) | 0
            g = (top[1] * (1 - v) + bottom[1] * v) | 0
            b = (top[2] * (1 - v) + bottom[2] * v) | 0

            const sunX = 0.72
            const sunY = 0.20
            const dx = u - sunX
            const dy = yn - sunY
            const d2 = dx * dx + dy * dy
            const halo = Math.max(0, 1 - d2 / 0.06)
            const core = Math.max(0, 1 - d2 / 0.012)
            r = clamp(r + 38 * halo + 52 * core, 0, 255) | 0
            g = clamp(g + 34 * halo + 44 * core, 0, 255) | 0
            b = clamp(b + 18 * halo + 20 * core, 0, 255) | 0

            const warm = Math.max(0, (v - 0.74) * 2.4)
            r = clamp(r + 15 * warm, 0, 255) | 0
            g = clamp(g + 9 * warm, 0, 255) | 0
          } else {
            const top = [7, 11, 20]
            const bottom = [17, 22, 35]
            r = (top[0] * (1 - v) + bottom[0] * v) | 0
            g = (top[1] * (1 - v) + bottom[1] * v) | 0
            b = (top[2] * (1 - v) + bottom[2] * v) | 0

            // Layered cloud mass (soft, not banded).
            const c1 = Math.sin(u * 7.4 + timeS * 0.12 + yn * 1.8) * 0.5 + 0.5
            const c2 = Math.sin(u * 13.2 - timeS * 0.16 + yn * 3.1) * 0.5 + 0.5
            const cloud = Math.max(0, (c1 * 0.62 + c2 * 0.38) - 0.38) * 0.62
            r = clamp(r + 14 * cloud, 0, 255) | 0
            g = clamp(g + 16 * cloud, 0, 255) | 0
            b = clamp(b + 20 * cloud, 0, 255) | 0

            // Uniform rain haze shimmer (no directional line pattern).
            const haze = (Math.sin((u * 41 + yn * 63) + timeS * 2.0) * 0.5 + 0.5) * 0.05
            r = clamp(r + 8 * haze, 0, 255) | 0
            g = clamp(g + 10 * haze, 0, 255) | 0
            b = clamp(b + 14 * haze, 0, 255) | 0
          }

          const fogT = Math.max(0, 1 - v)
          r = (r * (1 - fogT) + fog[0] * fogT) | 0
          g = (g * (1 - fogT) + fog[1] * fogT) | 0
          b = (b * (1 - fogT) + fog[2] * fogT) | 0
          floor32[baseIdx + x] = (255 << 24) | (b << 16) | (g << 8) | r
        }
        continue
      }

      // Floor
      const p = y - fCamY
      const eyeZ = 0.5 + renderJumpZ
      const rowDist = (eyeZ * fH) / (2 * p)
      
      const stepX = rowDist * (planeX * 2) / fW
      const stepY = rowDist * (planeY * 2) / fW
      let floorX = px + rowDist * (dirX - planeX)
      let floorY = py + rowDist * (dirY - planeY)
      
      const t = Math.max(0, Math.exp(-rowDist * 0.09))
      const fogInv = 1 - t
      
      for (let x = 0; x < fW; x++) {
        const cellX = floorX | 0
        const cellY = floorY | 0
        let color = voidInt
        
        if (cellX >= 0 && cellX < mapWidth && cellY >= 0 && cellY < mapHeight) {
             const pitCell = cellAt(currentMap, cellX, cellY) === CELL_PIT
             if (pitCell) {
               const fx = floorX - cellX
               const fy = floorY - cellY
               const edge = fx < 0.11 || fx > 0.89 || fy < 0.11 || fy > 0.89
               const stripe = ((cellX * 5 + cellY * 3) & 1) === 0
               let r, g, b
               if (edge) {
                 r = 255
                 g = 140
                 b = 45
               } else if (stripe) {
                 r = 185
                 g = 35
                 b = 75
               } else {
                 r = 55
                 g = 18
                 b = 72
               }
               const lit = Math.max(0, Math.exp(-rowDist * 0.09))
               const fogInv = 1 - lit
               const fr = (r * lit + fog[0] * fogInv) | 0
               const fg = (g * lit + fog[1] * fogInv) | 0
               const fb = (b * lit + fog[2] * fogInv) | 0
               floor32[baseIdx + x] = (255 << 24) | (fb << 16) | (fg << 8) | fr
               floorX += stepX
               floorY += stepY
               continue
             }
             // Draw floor for all in-bounds cells (including wall tiles) so jumps
             // do not reveal black void seams under wall bases.
             {
                 let base
                 if (era === 'past') {
                   // Roman cobblestone street with staggered stone joints.
                   const sx = floorX * 3.4
                   const sy = floorY * 3.4
                   const row = Math.floor(sy)
                   const stagger = (row & 1) ? 0.5 : 0
                   const stoneX = sx + stagger
                   const colIdx = Math.floor(stoneX)
                   const fu = stoneX - colIdx
                   const fv = sy - row
                   if (fu < 0.09 || fv < 0.08) {
                     base = pal.mortar
                   } else {
                     const n = floorHash(colIdx, row) * 10 - 5
                     const src = ((colIdx + row) & 1) ? pal.floor1 : pal.floor2
                     base = [
                       clamp(src[0] + n, 0, 255) | 0,
                       clamp(src[1] + n, 0, 255) | 0,
                       clamp(src[2] + n, 0, 255) | 0,
                     ]
                   }
                 } else {
                   const gx = Math.abs((floorX * 2.8) % 1 - 0.5)
                   const gy = Math.abs((floorY * 2.8) % 1 - 0.5)
                   const neonLine = gx > 0.46 || gy > 0.46
                   if (neonLine) base = [45, 180, 230]
                   else {
                     const check = (Math.floor(floorX * 2) + Math.floor(floorY * 2)) & 1
                     base = check ? pal.floor1 : pal.floor2
                   }
                 }

                 const light = sampleDynamicLight(floorX, floorY, rowDist, era, timeS)
                 const litR = clamp(base[0] * light.brightness + light.tint[0] * 0.08, 0, 255)
                 const litG = clamp(base[1] * light.brightness + light.tint[1] * 0.08, 0, 255)
                 const litB = clamp(base[2] * light.brightness + light.tint[2] * 0.08, 0, 255)
                 const r = (litR * t + fog[0] * fogInv) | 0
                 const g = (litG * t + fog[1] * fogInv) | 0
                 const b = (litB * t + fog[2] * fogInv) | 0
                 color = (255 << 24) | (b << 16) | (g << 8) | r
             }
        }
        floor32[baseIdx + x] = color
        floorX += stepX
        floorY += stepY
      }
    }
    
    floorCtx.putImageData(floorData, 0, 0)
    ctx.imageSmoothingEnabled = false
    ctx.drawImage(floorCanvas, 0, 0, W, H)

    // ── 3. WALLS (High-Res) ──────────────────────────────────────────────────────────
    // Cast 1 ray per pixel column
    for (let x = 0; x < W; x++) {
      const cameraX = 2 * x / W - 1
      const rdx = dirX + planeX * cameraX
      const rdy = dirY + planeY * cameraX
      
      const hits = castRay(rdx, rdy)
      
      for (let i = hits.length - 1; i >= 0; i--) {
        const { dist, side, cellType, wallX } = hits[i]
        const wallH = (H / dist) | 0
        const drawTop = Math.max(0, (camY - wallH / 2) | 0)
        const drawBottom = Math.min(H, (camY + wallH / 2) | 0)
        const drawH = drawBottom - drawTop
        if (drawH <= 0) continue

        let top = drawTop, height = drawH
        if (cellType === CELL_JUMP_OBS) {
          const half = (drawH * 0.45) | 0
          top = drawBottom - half
          height = half
        }

        // ── Texture strip ──
        let tex
        if      (cellType === CELL_CHECKPOINT) tex = textures.cpWall
        else if (cellType === CELL_GOAL)       tex = textures.goalWall
        else if (cellType === CELL_JUMP_OBS)   tex = (currentMap === mapPast ? textures.pastObsWall : textures.futureObsWall)
        else if (currentMap === mapPast)       tex = textures.pastWall
        else                                   tex = textures.futureWall

        const texX = Math.min(TEX_SIZE - 1, (wallX * TEX_SIZE) | 0)
        ctx.drawImage(tex, texX, 0, 1, TEX_SIZE, x, top, 1, height)

        const hitX = px + rdx * dist
        const hitY = py + rdy * dist
        const light = sampleDynamicLight(hitX, hitY, dist, era, timeS)
        const darken = 1 - Math.min(1, light.brightness)
        if (darken > 0.02) {
          ctx.fillStyle = `rgba(0,0,0,${darken.toFixed(3)})`
          ctx.fillRect(x, top, 1, height)
        }
        const glow = Math.max(0, light.brightness - 1)
        if (glow > 0.02) {
          const a = Math.min(0.35, glow * 0.45)
          const [lr, lg, lb] = light.tint
          ctx.fillStyle = `rgba(${lr},${lg},${lb},${a.toFixed(3)})`
          ctx.fillRect(x, top, 1, height)
        }

        // Shadow side: darken walls hit on the Y axis
        if (side === 1) {
          ctx.fillStyle = 'rgba(0,0,0,0.35)'
          ctx.fillRect(x, top, 1, height)
        }

        // Fog overlay
        const fogT = Math.max(0, 1 - Math.exp(-dist * 0.09))
        if (fogT > 0.02) {
          ctx.fillStyle = `rgba(${fog[0]},${fog[1]},${fog[2]},${fogT.toFixed(3)})`
          ctx.fillRect(x, top, 1, height)
        }

        // Goal glow edges
        if (cellType === CELL_GOAL) {
          ctx.fillStyle = 'rgba(255,240,60,0.55)'
          ctx.fillRect(x, top, 1, Math.min(4, height))
          ctx.fillRect(x, drawBottom - 4, 1, 4)
        }
        // Checkpoint glow edge
        if (cellType === CELL_CHECKPOINT) {
          ctx.fillStyle = 'rgba(80,255,160,0.50)'
          ctx.fillRect(x, top, 1, Math.min(4, height))
        }
      }
    }

    // ── 4. Chromatic aberration on time-swap ─────────────────────────────────────────
    if (swapFrames > 0) {
      const t = swapFrames / SWAP_FRAMES
      const a = (t * t * 0.45).toFixed(3)
      const grad = ctx.createLinearGradient(0, 0, W, 0)
      grad.addColorStop(0,   `rgba(255,40,60,${a})`)
      grad.addColorStop(0.4, 'rgba(0,0,0,0)')
      grad.addColorStop(0.6, 'rgba(0,0,0,0)')
      grad.addColorStop(1,   `rgba(60,120,255,${a})`)
      ctx.fillStyle = grad
      ctx.fillRect(0, 0, W, H)
      swapFrames--
    }

    if (deathFlash > 0) {
      const denom = deathOverlayKind === 'warn' ? 48 : DEATH_FLASH_FRAMES
      const t = deathFlash / denom
      if (deathOverlayKind === 'warn') {
        ctx.fillStyle = `rgba(255,170,40,${(t * 0.28).toFixed(3)})`
        ctx.fillRect(0, 0, W, H)
        ctx.fillStyle = `rgba(255,248,220,${(Math.min(1, t * 1.25)).toFixed(3)})`
      } else {
        ctx.fillStyle = `rgba(255,38,38,${(t * 0.34).toFixed(3)})`
        ctx.fillRect(0, 0, W, H)
        ctx.fillStyle = `rgba(255,220,220,${(Math.min(1, t * 1.35)).toFixed(3)})`
      }
      ctx.textAlign = 'center'
      ctx.textBaseline = 'middle'
      const titlePx = Math.round(Math.min(34, Math.max(22, W * 0.042)))
      const subPx = Math.round(Math.min(20, Math.max(14, W * 0.024)))
      ctx.font = `700 ${titlePx}px system-ui, sans-serif`
      ctx.fillText(deathLabel || 'Reset', W / 2, H * 0.16)
      if (deathSubLabel) {
        ctx.font = `500 ${subPx}px system-ui, sans-serif`
        ctx.fillStyle = `rgba(255,235,235,${(Math.min(1, t * 1.2)).toFixed(3)})`
        const lines = deathSubLabel.split('\n')
        for (let li = 0; li < lines.length; li++) {
          ctx.fillText(lines[li], W / 2, H * (0.16 + 0.038 * (li + 1)))
        }
      }
      deathFlash--
    }
  }

  // ─────────────────────────────────────────────── game loop ───

  function loop(timestamp) {
    if (!running) return
    replayPhysicsFrames++
    const dt = Math.min((timestamp - prevT) / 1000, 0.05)
    prevT = timestamp

    // ── Rotation: one apply per frame = mouse this interval + arrow from end of last frame ──
    const mousePart = pendingMouseRot
    if (running && mousePart !== 0) {
      replay.push([Math.round(performance.now() - startT), 'M', mousePart])
    }
    const rs = mousePart + pendingArrowCarry
    pendingMouseRot = 0
    pendingArrowCarry = 0
    if (rs !== 0) {
      const odx = dirX, opx = planeX
      dirX  =  odx   * Math.cos(rs) + dirY  * Math.sin(rs)
      dirY  = -odx   * Math.sin(rs) + dirY  * Math.cos(rs)
      planeX =  opx   * Math.cos(rs) + planeY * Math.sin(rs)
      planeY = -opx   * Math.sin(rs) + planeY * Math.cos(rs)
    }

    // ── Rotation (Arrow keys): carried into next frame (same as pendingArrowCarry on server) ──
    const keyRot = ROT_SPEED * dt
    if (keys['ArrowLeft'])  pendingArrowCarry += keyRot
    if (keys['ArrowRight']) pendingArrowCarry -= keyRot
    if (keys['PageUp']) pendingPitch -= 140 * dt
    if (keys['PageDown']) pendingPitch += 140 * dt

    // ── Prevent Vector Decay (Fixes warping) ──
    const dirLen = Math.hypot(dirX, dirY)
    if (dirLen > 0.0001) {
      dirX /= dirLen
      dirY /= dirLen
    }
    // Re-calculate plane to be perpendicular to dir
    const currentPlaneLen = Math.hypot(planeX, planeY)
    planeX = -dirY * currentPlaneLen
    planeY =  dirX * currentPlaneLen
    if (pendingPitch !== 0) {
      const maxLook = H * MAX_LOOK_RATIO
      lookPitch = clamp(lookPitch + pendingPitch, -maxLook, maxLook)
      pendingPitch = 0
    }

    // ── Movement (velocity + friction, forward along dirX/dirY) ───────
    let ax = 0, ay = 0
    if (keys['w'] || keys['ArrowUp'])   { ax += dirX; ay += dirY }
    if (keys['s'] || keys['ArrowDown']) { ax -= dirX; ay -= dirY }
    
    // Strafe with A/D (Left = rotate dir vector +90deg, Right = -90deg)
    // dir=(x,y) -> left=(y, -x), right=(-y, x)
    if (keys['a']) { ax += dirY; ay -= dirX }
    if (keys['d']) { ax -= dirY; ay += dirX }
    
    // Strafe with Q/E
    if (keys['q']) { ax += dirY; ay -= dirX }
    if (keys['e']) { ax -= dirY; ay += dirX }

    const mag = Math.hypot(ax, ay)
    if (mag > 0.001) {
      // Sprint multiplier
      const speedMult = keys['Control'] ? SPRINT_MULT : 1.0
      const scale = ACCEL * speedMult * dt / mag
      vx += ax * scale
      vy += ay * scale
      walkT += dt * speedMult
    } else {
      walkT *= 0.94
    }

    vx *= FRICTION
    vy *= FRICTION
    if (Math.hypot(vx, vy) < 0.003) { vx = 0; vy = 0 }

    // Wall sliding (separate X and Y)
    const nx = px + vx * dt
    const ny = py + vy * dt
    if (!circleHitsWall(currentMap, nx, py)) px = nx
    else vx = 0
    if (!circleHitsWall(currentMap, px, ny)) py = ny
    else vy = 0

    // ── Unsafe floor (pit / wrong timeline tile / obstacle) ──
    if (respawnGraceT > 0) {
      respawnGraceT -= dt
      badGroundAccum = 0
    } else if (onGround && !isWalkable(currentMap, px, py)) {
      badGroundAccum += dt
      if (badGroundAccum >= BAD_GROUND_GRACE) {
        const c = cellAt(currentMap, px, py)
        let sub = 'Tip: Shift only when both eras have floor under you.\nChasms need a jump while you move forward.'
        if (c === CELL_PIT) {
          sub = 'You stepped on a chasm tile.\nJump with Space while moving to clear gaps.'
        } else if (c === CELL_JUMP_OBS) {
          sub = 'Low wall — jump (Space) to hop over it.'
        } else if (c === CELL_WALL) {
          sub = 'Blocked tile under you — back up or strafe off the snag.'
        }
        respawnPlayer('No safe floor here', sub)
      }
    } else {
      badGroundAccum = 0
    }

    // ── Jump & gravity ────────────────────────────────────────────────
    if (onGround) coyoteT = COYOTE_T
    else coyoteT -= dt

    if (keys[' '] && coyoteT > 0 && !jumpConsumed) {
      jumpVZ = JUMP_VEL
      onGround = false
      coyoteT = 0
      jumpConsumed = true
    }
    jumpZ  += jumpVZ * dt
    jumpVZ -= GRAVITY * dt
    
    // Check floor collision
    if (jumpZ <= 0) {
      // If over a pit, keep falling until VOID_LEVEL
      if (isWalkable(currentMap, px, py)) {
        if (jumpZ < 0 && jumpVZ < -2) landSquish = 0.35
        jumpZ = 0; jumpVZ = 0; onGround = true
      }
    }
    
    if (jumpZ < VOID_LEVEL && respawnGraceT <= 0) {
      respawnPlayer(
        'Fell through a gap',
        'Keep forward speed and press Space over chasms.\nUse Ctrl + Space for longer jumps.'
      )
    }
    // Smooth visual follow for jump arc; keeps gameplay physics unchanged.
    const jumpFollowRate = onGround ? 18 : 12
    const jumpAlpha = 1 - Math.exp(-jumpFollowRate * dt)
    renderJumpZ += (jumpZ - renderJumpZ) * jumpAlpha
    if (Math.abs(renderJumpZ - jumpZ) < 0.0005) renderJumpZ = jumpZ

    // Softer landing settle than linear decay.
    if (landSquish > 0.0005) landSquish *= Math.exp(-dt * 8.5)
    else landSquish = 0

    const wallMs = Math.round(performance.now() - startT)
    frameBoundaryMs.push(wallMs)

    // ── Cell triggers ────────────────────────────────────────────────
    const c = cellAt(currentMap, px, py)
    if (c === CELL_CHECKPOINT) {
      lastCP = { x: px, y: py, era: currentMap === mapPast ? 'past' : 'future' }
    }
    if (c === CELL_GOAL) {
      running = false
      const ms = wallMs
      onTimeMs(ms)
      const inputsSnapshot = replay.map((row) => row.slice())
      const boundariesSnapshot = frameBoundaryMs.slice()
      onFinish({
        replay: {
          inputs: inputsSnapshot,
          claimedTimeMs: ms,
          frameCount: replayPhysicsFrames,
          frameBoundaryMs: boundariesSnapshot,
        },
        completionTimeMs: ms,
      })
      cancelAnimationFrame(rafId)
      rafId = null
      document.exitPointerLock && document.exitPointerLock()
      return
    }

    drawScene()
    onAfterFrame()
    onTimeMs(Math.round(performance.now() - startT))
    rafId = requestAnimationFrame(loop)
  }

  // ─────────────────────────────────────────── input ───

  const inputMap = {
    w: 'F', ArrowUp: 'F', s: 'B', ArrowDown: 'B',
    a: 'SL', d: 'SR', // A/D Strafe
    ArrowLeft: 'L', ArrowRight: 'R', // Arrows Turn
    ' ': 'J',
  }

  function mouseMove(e) {
    if (!running || !document.pointerLockElement) return
    pendingMouseRot -= e.movementX * MOUSE_SENS
    pendingPitch -= e.movementY * LOOK_SENS
  }

  function keyDown(e) {
    if (e.key === 'Control') {
      e.preventDefault()
      recordInput('SC')
      keys[e.key] = true
      return
    }
    if (e.key === 'Shift') {
      e.preventDefault()
      const nextMap = currentMap === mapPast ? mapFuture : mapPast
      if (!isWalkable(nextMap, px, py)) {
        triggerWarningOverlay(
          'Can’t enter that timeline here',
          'The other era has no floor at your feet.\nMove to a shared tile, then press Shift.'
        )
        keys[e.key] = true
        return
      }
      currentMap = nextMap
      onTimeState(currentMap === mapPast ? 'past' : 'future')
      swapFrames = SWAP_FRAMES
      recordInput('T')
      keys[e.key] = true
      return
    }
    const code = inputMap[e.key]
    if (code) {
      e.preventDefault()
      recordInput(code)
    }
    keys[e.key] = true
  }

  function keyUp(e) {
    if (e.key === 'Control') {
      recordInput('SC')
      keys[e.key] = false
      return
    }
    const code = inputMap[e.key]
    if (code) recordInput(code)
    if (e.key === ' ') jumpConsumed = false
    keys[e.key] = false
  }

  // ─────────────────────────────────── start / stop ───

  function start(level) {
    mapPast   = level.past   || []
    mapFuture = level.future || []
    mapWidth  = (mapPast[0] || []).length
    mapHeight = mapPast.length
    levelCheckpoints = level.checkpoints || []
    const gCell = findGoalCell([mapPast, mapFuture])
    goalCellX = gCell ? gCell.x : -1
    goalCellY = gCell ? gCell.y : -1
    const s = level.start || [1, 1]
    px = s[0] + 0.5
    py = s[1] + 0.5
    startX = px
    startY = py
    // Look toward the goal (bottom-right from top-left start)
    dirX = 1; dirY = 1
    const dl = Math.hypot(dirX, dirY)
    dirX /= dl; dirY /= dl
    // Camera plane perpendicular (rotate dir 90°)
    planeX = -dirY * PLANE_BASE
    planeY =  dirX * PLANE_BASE
    vx = 0; vy = 0
    jumpZ = 0; jumpVZ = 0; renderJumpZ = 0; onGround = true; coyoteT = COYOTE_T
    landSquish = 0; walkT = 0; swapFrames = 0; pendingMouseRot = 0; pendingArrowCarry = 0; lookPitch = 0; pendingPitch = 0
    deathFlash = 0; deathLabel = ''; deathSubLabel = ''; deathOverlayKind = 'fatal'
    badGroundAccum = 0
    respawnGraceT = 0.35
    currentMap = mapPast
    lastCP = null
    replay = []
    replayPhysicsFrames = 0
    frameBoundaryMs = []
    jumpConsumed = false
    textures = textures || createPlaceholderTextures()
    startT = performance.now()
    prevT  = startT
    running = true
    keys = {}
    onTimeState('past')
    onTimeMs(0)
    resize()
    
    document.addEventListener('mousemove', mouseMove)
    
    rafId = requestAnimationFrame(loop)
  }

  function stop() {
    running = false
    if (rafId) { cancelAnimationFrame(rafId); rafId = null }
    keys = {}
    document.removeEventListener('mousemove', mouseMove)
    document.exitPointerLock && document.exitPointerLock()
  }

  function getMinimapSnapshot() {
    if (!running || !mapWidth || !mapHeight) return null
    return {
      px,
      py,
      dirX,
      dirY,
      mapWidth,
      mapHeight,
      mapPast,
      mapFuture,
      era: currentMap === mapPast ? 'past' : 'future',
      startX,
      startY,
      checkpoints: levelCheckpoints,
      goalX: goalCellX,
      goalY: goalCellY,
    }
  }

  return { start, stop, resize, keyDown, keyUp, getMinimapSnapshot }
}
