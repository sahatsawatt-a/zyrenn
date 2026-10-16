<?php

namespace App\Support\Board;

/**
 * SVG path data -- the `d` of a <path> -- read into moves, straight lines,
 * cubic and quadratic curves and closes, every point absolute: arcs become
 * cubic curves and the shorthands (H, V, S, T) their full forms. The canvas
 * reads paths the same way (resources/js/features/boards/pen/pathData.ts).
 *
 * Every segment carries all its numbers, 0 where its kind has none; a
 * close carries the point it goes back to.
 *
 * @phpstan-type Segment array{type: string, x: float, y: float, x1: float, y1: float, x2: float, y2: float}
 */
final class PathData
{
    /** How many numbers each command takes. */
    private const PARAMS = ['M' => 2, 'L' => 2, 'H' => 1, 'V' => 1, 'C' => 6, 'S' => 4, 'Q' => 4, 'T' => 2, 'A' => 7, 'Z' => 0];

    /**
     * Reads path data. Anything it cannot read stops it there: what came
     * before is kept, as a browser does.
     *
     * @return list<Segment>
     */
    public static function parse(string $d): array
    {
        preg_match_all('/([MmLlHhVvCcSsQqTtAaZz])|([-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?)/', $d, $found);
        $tokens = array_map(fn (string $letter, string $number) => $letter !== '' ? $letter : (float) $number, $found[1], $found[2]);
        $segments = [];
        $at = 0;
        $command = '';
        [$x, $y, $startX, $startY] = [0.0, 0.0, 0.0, 0.0];
        $control = null;
        $quadratic = null;

        while ($at < count($tokens)) {
            if (is_string($tokens[$at])) {
                $command = $tokens[$at];
                $at++;

                if (strtoupper($command) === 'Z') {
                    $segments[] = self::segment('Z', $startX, $startY);
                    [$x, $y] = [$startX, $startY];
                    $control = $quadratic = null;

                    continue;
                }
            } elseif ($command === '') {
                break;
            }

            // Only the letters of a path are read as commands, so each has its count
            $upper = strtoupper($command);
            $count = self::PARAMS[$upper];
            $n = array_slice($tokens, $at, $count);

            if (count($n) < $count || array_filter($n, 'is_string') !== []) {
                break;
            }

            $at += $count;
            /** @var list<float> $n */
            $relative = $command !== $upper;
            [$ox, $oy] = $relative ? [$x, $y] : [0.0, 0.0];

            switch ($upper) {
                case 'M':
                    [$x, $y] = [$n[0] + $ox, $n[1] + $oy];
                    [$startX, $startY] = [$x, $y];
                    $segments[] = self::segment('M', $x, $y);
                    // Pairs after the first are lines
                    $command = $relative ? 'l' : 'L';
                    $control = $quadratic = null;
                    break;
                case 'L':
                case 'H':
                case 'V':
                    $x = $upper === 'V' ? $x : $n[0] + $ox;
                    $y = match ($upper) {
                        'H' => $y,
                        'V' => $n[0] + $oy,
                        default => $n[1] + $oy,
                    };
                    $segments[] = self::segment('L', $x, $y);
                    $control = $quadratic = null;
                    break;
                case 'C':
                case 'S':
                    [$x1, $y1] = $upper === 'C'
                        ? [$n[0] + $ox, $n[1] + $oy]
                        : ($control !== null ? [2 * $x - $control[0], 2 * $y - $control[1]] : [$x, $y]);
                    $rest = $upper === 'C' ? array_slice($n, 2) : $n;
                    [$x2, $y2] = [$rest[0] + $ox, $rest[1] + $oy];
                    [$x, $y] = [$rest[2] + $ox, $rest[3] + $oy];
                    $segments[] = self::segment('C', $x, $y, $x1, $y1, $x2, $y2);
                    $control = [$x2, $y2];
                    $quadratic = null;
                    break;
                case 'Q':
                case 'T':
                    [$x1, $y1] = $upper === 'Q'
                        ? [$n[0] + $ox, $n[1] + $oy]
                        : ($quadratic !== null ? [2 * $x - $quadratic[0], 2 * $y - $quadratic[1]] : [$x, $y]);
                    $rest = $upper === 'Q' ? array_slice($n, 2) : $n;
                    [$x, $y] = [$rest[0] + $ox, $rest[1] + $oy];
                    $segments[] = self::segment('Q', $x, $y, $x1, $y1);
                    $quadratic = [$x1, $y1];
                    $control = null;
                    break;
                case 'A':
                    [$toX, $toY] = [$n[5] + $ox, $n[6] + $oy];
                    array_push($segments, ...self::arc($x, $y, $n[0], $n[1], $n[2], (int) $n[3], (int) $n[4], $toX, $toY));
                    [$x, $y] = [$toX, $toY];
                    $control = $quadratic = null;
                    break;
            }
        }

        return $segments;
    }

