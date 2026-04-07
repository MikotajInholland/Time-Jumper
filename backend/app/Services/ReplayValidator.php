<?php

namespace App\Services;

/**
 * Replays recorded inputs with physics aligned to frontend/src/game/engine.js and checks
 * that the player reaches the goal at approximately claimedTimeMs.
 *
 * Replay: { "inputs": [ [t_ms, code], ... ], "claimedTimeMs": int }
 * Toggles (keydown/keyup): F,B,L,R,J,SL,SR,SC. T = successful timeline swap at t.
 *
 * Mouse yaw: [t_ms, "M", deltaRad] per client frame when the player moved the mouse.
 * frameCount: physics steps in the run (same as rAF ticks).
 * frameBoundaryMs (optional): wall ms at end of each frame — input flush uses these; physics dt is min(wallDelta, 0.05s) like engine.js.
 */
final class ReplayValidator
{
    private const MAX_INPUT_EVENTS = 50000;

    private const MAX_TIME_DRIFT_MS = 10000;

    /** Residual float / uniform-dt vs variable client dt per step. */
    private const TIME_TOLERANCE_MS = 500;

    private const MAX_MOUSE_DELTA_RAD = 3.0;

    private const MAX_PHYSICS_FRAMES = 120000;

    /** Client caps each frame’s integration step at 0.05s (engine.js). */
    private const CLIENT_MAX_DT = 0.05;

    /**
     * claimedTimeMs is wall-clock; Σ(dt) per frame is ≤ 0.05 each, so wall can be slightly above frameCount×0.05
     * (first rAF gap, vsync). We validate average dt = wall/frameCount against this slack cap, not frameCount ≥ wall/0.05.
     */
    private const CLIENT_MAX_AVG_DT = 0.058;

    private const CELL_EMPTY = 0;

    private const CELL_WALL = 1;

    private const CELL_CHECKPOINT = 2;

    private const CELL_GOAL = 3;

    private const CELL_JUMP_OBS = 4;

    private const CELL_PIT = 5;

    private const ACCEL = 45.0;

    private const FRICTION = 0.82;

    private const ROT_SPEED = 2.5;

    private const SPRINT_MULT = 1.6;

    private const JUMP_VEL = 9.5;

    private const GRAVITY = 28.0;

    private const RADIUS = 0.19;

    private const COYOTE_T = 0.22;

    private const VOID_LEVEL = -3.2;

    private const BAD_GROUND_GRACE = 0.18;

    private const RESPAWN_GRACE = 0.55;

    private const JUMP_OBS_CLEAR_Z = 0.38;

    private const PLANE_BASE = 0.70;

    private const INITIAL_RESPAWN_GRACE = 0.35;

    public function validate(array $level, array $replay): bool
    {
        return $this->validateDetailed($level, $replay)['ok'];
    }

