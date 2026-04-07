<?php

namespace App\Services;


 // Beatability rule: every consecutive path node is connected by a carved *cardinal* corridor
 // (Manhattan tube) in both timelines, with pit-only mids (no jump-over low walls). Diagonal
 //“teleport” steps are forbidden. A cardinal bridge guarantees the goal is reachable from the walk.
 
final class ProceduralMapGenerator
{
    private const WIDTH = 32;
    private const HEIGHT = 32;

    private const EMPTY = 0;
    private const WALL = 1;
    private const CHECKPOINT = 2;
    private const GOAL = 3;
    private const PIT = 5;

    private const MIN_PATH_NODES = 22;
    private const MAX_PATH_STEPS = 240;
    // Deterministic retries if solver says unwinnable; attempt 0 uses raw seed (stable daily). 
    private const BEATABILITY_ATTEMPTS = 72;

    private int $seed;

    public function __construct(int $seed)
    {
        $this->seed = $seed;
    }

    private function rand(): float
    {
        return mt_rand() / (mt_getrandmax() + 1.0);
    }

    public function generate(): array
    {
        $gx = self::WIDTH - 3;
        $gy = self::HEIGHT - 3;

        for ($attempt = 0; $attempt < self::BEATABILITY_ATTEMPTS; $attempt++) {
            if ($attempt === 0) {
                mt_srand($this->seed);
            } else {
                mt_srand((int) ($this->seed ^ ($attempt * 0x9E3779B5)));
            }

            [$gx, $gy] = $this->pickRandomGoalCell(2, 2);
            $goal = [$gx, $gy];

            $path = $this->buildGoldenPath($gx, $gy);
            if (count($path) < self::MIN_PATH_NODES) {
                for ($k = 1; $k <= 40 && count($path) < self::MIN_PATH_NODES; $k++) {
                    mt_srand((int) ($this->seed ^ ($attempt * 0x9E3779B5) ^ ($k * 1103515245)));
                    $path = $this->buildGoldenPath($gx, $gy);
                }
            }

            if (count($path) < 8) {
                continue;
            }

            $last = $path[count($path) - 1];
            if ($last['x'] !== $gx || $last['y'] !== $gy) {
                continue;
            }

            $past = $this->emptyGrid(self::WALL);
            $future = $this->emptyGrid(self::WALL);
            $carved = $this->applyPathToGrids($past, $future, $path, $goal);
            $checkpoints = $this->placeCheckpoints($past, $future, $path, $gx, $gy);
            $this->addScenery($past, $future, $carved);

            $sx = $path[0]['x'];
            $sy = $path[0]['y'];
            if ($this->isBeatable($past, $future, $sx, $sy, $gx, $gy)) {
                return [
                    'past' => $past,
                    'future' => $future,
                    'start' => [$sx, $sy],
                    'checkpoints' => $checkpoints,
                    'goldenPath' => $this->serializeGoldenPath($path),
                ];
            }
        }

        return $this->generateFallbackCorridor($gx, $gy);
    }

    private function cellWalkable(array $grid, int $x, int $y): bool
    {
        $c = $grid[$y][$x] ?? self::WALL;

        return $c === self::EMPTY || $c === self::CHECKPOINT || $c === self::GOAL;
    }

    private function cellIsPit(array $grid, int $x, int $y): bool
    {
        return ($grid[$y][$x] ?? self::WALL) === self::PIT;
    }

    // Same as frontend engine.js RADIUS — used so BFS matches circle collision, not 4-connectivity. 
    private const PLAYER_COLLISION_R = 0.19;

    
     //Tiles that block the player circle on the ground (matches engine solidAt for floor navigation).
     
    private function cellBlocksPlayerCircle(array $map, int $cx, int $cy): bool
    {
        $c = $map[$cy][$cx] ?? self::WALL;

        return $c === self::WALL || $c === 4;
    }

    private function circleHitsSolid(array $map, float $px, float $py): bool
    {
        $r = self::PLAYER_COLLISION_R;
        $x0 = (int) floor($px - $r);
        $x1 = (int) ceil($px + $r);
        $y0 = (int) floor($py - $r);
        $y1 = (int) ceil($py + $r);
        for ($cy = $y0; $cy <= $y1; $cy++) {
            for ($cx = $x0; $cx <= $x1; $cx++) {
                if (!$this->cellBlocksPlayerCircle($map, $cx, $cy)) {
                    continue;
                }
                $nearX = max($cx, min($px, $cx + 1));
                $nearY = max($cy, min($py, $cy + 1));
                $dx = $px - $nearX;
                $dy = $py - $nearY;
                if ($dx * $dx + $dy * $dy < $r * $r) {
                    return true;
                }
            }
        }

        return false;
    }