    /**
     * An elliptical arc as cubic curves, a quarter turn or less each (the
     * endpoint-to-centre conversion of the SVG spec, F.6.5).
     *
     * @return list<Segment>
     */
    private static function arc(float $x1, float $y1, float $rx, float $ry, float $turn, int $large, int $sweep, float $x2, float $y2): array
    {
        if ($x1 === $x2 && $y1 === $y2) {
            return [];
        }

        [$rx, $ry] = [abs($rx), abs($ry)];

        if ($rx == 0 || $ry == 0) {
            return [self::segment('L', $x2, $y2)];
        }

        $phi = deg2rad($turn);
        [$cos, $sin] = [cos($phi), sin($phi)];
        [$dx, $dy] = [($x1 - $x2) / 2, ($y1 - $y2) / 2];
        [$px, $py] = [$cos * $dx + $sin * $dy, -$sin * $dx + $cos * $dy];
        // Radii too small to reach are grown until they just do
        $grow = ($px * $px) / ($rx * $rx) + ($py * $py) / ($ry * $ry);

        if ($grow > 1) {
            [$rx, $ry] = [$rx * sqrt($grow), $ry * sqrt($grow)];
        }

        $sign = $large === $sweep ? -1 : 1;
        $top = $rx * $rx * $ry * $ry - $rx * $rx * $py * $py - $ry * $ry * $px * $px;
        $bottom = $rx * $rx * $py * $py + $ry * $ry * $px * $px;
        $scale = $sign * sqrt(max(0.0, $top / $bottom));
        [$cxp, $cyp] = [$scale * $rx * $py / $ry, -$scale * $ry * $px / $rx];
        [$cx, $cy] = [$cos * $cxp - $sin * $cyp + ($x1 + $x2) / 2, $sin * $cxp + $cos * $cyp + ($y1 + $y2) / 2];
        $angle = fn (float $ux, float $uy, float $vx, float $vy) => atan2($ux * $vy - $uy * $vx, $ux * $vx + $uy * $vy);
        $start = $angle(1, 0, ($px - $cxp) / $rx, ($py - $cyp) / $ry);
        $delta = $angle(($px - $cxp) / $rx, ($py - $cyp) / $ry, (-$px - $cxp) / $rx, (-$py - $cyp) / $ry);

        if (! $sweep && $delta > 0) {
            $delta -= 2 * M_PI;
        } elseif ($sweep && $delta < 0) {
            $delta += 2 * M_PI;
        }

        $pieces = (int) ceil(abs($delta) / (M_PI / 2));
        $step = $delta / $pieces;
        $handle = 4 / 3 * tan($step / 4);
        $point = fn (float $t) => [$cx + $rx * cos($t) * $cos - $ry * sin($t) * $sin, $cy + $rx * cos($t) * $sin + $ry * sin($t) * $cos];
        $tangent = fn (float $t) => [-$rx * sin($t) * $cos - $ry * cos($t) * $sin, -$rx * sin($t) * $sin + $ry * cos($t) * $cos];
        $curves = [];

        for ($i = 0; $i < $pieces; $i++) {
            $a = $start + $i * $step;
            $b = $a + $step;
            [$fx, $fy] = $point($a);
            [$tx, $ty] = $point($b);
            [$ax, $ay] = $tangent($a);
            [$bx, $by] = $tangent($b);
            $curves[] = self::segment('C', $tx, $ty, $fx + $handle * $ax, $fy + $handle * $ay, $tx - $handle * $bx, $ty - $handle * $by);
        }

        // Ended exactly where the arc does, whatever the sums drifted by
        $last = array_pop($curves);

        return [...$curves, self::segment('C', $x2, $y2, $last['x1'], $last['y1'], $last['x2'], $last['y2'])];
    }