    /**
     * @param array{past: int[][], future: int[][], start: array{0: int, 1: int}} $level
     * @param array<string, mixed> $replay
     * @return array{ok: bool, code: string, message: string, context: array<string, mixed>}
     */
    public function validateDetailed(array $level, array $replay): array
    {
        $rawInputs = $replay['inputs'] ?? [];
        $claimedTimeMs = (int) ($replay['claimedTimeMs'] ?? 0);

        if ($claimedTimeMs <= 0 || $claimedTimeMs > 600000) {
            return self::replayFail('CLAIMED_TIME_INVALID', 'claimedTimeMs must be between 1 and 600000.', [
                'claimedTimeMs' => $claimedTimeMs,
                'allowedMax' => 600000,
            ]);
        }
        if (!is_array($rawInputs)) {
            return self::replayFail('INPUTS_NOT_ARRAY', 'replay.inputs must be an array.', []);
        }
        if (count($rawInputs) > self::MAX_INPUT_EVENTS) {
            return self::replayFail('INPUTS_TOO_MANY', 'Too many input events.', [
                'count' => count($rawInputs),
                'max' => self::MAX_INPUT_EVENTS,
            ]);
        }

        $toggleKeys = ['F', 'B', 'L', 'R', 'J', 'T', 'SL', 'SR', 'SC'];
        $lastT = -1;
        $events = [];

        foreach ($rawInputs as $idx => $ev) {
            if (!is_array($ev) || count($ev) < 2) {
                return self::replayFail('INPUT_SHAPE', 'Each input must be an array with at least [t_ms, code].', [
                    'index' => $idx,
                    'value' => $ev,
                ]);
            }
            $t = (int) $ev[0];
            $key = (string) $ev[1];
            if ($t < 0 || $t < $lastT) {
                return self::replayFail('TIMESTAMP_ORDER', 'Input timestamps must be non-decreasing.', [
                    'index' => $idx,
                    'previousT' => $lastT,
                    't' => $t,
                    'key' => $key,
                ]);
            }
            if ($t > $claimedTimeMs + self::MAX_TIME_DRIFT_MS) {
                return self::replayFail('TIMESTAMP_TOO_LATE', 'An input is stamped too far after claimedTimeMs.', [
                    'index' => $idx,
                    't' => $t,
                    'claimedTimeMs' => $claimedTimeMs,
                    'maxDriftMs' => self::MAX_TIME_DRIFT_MS,
                    'key' => $key,
                ]);
            }
            if ($key === 'M') {
                if (count($ev) !== 3 || !is_numeric($ev[2])) {
                    return self::replayFail('MOUSE_INPUT_SHAPE', 'Mouse entries must be [t_ms, "M", deltaRad].', [
                        'index' => $idx,
                        'value' => $ev,
                    ]);
                }
                $d = (float) $ev[2];
                if (!is_finite($d) || abs($d) > self::MAX_MOUSE_DELTA_RAD) {
                    return self::replayFail('MOUSE_DELTA_INVALID', 'Mouse delta is not a finite radian value in range.', [
                        'index' => $idx,
                        'delta' => $ev[2],
                        'maxAbsRad' => self::MAX_MOUSE_DELTA_RAD,
                    ]);
                }
                $events[] = [$t, 'M', $d];
                $lastT = $t;

                continue;
            }
            if (count($ev) !== 2 || !in_array($key, $toggleKeys, true)) {
                return self::replayFail('TOGGLE_INPUT_INVALID', 'Unknown or malformed toggle input.', [
                    'index' => $idx,
                    'key' => $key,
                    'elementCount' => count($ev),
                ]);
            }
            $events[] = [$t, $key];
            $lastT = $t;
        }

        $seenMove = false;
        foreach ($events as [, $k]) {
            if (in_array($k, ['F', 'B', 'SL', 'SR'], true)) {
                $seenMove = true;
                break;
            }
        }
        if (!$seenMove) {
            return self::replayFail('NO_MOVEMENT_INPUT', 'Replay must include at least one F, B, SL, or SR toggle.', [
                'parsedEventCount' => count($events),
            ]);
        }

        $past = $level['past'] ?? [];
        if ($past === [] || !isset($past[0][0])) {
            return self::replayFail('LEVEL_EMPTY', 'Level past grid is missing or empty.', []);
        }

        $future = $level['future'] ?? [];
        $start = $level['start'] ?? [1, 1];
        $sx = ($start[0] ?? 1) + 0.5;
        $sy = ($start[1] ?? 1) + 0.5;

        $wallSec = $claimedTimeMs / 1000.0;
        $hasFrameCount = array_key_exists('frameCount', $replay);
        $frameCount = $hasFrameCount ? (int) $replay['frameCount'] : (int) max(1, (int) round($claimedTimeMs * 60 / 1000));
        if ($hasFrameCount && $frameCount <= 0) {
            return self::replayFail('FRAME_COUNT_INVALID', 'frameCount must be a positive integer when provided.', [
                'frameCount' => $replay['frameCount'] ?? null,
            ]);
        }
        if ($frameCount < 1 || $frameCount > self::MAX_PHYSICS_FRAMES) {
            return self::replayFail('FRAME_COUNT_OUT_OF_RANGE', 'frameCount must be between 1 and the configured maximum.', [
                'frameCount' => $frameCount,
                'maxFrames' => self::MAX_PHYSICS_FRAMES,
                'claimedTimeMs' => $claimedTimeMs,
                'frameCountSource' => $hasFrameCount ? 'client' : 'estimated_60fps',
            ]);
        }

        $frameBoundaryMs = null;
        if (!empty($replay['frameBoundaryMs'])) {
            if (!is_array($replay['frameBoundaryMs'])) {
                return self::replayFail('BOUNDARIES_NOT_ARRAY', 'frameBoundaryMs must be an array when provided.', []);
            }
            if (count($replay['frameBoundaryMs']) !== $frameCount) {
                return self::replayFail('BOUNDARY_COUNT_MISMATCH', 'frameBoundaryMs must have exactly frameCount entries.', [
                    'boundaryCount' => count($replay['frameBoundaryMs']),
                    'frameCount' => $frameCount,
                ]);
            }
            $norm = [];
            $prev = -1;
            foreach ($replay['frameBoundaryMs'] as $i => $b) {
                if (!is_numeric($b)) {
                    return self::replayFail('BOUNDARY_NON_NUMERIC', 'Each frameBoundaryMs entry must be numeric.', [
                        'index' => $i,
                    ]);
                }
                $bi = (int) $b;
                if ($bi < $prev) {
                    return self::replayFail('BOUNDARY_ORDER', 'frameBoundaryMs must be non-decreasing.', [
                        'index' => $i,
                        'previous' => $prev,
                        'value' => $bi,
                    ]);
                }
                $norm[] = $bi;
                $prev = $bi;
            }
            $lastB = $norm[$frameCount - 1];
            if (abs($lastB - $claimedTimeMs) > 25) {
                return self::replayFail('BOUNDARY_CLAIMED_MISMATCH', 'Last boundary must match claimedTimeMs (same wall clock as finish).', [
                    'lastBoundary' => $lastB,
                    'claimedTimeMs' => $claimedTimeMs,
                ]);
            }
            $frameBoundaryMs = $norm;
        } else {
            $dt = $wallSec / $frameCount;
            if ($dt <= 0.0 || $dt > self::CLIENT_MAX_AVG_DT) {
                return self::replayFail('DT_INVALID', 'Average step (claimedTimeMs / frameCount) exceeds allowed bound (wall clock can only slightly exceed per-frame physics cap).', [
                    'dt' => round($dt, 6),
                    'frameCount' => $frameCount,
                    'claimedTimeMs' => $claimedTimeMs,
                    'clientMaxAvgDt' => self::CLIENT_MAX_AVG_DT,
                    'clientPerFrameCap' => self::CLIENT_MAX_DT,
                ]);
            }
        }

        return $this->simulate($past, $future, $sx, $sy, $events, $claimedTimeMs, $frameCount, $frameBoundaryMs);
    }