    // Walls only — pits / air leap do not collide (matches crossing a chasm mid-jump). 
    private function circleHitsWallOnly(array $map, float $px, float $py): bool
    {
        $r = self::PLAYER_COLLISION_R;
        $x0 = (int) floor($px - $r);
        $x1 = (int) ceil($px + $r);
        $y0 = (int) floor($py - $r);
        $y1 = (int) ceil($py + $r);
        for ($cy = $y0; $cy <= $y1; $cy++) {
            for ($cx = $x0; $cx <= $x1; $cx++) {
                $c = $map[$cy][$cx] ?? self::WALL;
                if ($c !== self::WALL) {
                    continue;
                }
                $nearX = max($cx, min($px, $cx + 1));
                $nearY = max($cy, min($py, $cy + 1));
                $dx = $px - $nearX;
                $dy = $py - $nearY;
                if ($dx * $dx + $dy * $dy < $r * $r) {
                    return true;
                }
            }
        }

        return false;
    }

    
     //Orthogonal step is valid only if the player circle can slide along the segment between cell
     // centres without intersecting wall AABBs (stops false links through diagonal wall corners).
     
    private function canTraverseOrthStep(array $map, int $x, int $y, int $nx, int $ny): bool
    {
        if (!$this->cellWalkable($map, $x, $y) || !$this->cellWalkable($map, $nx, $ny)) {
            return false;
        }
        $x0 = $x + 0.5;
        $y0 = $y + 0.5;
        $x1 = $nx + 0.5;
        $y1 = $ny + 0.5;
        for ($s = 1; $s <= 7; $s++) {
            $t = $s / 8.0;
            $px = $x0 + $t * ($x1 - $x0);
            $py = $y0 + $t * ($y1 - $y0);
            if ($this->circleHitsSolid($map, $px, $py)) {
                return false;
            }
        }

        return true;
    }

    private function canLeapPitStep(array $map, int $x, int $y, int $nx2, int $ny2): bool
    {
        if (!$this->cellWalkable($map, $x, $y) || !$this->cellWalkable($map, $nx2, $ny2)) {
            return false;
        }
        $x0 = $x + 0.5;
        $y0 = $y + 0.5;
        $x1 = $nx2 + 0.5;
        $y1 = $ny2 + 0.5;
        for ($s = 1; $s <= 15; $s++) {
            $t = $s / 16.0;
            $px = $x0 + $t * ($x1 - $x0);
            $py = $y0 + $t * ($y1 - $y0);
            if ($this->circleHitsWallOnly($map, $px, $py)) {
                return false;
            }
        }

        return true;
    }

    
     // BFS over (x,y,era): orth moves, timeline swap when both eras walkable, cardinal leaps over a pit.
     
