<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Models\Board\Board;
use App\Models\Prototype\PrototypePlay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Who played a prototype, and what they saw: a play starts when Play opens,
 * and what happened is added as it goes and once more as the page closes
 * (sent with keepalive, so it gets there as the tab goes). Only the one
 * playing adds to their play; anyone in the project reads them all.
 */
class PrototypePlayController extends Controller
{
    /** The most plays the panel shows, newest first. */
    private const SHOWN = 100;

    public function index(Board $board): JsonResponse
    {
        Gate::authorize('view', $board);
        abort_unless($board->isPrototype(), 404);

        return response()->json([
            'plays' => $board->prototypePlays()
                ->with('user:id,name')
                ->latest('started_at')
                ->limit(self::SHOWN)
                ->get()
                ->map(fn (PrototypePlay $play) => $play->shown())
                ->values(),
        ]);
    }

    public function store(Request $request, Board $board): JsonResponse
    {
        Gate::authorize('view', $board);
        abort_unless($board->isPrototype(), 404);

        $valid = $request->validate([
            'screen' => ['nullable', 'string', 'max:64'],
            'width' => ['nullable', 'integer', 'between:1,20000'],
            'height' => ['nullable', 'integer', 'between:1,20000'],
        ]);

        $play = new PrototypePlay;
        $play->board_id = $board->id;
        $play->user_id = $request->user()->id;
        $play->started_at = now();
        $play->width = $valid['width'] ?? null;
        $play->height = $valid['height'] ?? null;
        $play->path = isset($valid['screen']) ? [['screen' => (string) $valid['screen'], 'at' => 0.0]] : [];
        $play->misses = [];
        $play->save();

        return response()->json(['play' => $play->ref_id], 201);
    }

    /**
     * What happened since the last time: screens gone to and taps that
     * found nothing, each `at` seconds from the start; and, as the page
     * closes, that it ended.
     */
    public function append(Request $request, PrototypePlay $play): Response
    {
        abort_unless($play->user_id === $request->user()->id, 403);

        $valid = $request->validate([
            'path' => ['sometimes', 'array', 'max:'.PrototypePlay::MOST_STEPS],
            'path.*.screen' => ['required', 'string', 'max:64'],
            'path.*.at' => ['required', 'numeric', 'min:0'],
            'misses' => ['sometimes', 'array', 'max:'.PrototypePlay::MOST_STEPS],
            'misses.*.screen' => ['required', 'string', 'max:64'],
            'misses.*.x' => ['required', 'numeric', 'between:0,1'],
            'misses.*.y' => ['required', 'numeric', 'between:0,1'],
            'misses.*.at' => ['required', 'numeric', 'min:0'],
            'ended' => ['sometimes', 'boolean'],
        ]);

        $step = fn (array $one) => ['screen' => (string) $one['screen'], 'at' => round((float) $one['at'], 1)];
        $miss = fn (array $one) => [...$step($one), 'x' => round((float) $one['x'], 4), 'y' => round((float) $one['y'], 4)];

        $play->path = array_slice([...$play->path, ...array_map($step, array_values($valid['path'] ?? []))], 0, PrototypePlay::MOST_STEPS);
        $play->misses = array_slice([...$play->misses, ...array_map($miss, array_values($valid['misses'] ?? []))], 0, PrototypePlay::MOST_STEPS);

        if ($valid['ended'] ?? false) {
            $play->ended_at = now();
        }

        $play->save();

        return response()->noContent();
    }
}