    /**
     * @return Segment
     */
    private static function segment(string $type, float $x, float $y, float $x1 = 0.0, float $y1 = 0.0, float $x2 = 0.0, float $y2 = 0.0): array
    {
        return ['type' => $type, 'x' => $x, 'y' => $y, 'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2];
    }

    /**
     * Path data again, numbers kept to two places.
     *
     * @param  list<Segment>  $segments
     */
    public static function toString(array $segments): string
    {
        $n = fn (float $value) => (string) (round($value, 2) + 0);

        return implode(' ', array_map(fn (array $s) => match ($s['type']) {
            'M', 'L' => $s['type'].$n($s['x']).' '.$n($s['y']),
            'C' => 'C'.$n($s['x1']).' '.$n($s['y1']).' '.$n($s['x2']).' '.$n($s['y2']).' '.$n($s['x']).' '.$n($s['y']),
            'Q' => 'Q'.$n($s['x1']).' '.$n($s['y1']).' '.$n($s['x']).' '.$n($s['y']),
            default => 'Z',
        }, $segments));
    }

    /**
     * Every point of a path moved by $dx, $dy.
     *
     * @param  list<Segment>  $segments
     * @return list<Segment>
     */
    public static function translate(array $segments, float $dx, float $dy): array
    {
        return array_map(fn (array $s) => self::segment($s['type'], $s['x'] + $dx, $s['y'] + $dy, $s['x1'] + $dx, $s['y1'] + $dy, $s['x2'] + $dx, $s['y2'] + $dy), $segments);
    }

    /**
     * The box the path's line runs through: its curves followed, not their
     * control points.
     *
     * @param  list<Segment>  $segments
     * @return array{x: float, y: float, width: float, height: float}
     */
    public static function bounds(array $segments): array
    {
        [$minX, $minY, $maxX, $maxY] = [INF, INF, -INF, -INF];
        [$x, $y, $startX, $startY] = [0.0, 0.0, 0.0, 0.0];
        $take = function (float $px, float $py) use (&$minX, &$minY, &$maxX, &$maxY) {
            [$minX, $minY, $maxX, $maxY] = [min($minX, $px), min($minY, $py), max($maxX, $px), max($maxY, $py)];
        };

        foreach ($segments as $s) {
            if ($s['type'] === 'Z') {
                [$x, $y] = [$startX, $startY];

                continue;
            }

            if ($s['type'] === 'M') {
                [$startX, $startY] = [$s['x'], $s['y']];
            }

            if ($s['type'] === 'C' || $s['type'] === 'Q') {
                // Followed in small steps: close enough to place and size it
                for ($i = 1; $i < 24; $i++) {
                    $t = $i / 24;
                    $u = 1 - $t;
                    $s['type'] === 'C'
                        ? $take(
                            $u ** 3 * $x + 3 * $u * $u * $t * $s['x1'] + 3 * $u * $t * $t * $s['x2'] + $t ** 3 * $s['x'],
                            $u ** 3 * $y + 3 * $u * $u * $t * $s['y1'] + 3 * $u * $t * $t * $s['y2'] + $t ** 3 * $s['y'],
                        )
                        : $take(
                            $u * $u * $x + 2 * $u * $t * $s['x1'] + $t * $t * $s['x'],
                            $u * $u * $y + 2 * $u * $t * $s['y1'] + $t * $t * $s['y'],
                        );
                }
            }

            $take($s['x'], $s['y']);
            [$x, $y] = [$s['x'], $s['y']];
        }

        return is_finite($minX)
            ? ['x' => $minX, 'y' => $minY, 'width' => $maxX - $minX, 'height' => $maxY - $minY]
            : ['x' => 0.0, 'y' => 0.0, 'width' => 0.0, 'height' => 0.0];
    }

    /**
     * The points along the first stretch of a path -- up to where it lifts
     * off with another M -- a few units apart, curves followed in small
     * steps: a line a pencil can draw, as [x, y, x, y, ...].
     *
     * @param  list<Segment>  $segments
     * @return list<float>
     */
    public static function sample(array $segments, float $spacing = 4.0): array
    {
        $points = [];
        [$x, $y, $startX, $startY] = [0.0, 0.0, 0.0, 0.0];
        $add = function (float $px, float $py) use (&$points) {
            array_push($points, round($px, 2), round($py, 2));
        };

        foreach ($segments as $at => $s) {
            if ($s['type'] === 'M') {
                if ($at > 0) {
                    break;
                }

                [$x, $y, $startX, $startY] = [$s['x'], $s['y'], $s['x'], $s['y']];
                $add($x, $y);

                continue;
            }

            [$toX, $toY] = $s['type'] === 'Z' ? [$startX, $startY] : [$s['x'], $s['y']];
            // Enough steps that no two points are far apart: by how far the
            // segment goes, its handles counted for a curve
            $reach = abs($toX - $x) + abs($toY - $y)
                + ($s['type'] === 'C' || $s['type'] === 'Q' ? abs($s['x1'] - $x) + abs($s['y1'] - $y) : 0);
            $steps = max(1, min(200, (int) ceil($reach / $spacing)));

            for ($i = 1; $i <= $steps; $i++) {
                $t = $i / $steps;
                $u = 1 - $t;

                match ($s['type']) {
                    'C' => $add(
                        $u ** 3 * $x + 3 * $u * $u * $t * $s['x1'] + 3 * $u * $t * $t * $s['x2'] + $t ** 3 * $toX,
                        $u ** 3 * $y + 3 * $u * $u * $t * $s['y1'] + 3 * $u * $t * $t * $s['y2'] + $t ** 3 * $toY,
                    ),
                    'Q' => $add(
                        $u * $u * $x + 2 * $u * $t * $s['x1'] + $t * $t * $toX,
                        $u * $u * $y + 2 * $u * $t * $s['y1'] + $t * $t * $toY,
                    ),
                    default => $add($x + ($toX - $x) * $t, $y + ($toY - $y) * $t),
                };
            }

            [$x, $y] = [$toX, $toY];
        }

        return $points;
    }
}