    private function isBeatable(array $past, array $future, int $sx, int $sy, int $gx, int $gy): bool
    {
        if (!$this->cellWalkable($past, $sx, $sy)) {
            return false;
        }
        if (!$this->cellWalkable($past, $gx, $gy) && !$this->cellWalkable($future, $gx, $gy)) {
            return false;
        }

        $k = static fn (int $x, int $y, string $e): string => $x . ',' . $y . ',' . $e;
        $seen = [];
        $queue = [];
        $qi = 0;
        $seen[$k($sx, $sy, 'past')] = true;
        $queue[] = [$sx, $sy, 'past'];

        $dirs = [[1, 0], [-1, 0], [0, 1], [0, -1]];

        while ($qi < count($queue)) {
            [$x, $y, $era] = $queue[$qi++];
            if ($x === $gx && $y === $gy) {
                return true;
            }

            $map = $era === 'past' ? $past : $future;
            $otherEra = $era === 'past' ? 'future' : 'past';

            if ($this->cellWalkable($past, $x, $y) && $this->cellWalkable($future, $x, $y)) {
                $kk = $k($x, $y, $otherEra);
                if (!isset($seen[$kk])) {
                    $seen[$kk] = true;
                    $queue[] = [$x, $y, $otherEra];
                }
            }

            foreach ($dirs as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if ($this->inBounds($nx, $ny) && $this->canTraverseOrthStep($map, $x, $y, $nx, $ny)) {
                    $kk = $k($nx, $ny, $era);
                    if (!isset($seen[$kk])) {
                        $seen[$kk] = true;
                        $queue[] = [$nx, $ny, $era];
                    }
                }

                $nx2 = $x + 2 * $dx;
                $ny2 = $y + 2 * $dy;
                $mx = $x + $dx;
                $my = $y + $dy;
                if ($this->inBounds($nx2, $ny2)
                    && $this->inBounds($mx, $my)
                    && $this->cellIsPit($map, $mx, $my)
                    && $this->canLeapPitStep($map, $x, $y, $nx2, $ny2)) {
                    $kk = $k($nx2, $ny2, $era);
                    if (!isset($seen[$kk])) {
                        $seen[$kk] = true;
                        $queue[] = [$nx2, $ny2, $era];
                    }
                }
            }
        }

        return false;
    }

    
     //Boring but always solvable: identical past/future L-corridor, no pits or gates.
     //@return array{past: array, future: array, start: array{0:int,1:int}, checkpoints: list<array{0:int,1:int}>, goldenPath: list}
     
    private function generateFallbackCorridor(int $gx, int $gy): array
    {
        $past = $this->emptyGrid(self::WALL);
        $future = $this->emptyGrid(self::WALL);
        $sx = 2;
        $sy = 2;

        $past[$sy][$sx] = self::EMPTY;
        $future[$sy][$sx] = self::EMPTY;

        $path = [];
        $path[] = [
            'x' => $sx,
            'y' => $sy,
            'z' => 0,
            'era' => 'past',
            'action' => 'spawn_fallback',
            'exclusive' => null,
            'mid' => null,
        ];

        $x = $sx;
        $y = $sy;
        while ($x !== $gx) {
            $x += $gx <=> $x;
            $past[$y][$x] = self::EMPTY;
            $future[$y][$x] = self::EMPTY;
            $path[] = [
                'x' => $x,
                'y' => $y,
                'z' => 0,
                'era' => 'past',
                'action' => 'walk_fallback',
                'exclusive' => null,
                'mid' => null,
            ];
        }
        while ($y !== $gy) {
            $y += $gy <=> $y;
            $past[$y][$x] = self::EMPTY;
            $future[$y][$x] = self::EMPTY;
            $path[] = [
                'x' => $x,
                'y' => $y,
                'z' => 0,
                'era' => 'past',
                'action' => 'walk_fallback',
                'exclusive' => null,
                'mid' => null,
            ];
        }

        $past[$gy][$gx] = self::GOAL;
        $future[$gy][$gx] = self::GOAL;

        $checkpoints = $this->placeCheckpoints($past, $future, $path, $gx, $gy);

        return [
            'past' => $past,
            'future' => $future,
            'start' => [$sx, $sy],
            'checkpoints' => $checkpoints,
            'goldenPath' => $this->serializeGoldenPath($path),
        ];
    }

    private function emptyGrid(int $fill = 0): array
    {
        return array_fill(0, self::HEIGHT, array_fill(0, self::WIDTH, $fill));
    }

    private function heuristic(int $x, int $y, int $gx, int $gy): int
    {
        return abs($gx - $x) + abs($gy - $y);
    }

    private function inBounds(int $x, int $y): bool
    {
        return $x >= 1 && $x < self::WIDTH - 1 && $y >= 1 && $y < self::HEIGHT - 1;
    }

    /**
     * Random goal for this RNG stream (daily seed → reproducible). Keeps a minimum Manhattan distance
     * from spawn so runs are not trivially short.
     *
     * @return array{0:int,1:int}
     */
    private function pickRandomGoalCell(int $spawnX, int $spawnY): array
    {
        $min = 3;
        $maxX = self::WIDTH - 4;
        $maxY = self::HEIGHT - 4;
        for ($t = 0; $t < 160; $t++) {
            $gx = mt_rand($min, $maxX);
            $gy = mt_rand($min, $maxY);
            if ($gx === $spawnX && $gy === $spawnY) {
                continue;
            }
            if (abs($gx - $spawnX) + abs($gy - $spawnY) < 12) {
                continue;
            }

            return [$gx, $gy];
        }

        return [self::WIDTH - 3, self::HEIGHT - 3];
    }