    /**
     * @return array{ok: bool, code: string, message: string, context: array<string, mixed>}
     */
    private static function replayFail(string $code, string $message, array $context): array
    {
        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * @return array{ok: true, code: string, message: string, context: array<string, mixed>}
     */
    private static function replayOk(): array
    {
        return [
            'ok' => true,
            'code' => 'OK',
            'message' => '',
            'context' => [],
        ];
    }

    /**
     * @param list<array{0: int, 1: string}|array{0: int, 1: 'M', 2: float}> $events
     * @param list<int>|null $frameBoundaryMs
     * @return array{ok: bool, code: string, message: string, context: array<string, mixed>}
     */
    private function simulate(
        array $past,
        array $future,
        float $sx,
        float $sy,
        array $events,
        int $claimedTimeMs,
        int $frameCount,
        ?array $frameBoundaryMs
    ): array {
        $px = $sx;
        $py = $sy;
        $invRoot2 = 1.0 / hypot(1.0, 1.0);
        $dirX = $invRoot2;
        $dirY = $invRoot2;
        $planeX = -$dirY * self::PLANE_BASE;
        $planeY = $dirX * self::PLANE_BASE;

        $vx = 0.0;
        $vy = 0.0;
        $jumpZ = 0.0;
        $jumpVZ = 0.0;
        $onGround = true;
        $coyoteT = self::COYOTE_T;
        $jumpConsumed = false;
        $spaceHeld = false;

        $heldF = $heldB = $heldSL = $heldSR = $heldArrowL = $heldArrowR = $heldSC = false;

        $mapPast = $past;
        $mapFuture = $future;
        $current = $past;

        $lastCP = null;
        $badGroundAccum = 0.0;
        $respawnGraceT = self::INITIAL_RESPAWN_GRACE;

        $simSec = 0.0;
        $evIdx = 0;
        $n = count($events);
        $pendingArrowRot = 0.0;

        $prevBoundary = 0;
        for ($step = 0; $step < $frameCount; $step++) {
            if ($frameBoundaryMs !== null) {
                $flushEnd = $frameBoundaryMs[$step];
                $rawSec = ($frameBoundaryMs[$step] - $prevBoundary) / 1000.0;
                if ($rawSec < 0.0) {
                    $rawSec = 0.0;
                }
                $dt = min($rawSec, self::CLIENT_MAX_DT);
                $prevBoundary = $frameBoundaryMs[$step];
            } else {
                $flushEnd = (int) round(($step + 1) * $claimedTimeMs / $frameCount);
                $dt = ($claimedTimeMs / 1000.0) / $frameCount;
            }

            $mouseSum = 0.0;
            while ($evIdx < $n && $events[$evIdx][0] <= $flushEnd + 1.0) {
                $ev = $events[$evIdx];
                $evIdx++;
                $code = $ev[1];
                if ($code === 'M') {
                    $mouseSum += $ev[2];

                    continue;
                }
                switch ($code) {
                    case 'F':
                        $heldF = !$heldF;
                        break;
                    case 'B':
                        $heldB = !$heldB;
                        break;
                    case 'SL':
                        $heldSL = !$heldSL;
                        break;
                    case 'SR':
                        $heldSR = !$heldSR;
                        break;
                    case 'L':
                        $heldArrowL = !$heldArrowL;
                        break;
                    case 'R':
                        $heldArrowR = !$heldArrowR;
                        break;
                    case 'SC':
                        $heldSC = !$heldSC;
                        break;
                    case 'J':
                        $spaceHeld = !$spaceHeld;
                        if (!$spaceHeld) {
                            $jumpConsumed = false;
                        }
                        break;
                    case 'T':
                        $this->tryTimeSwap($current, $mapPast, $mapFuture, $px, $py);
                        break;
                }
            }

            $touchedGoalThisStep = false;
            $this->integrateStep(
                $dt,
                $current,
                $mapPast,
                $mapFuture,
                $px,
                $py,
                $dirX,
                $dirY,
                $planeX,
                $planeY,
                $vx,
                $vy,
                $jumpZ,
                $jumpVZ,
                $onGround,
                $coyoteT,
                $jumpConsumed,
                $spaceHeld,
                $heldF,
                $heldB,
                $heldSL,
                $heldSR,
                $heldArrowL,
                $heldArrowR,
                $heldSC,
                $lastCP,
                $badGroundAccum,
                $respawnGraceT,
                $sx,
                $sy,
                $pendingArrowRot,
                $mouseSum,
                $touchedGoalThisStep
            );

            $simSec += $dt;

            $onGoal = $this->cellAt($current, $px, $py) === self::CELL_GOAL || $touchedGoalThisStep;
            if ($onGoal) {
                $finishMs = $simSec * 1000.0;
                if (abs($finishMs - $claimedTimeMs) <= self::TIME_TOLERANCE_MS) {
                    return self::replayOk();
                }

                return self::replayFail('TIME_MISMATCH', 'Simulated goal time does not match claimedTimeMs within tolerance.', [
                    'claimedTimeMs' => $claimedTimeMs,
                    'simulatedFinishMs' => round($finishMs, 3),
                    'deltaMs' => round($finishMs - $claimedTimeMs, 3),
                    'toleranceMs' => self::TIME_TOLERANCE_MS,
                    'frameIndex' => $step,
                    'frameCount' => $frameCount,
                ]);
            }
        }

        $cell = $this->cellAt($current, $px, $py);

        return self::replayFail('GOAL_NOT_REACHED', 'Simulation finished without the player center or movement path crossing a goal tile.', [
            'px' => round($px, 4),
            'py' => round($py, 4),
            'cellType' => $cell,
            'cellTypeName' => self::cellTypeName($cell),
            'era' => $current === $mapPast ? 'past' : 'future',
            'frameCount' => $frameCount,
            'usedFrameBoundaries' => $frameBoundaryMs !== null,
            'simulatedTimeMs' => round($simSec * 1000.0, 3),
            'claimedTimeMs' => $claimedTimeMs,
            'eventsTotal' => $n,
            'eventsConsumed' => $evIdx,
            'eventsRemaining' => $n - $evIdx,
            'velocity' => [round($vx, 4), round($vy, 4)],
            'onGround' => $onGround,
            'jumpZ' => round($jumpZ, 4),
        ]);
    }

    private static function cellTypeName(int $cell): string
    {
        return match ($cell) {
            self::CELL_EMPTY => 'EMPTY',
            self::CELL_WALL => 'WALL',
            self::CELL_CHECKPOINT => 'CHECKPOINT',
            self::CELL_GOAL => 'GOAL',
            self::CELL_JUMP_OBS => 'JUMP_OBS',
            self::CELL_PIT => 'PIT',
            default => 'UNKNOWN('.$cell.')',
        };
    }

    /**
     * @param array<int, int[]> $current
     */
    private function tryTimeSwap(
        array &$current,
        array $mapPast,
        array $mapFuture,
        float $px,
        float $py
    ): void {
        $next = $current === $mapPast ? $mapFuture : $mapPast;
        if ($this->isWalkable($next, $px, $py)) {
            $current = $next;
        }
    }

    /**
     * @param array<int, int[]> $current
     * @param array<int, int[]>|null $lastCP
     */
    private function integrateStep(
        float $dt,
        array &$current,
        array $mapPast,
        array $mapFuture,
        float &$px,
        float &$py,
        float &$dirX,
        float &$dirY,
        float &$planeX,
        float &$planeY,
        float &$vx,
        float &$vy,
        float &$jumpZ,
        float &$jumpVZ,
        bool &$onGround,
        float &$coyoteT,
        bool &$jumpConsumed,
        bool $spaceHeld,
        bool $heldF,
        bool $heldB,
        bool $heldSL,
        bool $heldSR,
        bool $heldArrowL,
        bool $heldArrowR,
        bool $heldSC,
        ?array &$lastCP,
        float &$badGroundAccum,
        float &$respawnGraceT,
        float $startX,
        float $startY,
        float &$pendingArrowRot,
        float $mouseRotThisStep,
        bool &$touchedGoalThisStep
    ): void {
        $rs = $pendingArrowRot + $mouseRotThisStep;
        $pendingArrowRot = 0.0;
        if (abs($rs) > 1e-12) {
            $odx = $dirX;
            $opx = $planeX;
            $cos = cos($rs);
            $sin = sin($rs);
            $dirX = $odx * $cos + $dirY * $sin;
            $dirY = -$odx * $sin + $dirY * $cos;
            $planeX = $opx * $cos + $planeY * $sin;
            $planeY = -$opx * $sin + $planeY * $cos;
        }

        $dirLen = hypot($dirX, $dirY);
        if ($dirLen > 1e-4) {
            $dirX /= $dirLen;
            $dirY /= $dirLen;
        }
        $planeLen = hypot($planeX, $planeY);
        $planeX = -$dirY * $planeLen;
        $planeY = $dirX * $planeLen;

        $ax = 0.0;
        $ay = 0.0;
        if ($heldF) {
            $ax += $dirX;
            $ay += $dirY;
        }
        if ($heldB) {
            $ax -= $dirX;
            $ay -= $dirY;
        }
        if ($heldSL) {
            $ax += $dirY;
            $ay -= $dirX;
        }
        if ($heldSR) {
            $ax -= $dirY;
            $ay += $dirX;
        }

        $mag = hypot($ax, $ay);
        if ($mag > 0.001) {
            $speedMult = $heldSC ? self::SPRINT_MULT : 1.0;
            $scale = self::ACCEL * $speedMult * $dt / $mag;
            $vx += $ax * $scale;
            $vy += $ay * $scale;
        }

        $vx *= self::FRICTION;
        $vy *= self::FRICTION;
        if (hypot($vx, $vy) < 0.003) {
            $vx = 0.0;
            $vy = 0.0;
        }

        $px0 = $px;
        $py0 = $py;

        $nx = $px + $vx * $dt;
        if (!$this->circleHitsWall($current, $nx, $py0, $jumpZ)) {
            if ($this->segmentTouchesGoalCell($current, $px0, $py0, $nx, $py0)) {
                $touchedGoalThisStep = true;
            }
            $px = $nx;
        } else {
            $vx = 0.0;
        }
        $ny = $py0 + $vy * $dt;
        if (!$this->circleHitsWall($current, $px, $ny, $jumpZ)) {
            if ($this->segmentTouchesGoalCell($current, $px, $py0, $px, $ny)) {
                $touchedGoalThisStep = true;
            }
            $py = $ny;
        } else {
            $vy = 0.0;
        }

        if ($respawnGraceT > 0) {
            $respawnGraceT -= $dt;
            $badGroundAccum = 0.0;
        } elseif ($onGround && !$this->isWalkable($current, $px, $py)) {
            $badGroundAccum += $dt;
            if ($badGroundAccum >= self::BAD_GROUND_GRACE) {
                $this->respawn(
                    $px,
                    $py,
                    $current,
                    $mapPast,
                    $mapFuture,
                    $vx,
                    $vy,
                    $jumpZ,
                    $jumpVZ,
                    $onGround,
                    $jumpConsumed,
                    $lastCP,
                    $badGroundAccum,
                    $respawnGraceT,
                    $startX,
                    $startY
                );
            }
        } else {
            $badGroundAccum = 0.0;
        }

        if ($onGround) {
            $coyoteT = self::COYOTE_T;
        } else {
            $coyoteT -= $dt;
        }

        if ($spaceHeld && $coyoteT > 0 && !$jumpConsumed) {
            $jumpVZ = self::JUMP_VEL;
            $onGround = false;
            $coyoteT = 0.0;
            $jumpConsumed = true;
        }

        $jumpZ += $jumpVZ * $dt;
        $jumpVZ -= self::GRAVITY * $dt;

        if ($jumpZ <= 0) {
            if ($this->isWalkable($current, $px, $py)) {
                $jumpZ = 0.0;
                $jumpVZ = 0.0;
                $onGround = true;
            }
        }

        if ($jumpZ < self::VOID_LEVEL && $respawnGraceT <= 0) {
            $this->respawn(
                $px,
                $py,
                $current,
                $mapPast,
                $mapFuture,
                $vx,
                $vy,
                $jumpZ,
                $jumpVZ,
                $onGround,
                $jumpConsumed,
                $lastCP,
                $badGroundAccum,
                $respawnGraceT,
                $startX,
                $startY
            );
        }

        $c = $this->cellAt($current, $px, $py);
        if ($c === self::CELL_CHECKPOINT) {
            $lastCP = [
                'x' => $px,
                'y' => $py,
                'era' => $current === $mapPast ? 'past' : 'future',
            ];
        }

        $keyRot = self::ROT_SPEED * $dt;
        if ($heldArrowL) {
            $pendingArrowRot += $keyRot;
        }
        if ($heldArrowR) {
            $pendingArrowRot -= $keyRot;
        }
    }

    /**
     * @param array<int, int[]>|null $lastCP
     */
    private function respawn(
        float &$px,
        float &$py,
        array &$current,
        array $mapPast,
        array $mapFuture,
        float &$vx,
        float &$vy,
        float &$jumpZ,
        float &$jumpVZ,
        bool &$onGround,
        bool &$jumpConsumed,
        ?array $lastCP,
        float &$badGroundAccum,
        float &$respawnGraceT,
        float $startX,
        float $startY
    ): void {
        $badGroundAccum = 0.0;
        $respawnGraceT = self::RESPAWN_GRACE;
        if ($lastCP !== null) {
            $px = (float) $lastCP['x'];
            $py = (float) $lastCP['y'];
            if (($lastCP['era'] ?? 'past') === 'past') {
                $current = $mapPast;
            } else {
                $current = $mapFuture;
            }
        } else {
            $px = $startX;
            $py = $startY;
            $current = $mapPast;
        }
        $vx = 0.0;
        $vy = 0.0;
        $jumpZ = 0.0;
        $jumpVZ = 0.0;
        $onGround = true;
        $jumpConsumed = false;
    }

    /**
     * @param array<int, int[]> $map
     */
    private function cellAt(array $map, float $x, float $y): int
    {
        $ix = (int) $x;
        $iy = (int) $y;
        if ($iy < 0 || $iy >= count($map) || $ix < 0 || $ix >= count($map[0])) {
            return self::CELL_WALL;
        }

        return $map[$iy][$ix] ?? self::CELL_WALL;
    }

    /**
     * True if the segment (axis slide leg) passes through any GOAL cell.
     * Matches fast movement: the client wins on the frame the center hits goal, but server/client
     * drift can end the step beside the tile while the path still crossed it.
     *
     * @param array<int, int[]> $map
     */
    private function segmentTouchesGoalCell(array $map, float $x0, float $y0, float $x1, float $y1): bool
    {
        $dx = $x1 - $x0;
        $dy = $y1 - $y0;
        $dist = hypot($dx, $dy);
        if ($dist < 1e-8) {
            return $this->cellAt($map, $x0, $y0) === self::CELL_GOAL;
        }
        $steps = (int) max(2, min(64, 2 + (int) ceil($dist / 0.12)));

        for ($i = 0; $i <= $steps; $i++) {
            $t = $i / $steps;
            $x = $x0 + $dx * $t;
            $y = $y0 + $dy * $t;
            if ($this->cellAt($map, $x, $y) === self::CELL_GOAL) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, int[]> $map
     */
    private function isWalkable(array $map, float $x, float $y): bool
    {
        $c = $this->cellAt($map, $x, $y);

        return $c === self::CELL_EMPTY || $c === self::CELL_CHECKPOINT || $c === self::CELL_GOAL;
    }

    /**
     * @param array<int, int[]> $map
     */
    private function isBlockingJumpObs(int $c, float $jumpZ): bool
    {
        return $c === self::CELL_JUMP_OBS && $jumpZ < self::JUMP_OBS_CLEAR_Z;
    }

    /**
     * @param array<int, int[]> $map
     */
    private function solidAt(array $map, float $x, float $y, float $jumpZ): bool
    {
        $c = $this->cellAt($map, $x, $y);
        if ($c === self::CELL_PIT) {
            return false;
        }

        return $c === self::CELL_WALL || $this->isBlockingJumpObs($c, $jumpZ);
    }

    /**
     * @param array<int, int[]> $map
     */
    private function circleHitsWall(array $map, float $x, float $y, float $jumpZ): bool
    {
        $r = self::RADIUS;
        $x0 = (int) floor($x - $r);
        $x1 = (int) ceil($x + $r);
        $y0 = (int) floor($y - $r);
        $y1 = (int) ceil($y + $r);
        for ($cy = $y0; $cy <= $y1; $cy++) {
            for ($cx = $x0; $cx <= $x1; $cx++) {
                if (!$this->solidAt($map, $cx + 0.5, $cy + 0.5, $jumpZ)) {
                    continue;
                }
                $nearX = max($cx, min($x, $cx + 1));
                $nearY = max($cy, min($y, $cy + 1));
                $dx = $x - $nearX;
                $dy = $y - $nearY;
                if ($dx * $dx + $dy * $dy < $r * $r) {
                    return true;
                }
            }
        }

        return false;
    }
}