    /**
     * Ordered cells from (x0,y0) to (x1,y1), exclusive of start, inclusive of end (Manhattan).
     *
     * @return list<array{0:int,1:int}>
     */
    private function manhattanWalk(int $x0, int $y0, int $x1, int $y1): array
    {
        $cells = [];
        $x = $x0;
        $y = $y0;
        $guard = 0;
        while (($x !== $x1 || $y !== $y1) && $guard++ < 128) {
            if ($x < $x1) {
                ++$x;
            } elseif ($x > $x1) {
                --$x;
            } elseif ($y < $y1) {
                ++$y;
            } elseif ($y > $y1) {
                --$y;
            } else {
                break;
            }
            $cells[] = [$x, $y];
        }

        return $cells;
    }

    /**
     * @param list<array<string,mixed>> $path
     * @return list<array{x:int,y:int,era:string,action:string}>
     */
    private function serializeGoldenPath(array $path): array
    {
        $out = [];
        foreach ($path as $n) {
            $out[] = [
                'x' => $n['x'],
                'y' => $n['y'],
                'era' => $n['era'],
                'action' => $n['action'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function buildGoldenPath(int $gx, int $gy): array
    {
        $x = 2;
        $y = 2;
        $z = 0;
        $era = 'past';
        $visited = [];
        $key = static fn (int $a, int $b): string => $a . ',' . $b;

        $path = [];
        $path[] = [
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'era' => $era,
            'action' => 'spawn',
            'exclusive' => null,
            'mid' => null,
        ];
        $visited[$key($x, $y)] = true;

        $steps = 0;
        while (($x !== $gx || $y !== $gy) && $steps++ < self::MAX_PATH_STEPS) {
            $dxg = $gx <=> $x;
            $dyg = $gy <=> $y;

            $moveRoll = $this->rand();
            $candidates = [];

            if ($moveRoll < 0.52) {
                $candidates = $this->candidatesNormal($x, $y, $z, $era, $visited, $gx, $gy, $dxg, $dyg);
            } elseif ($moveRoll < 0.76) {
                $candidates = $this->candidatesDrop($x, $y, $z, $era, $visited, $gx, $gy);
            } else {
                $candidates = $this->candidatesTimelineSwitch($x, $y, $z, $era, $visited, $gx, $gy);
            }

            if ($candidates === []) {
                $candidates = $this->candidatesNormal($x, $y, $z, $era, $visited, $gx, $gy, $dxg, $dyg);
            }
            if ($candidates === []) {
                $candidates = $this->forcedGreedyStep($x, $y, $z, $era, $visited, $gx, $gy);
            }
            if ($candidates === []) {
                break;
            }

            usort($candidates, static fn (array $a, array $b): int => $a['_score'] <=> $b['_score']);
            $pickBand = min(4, count($candidates));
            $choice = $candidates[(int) ($this->rand() * $pickBand)];
            unset($choice['_score']);

            $x = $choice['x'];
            $y = $choice['y'];
            $z = $choice['z'];
            $era = $choice['era'];
            $visited[$key($x, $y)] = true;

            $path[] = $choice;
        }

        if ($x !== $gx || $y !== $gy) {
            $this->stitchToGoal($path, $visited, $gx, $gy, $key);
        }

        $exclusiveAt = $this->exclusiveCellsFromPath($path);
        $this->bridgeToGoal($path, $gx, $gy, $exclusiveAt);

        return $path;
    }

    /**
     * @param list<array<string,mixed>> $path
     * @return array<string,'past'|'future'>
     */
    private function exclusiveCellsFromPath(array $path): array
    {
        $m = [];
        foreach ($path as $n) {
            $ex = $n['exclusive'] ?? null;
            if ($ex === 'past' || $ex === 'future') {
                $m[$n['x'] . ',' . $n['y']] = $ex;
            }
        }

        return $m;
    }

    /**
     * Cardinal steps until the goal cell. Inserts same-cell era flips when the next tile is only
     * walkable in the other timeline (so carving never overwrites exclusive landings).
     *
     * @param list<array<string,mixed>> $path
     * @param array<string,'past'|'future'> $exclusiveAt
     */
    private function bridgeToGoal(array &$path, int $gx, int $gy, array $exclusiveAt): void
    {
        $guard = 0;
        while ($guard++ < 768) {
            $last = $path[count($path) - 1];
            if ($last['x'] === $gx && $last['y'] === $gy) {
                return;
            }
            $lx = $last['x'];
            $ly = $last['y'];
            $era = $last['era'];

            $nx = $lx;
            $ny = $ly;
            if ($lx !== $gx) {
                $nx += $gx <=> $lx;
            } else {
                $ny += $gy <=> $ly;
            }

            if (!$this->inBounds($nx, $ny)) {
                if ($lx === $gx && $this->inBounds($lx, $ly + ($gy <=> $ly))) {
                    $nx = $lx;
                    $ny = $ly + ($gy <=> $ly);
                } elseif ($ly === $gy && $this->inBounds($lx + ($gx <=> $lx), $ly)) {
                    $nx = $lx + ($gx <=> $lx);
                    $ny = $ly;
                } else {
                    return;
                }
            }

            $ek = $nx . ',' . $ny;
            if (isset($exclusiveAt[$ek]) && $exclusiveAt[$ek] !== $era) {
                $path[] = [
                    'x' => $lx,
                    'y' => $ly,
                    'z' => $last['z'],
                    'era' => $exclusiveAt[$ek],
                    'action' => 'bridge_swap',
                    'exclusive' => null,
                    'mid' => null,
                ];
                continue;
            }

            $path[] = [
                'x' => $nx,
                'y' => $ny,
                'z' => $last['z'],
                'era' => $era,
                'action' => 'bridge',
                'exclusive' => null,
                'mid' => null,
            ];
        }
    }

    /**
     * @param array<string,true> $visited
     */
    private function stitchToGoal(array &$path, array &$visited, int $gx, int $gy, callable $key): void
    {
        $last = $path[count($path) - 1];
        $x = $last['x'];
        $y = $last['y'];
        $z = $last['z'];
        $era = $last['era'];

        $guard = 0;
        while (($x !== $gx || $y !== $gy) && $guard++ < 220) {
            $cands = $this->forcedGreedyStep($x, $y, $z, $era, $visited, $gx, $gy);
            if ($cands === []) {
                $nx = $x;
                $ny = $y;
                if ($nx !== $gx) {
                    $nx += $gx <=> $nx;
                } elseif ($ny !== $gy) {
                    $ny += $gy <=> $ny;
                }
                $nx = max(1, min(self::WIDTH - 2, $nx));
                $ny = max(1, min(self::HEIGHT - 2, $ny));
                if (isset($visited[$key($nx, $ny)])) {
                    break;
                }
                $visited[$key($nx, $ny)] = true;
                $path[] = [
                    'x' => $nx,
                    'y' => $ny,
                    'z' => $z,
                    'era' => $era,
                    'action' => 'stitch',
                    'exclusive' => null,
                    'mid' => null,
                ];
                $x = $nx;
                $y = $ny;
                continue;
            }
            $step = $cands[0];
            unset($step['_score']);
            $visited[$key($step['x'], $step['y'])] = true;
            $path[] = $step;
            $x = $step['x'];
            $y = $step['y'];
            $z = $step['z'];
            $era = $step['era'];
        }
    }

    /**
     * @param array<string,true> $visited
     * @return list<array<string,mixed>>
     */
    private function candidatesNormal(
        int $x,
        int $y,
        int $z,
        string $era,
        array $visited,
        int $gx,
        int $gy,
        int $dxg,
        int $dyg
    ): array {
        $out = [];
        $preferPit = $this->rand() < 0.38;

        if ($preferPit) {
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $mx = $x + $dx;
                $my = $y + $dy;
                $nx = $x + 2 * $dx;
                $ny = $y + 2 * $dy;
                if (!$this->inBounds($nx, $ny) || !$this->inBounds($mx, $my)) {
                    continue;
                }
                $kN = $nx . ',' . $ny;
                if (isset($visited[$kN])) {
                    continue;
                }
                $cells = $this->manhattanWalk($x, $y, $nx, $ny);
                if (count($cells) !== 2 || $cells[0][0] !== $mx || $cells[0][1] !== $my) {
                    continue;
                }
                $out[] = [
                    'x' => $nx,
                    'y' => $ny,
                    'z' => $z,
                    'era' => $era,
                    'action' => 'leap_pit',
                    'exclusive' => null,
                    'mid' => [$mx, $my, 'pit'],
                    '_score' => $this->heuristic($nx, $ny, $gx, $gy),
                ];
            }
        }

        foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dy]) {
            $nx = $x + $dx;
            $ny = $y + $dy;
            if (!$this->inBounds($nx, $ny)) {
                continue;
            }
            if (isset($visited[$nx . ',' . $ny])) {
                continue;
            }
            $out[] = [
                'x' => $nx,
                'y' => $ny,
                'z' => $z,
                'era' => $era,
                'action' => 'walk',
                'exclusive' => null,
                'mid' => null,
                '_score' => $this->heuristic($nx, $ny, $gx, $gy),
            ];
        }

        return $out;
    }

    /**
     * @param array<string,true> $visited
     * @return list<array<string,mixed>>
     */
    private function candidatesDrop(int $x, int $y, int $z, string $era, array $visited, int $gx, int $gy): array
    {
        $out = [];
        foreach ([[0, 1]] as [$dx, $dy]) {
            $nx = $x + $dx;
            $ny = $y + $dy;
            if (!$this->inBounds($nx, $ny)) {
                continue;
            }
            if (isset($visited[$nx . ',' . $ny])) {
                continue;
            }
            $out[] = [
                'x' => $nx,
                'y' => $ny,
                'z' => max(0, $z - 1),
                'era' => $era,
                'action' => 'drop',
                'exclusive' => null,
                'mid' => null,
                '_score' => $this->heuristic($nx, $ny, $gx, $gy),
            ];
        }

        return $out;
    }

    /**
     * @param array<string,true> $visited
     * @return list<array<string,mixed>>
     */
    private function candidatesTimelineSwitch(int $x, int $y, int $z, string $era, array $visited, int $gx, int $gy): array
    {
        $out = [];
        $nextEra = $era === 'past' ? 'future' : 'past';
        foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dy]) {
            $nx = $x + $dx;
            $ny = $y + $dy;
            if (!$this->inBounds($nx, $ny)) {
                continue;
            }
            if (isset($visited[$nx . ',' . $ny])) {
                continue;
            }
            $out[] = [
                'x' => $nx,
                'y' => $ny,
                'z' => $z,
                'era' => $nextEra,
                'action' => 'timeline_switch',
                'exclusive' => $nextEra,
                'mid' => null,
                '_score' => $this->heuristic($nx, $ny, $gx, $gy),
            ];
        }

        return $out;
    }

    /**
     * @param array<string,true> $visited
     * @return list<array<string,mixed>>
     */
    private function forcedGreedyStep(int $x, int $y, int $z, string $era, array $visited, int $gx, int $gy): array
    {
        $best = [];
        $bestScore = PHP_INT_MAX;
        foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dy]) {
            $nx = $x + $dx;
            $ny = $y + $dy;
            if (!$this->inBounds($nx, $ny)) {
                continue;
            }
            if (isset($visited[$nx . ',' . $ny])) {
                continue;
            }
            $h = $this->heuristic($nx, $ny, $gx, $gy);
            if ($h < $bestScore) {
                $bestScore = $h;
                $best = [[
                    'x' => $nx,
                    'y' => $ny,
                    'z' => $z,
                    'era' => $era,
                    'action' => 'walk_forced',
                    'exclusive' => null,
                    'mid' => null,
                    '_score' => $h,
                ]];
            }
        }

        return $best;
    }

    private function setDualWalk(array &$past, array &$future, int $x, int $y): void
    {
        $past[$y][$x] = self::EMPTY;
        $future[$y][$x] = self::EMPTY;
    }

    /**
     * @param array<string,true> $carved
     * @param array<string,mixed> $prev
     * @param array<string,mixed> $node
     */
    private function carveEdgeBetween(array &$past, array &$future, array &$carved, array $prev, array $node): void
    {
        $x0 = $prev['x'];
        $y0 = $prev['y'];
        $x1 = $node['x'];
        $y1 = $node['y'];
        if ($x0 === $x1 && $y0 === $y1) {
            return;
        }

        $mid = $node['mid'] ?? null;
        $mx = $my = null;
        $mtype = null;
        if (is_array($mid) && count($mid) >= 3) {
            [$mx, $my, $mtype] = $mid;
        }

        $cells = $this->manhattanWalk($x0, $y0, $x1, $y1);
        if ($cells === []) {
            return;
        }

        $midOk = false;
        if ($mx !== null && $my !== null) {
            foreach ($cells as [$cx, $cy]) {
                if ($cx === $mx && $cy === $my) {
                    $midOk = true;
                    break;
                }
            }
        }
        if ($mid !== null && !$midOk) {
            $mx = $my = $mtype = null;
        }

        foreach ($cells as [$cx, $cy]) {
            $carved[$cx . ',' . $cy] = true;
            if ($mx !== null && $cx === $mx && $cy === $my) {
                $past[$cy][$cx] = self::PIT;
                $future[$cy][$cx] = self::PIT;
            } else {
                $this->setDualWalk($past, $future, $cx, $cy);
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $path
     * @return array<string,true>
     */
    private function applyPathToGrids(array &$past, array &$future, array $path, array $goal): array
    {
        [$gx, $gy] = $goal;
        $carved = [];

        $first = $path[0];
        $this->carveStand($past, $future, $first);
        $carved[$first['x'] . ',' . $first['y']] = true;

        for ($i = 1, $n = count($path); $i < $n; $i++) {
            $this->carveEdgeBetween($past, $future, $carved, $path[$i - 1], $path[$i]);
            $this->carveStand($past, $future, $path[$i]);
            $carved[$path[$i]['x'] . ',' . $path[$i]['y']] = true;
        }

        $past[$gy][$gx] = self::GOAL;
        $future[$gy][$gx] = self::GOAL;

        return $carved;
    }

    /**
     * @param array<string,mixed> $node
     */
    private function carveStand(array &$past, array &$future, array $node): void
    {
        $x = $node['x'];
        $y = $node['y'];
        $ex = $node['exclusive'] ?? null;

        if ($ex === 'future') {
            $past[$y][$x] = self::WALL;
            $future[$y][$x] = self::EMPTY;
        } elseif ($ex === 'past') {
            $past[$y][$x] = self::EMPTY;
            $future[$y][$x] = self::WALL;
        } else {
            $past[$y][$x] = self::EMPTY;
            $future[$y][$x] = self::EMPTY;
        }
    }

    /**
     * @param list<array<string,mixed>> $path
     * @return list<array{0:int,1:int}>
     */
    private function placeCheckpoints(array &$past, array &$future, array $path, int $gx, int $gy): array
    {
        $cps = [];
        $n = count($path);
        if ($n < 4) {
            return $cps;
        }
        $idxs = [
            (int) max(1, floor($n * 0.28)),
            (int) max(2, floor($n * 0.52)),
            (int) max(3, floor($n * 0.72)),
        ];
        foreach ($idxs as $idx) {
            if (!isset($path[$idx])) {
                continue;
            }
            $node = $path[$idx];
            if (($node['exclusive'] ?? null) !== null) {
                continue;
            }
            $x = $node['x'];
            $y = $node['y'];
            if ($x === $gx && $y === $gy) {
                continue;
            }
            $past[$y][$x] = self::CHECKPOINT;
            $future[$y][$x] = self::CHECKPOINT;
            $cps[] = [$x, $y];
        }

        return $cps;
    }

    /**
     * @param array<string,true> $carved
     */
    private function addScenery(array &$past, array &$future, array $carved): void
    {
        $protected = $carved;

        for ($y = 1; $y < self::HEIGHT - 1; $y++) {
            for ($x = 1; $x < self::WIDTH - 1; $x++) {
                if (isset($protected[$x . ',' . $y])) {
                    continue;
                }
                if ($past[$y][$x] !== self::WALL || $future[$y][$x] !== self::WALL) {
                    continue;
                }
                $r = $this->rand();
                if ($r < 0.028) {
                    $past[$y][$x] = self::EMPTY;
                    $future[$y][$x] = self::WALL;
                } elseif ($r < 0.056) {
                    $future[$y][$x] = self::EMPTY;
                    $past[$y][$x] = self::WALL;
                }
            }
        }
    }

    public static function dateSeed(\DateTimeInterface $date): int
    {
        return abs((int) crc32($date->format('Y-m-d')));
    }
}
