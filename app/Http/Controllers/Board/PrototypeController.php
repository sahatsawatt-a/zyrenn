<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Models\Board\Board;
use App\Models\Project;
use App\Support\Dashboard\Previews;
use App\Support\Prototype\PrototypeTables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A project's prototypes: clickable screens to show someone what they will
 * get before anything is built. Each is kept as a board -- drawn and edited
 * as one, live with everyone -- whose frames are its screens, at the size of
 * the device it is made for; Play clicks through them.
 */
class PrototypeController extends Controller
{
    /** How big a screen is on each device: as features/prototypes/devices.ts says. */
    public const SIZES = [
        'phone' => [390, 844],
        'tablet' => [834, 1194],
        'desktop' => [1440, 900],
    ];

    /** The screens a new prototype starts with. */
    private const FIRST_SCREENS = ['Home', 'List', 'Detail'];

    /** Room between screens, side by side. */
    private const GAP = 160;

    public function index(Project $project): Response
    {
        Gate::authorize('view', $project);

        $prototypes = $project->prototypes()->with('editor:id,name')->latest('updated_at')->get();
        /** @var Collection<int, array{kind: string, ref_id: string}> $glimpses */
        $glimpses = $prototypes->map(fn (Board $board) => ['kind' => 'board', 'ref_id' => $board->ref_id])->toBase();

        return Inertia::render('prototypes/Index', [
            'prototypes' => $prototypes->map(fn (Board $board) => [
                'ref_id' => $board->ref_id,
                'title' => $board->title,
                'device' => $board->prototype['device'] ?? 'phone',
                'screens' => count(self::screens($board)),
                'updated_at' => $board->updated_at,
                'edited_by' => $board->getRelationValue('editor')?->getAttribute('name'),
            ])->values(),
            // A picture of each one's first screen
            'previews' => Previews::of($glimpses),
        ]);
    }

    /**
     * A new prototype for a device, with a few empty screens to start from,
     * opened to be drawn on.
     */
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('contribute', $project);

        $valid = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'device' => ['required', Rule::in(Board::DEVICES)],
        ]);
        [$width, $height] = self::SIZES[$valid['device']];

        $board = (new Board)->ownedBy($project, $request->user());
        $board->title = trim((string) ($valid['title'] ?? '')) ?: 'Untitled prototype';
        $board->kind = Board::PROTOTYPE;
        $board->prototype = ['device' => $valid['device'], 'start' => 'screen-1'];
        $board->content = ['items' => array_map(
            fn (string $name, int $at) => self::screen('screen-'.($at + 1), $name, $at * ($width + self::GAP), $width, $height),
            self::FIRST_SCREENS,
            array_keys(self::FIRST_SCREENS),
        )];
        $board->save();

        return to_route('prototypes.show', $board);
    }

    /**
     * It, to draw its screens on: the board page, with its screens bar.
     */
    public function show(Board $board): Response
    {
        Gate::authorize('view', $board);
        abort_unless($board->isPrototype(), 404);

        return BoardController::page($board);
    }

    /**
     * Change what it is made for, or the screen Play opens on. A new device
     * changes the size new screens are made at; the ones there keep theirs.
     */
    public function update(Request $request, Board $board): RedirectResponse
    {
        Gate::authorize('update', $board);
        abort_unless($board->isPrototype(), 404);

        $valid = $request->validate([
            'device' => ['sometimes', Rule::in(Board::DEVICES)],
            'start' => ['sometimes', 'nullable', 'string', 'max:64'],
            // What Play's saves and deletes change: a copy of the tables for
            // each play, or the tables themselves
            'data' => ['sometimes', Rule::in(Board::PROTOTYPE_DATA)],
        ]);

        $settings = $board->prototype ?? ['device' => 'phone', 'start' => null];

        if (isset($valid['device'])) {
            $settings['device'] = (string) $valid['device'];
        }

        if (array_key_exists('start', $valid)) {
            $settings['start'] = $valid['start'] === null ? null : (string) $valid['start'];
        }

        if (isset($valid['data'])) {
            $settings['data'] = (string) $valid['data'];
        }

        $board->prototype = $settings;
        $board->save();

        return back();
    }

    /**
     * Click through its screens, as the person it is for would.
     */
    public function play(Request $request, Board $board): Response
    {
        Gate::authorize('view', $board);
        abort_unless($board->isPrototype(), 404);

        $settings = $board->prototype ?? ['device' => 'phone', 'start' => null];

        return Inertia::render('prototypes/Play', [
            // The project tables it reads and writes, as each play starts with
            // them; and whether saving changes the tables themselves -- only
            // when it is set to, and for whoever may change the project
            'tables' => PrototypeTables::forPlay($board, $request->user()),
            'can_save' => ($settings['data'] ?? 'play') === 'tables' && $request->user()->can('update', $board),
            'board' => $board->only(['ref_id', 'title']),
            'prototype' => $board->prototype ?? ['device' => 'phone', 'start' => null],
            'items' => $board->content['items'] ?? [],
            'screens' => self::screens($board),
            'edit' => route('prototypes.show', $board),
            'project' => $board->project?->only(['ref_id', 'name']),
        ]);
    }

    /**
     * Its screens -- the frames on it -- left to right, then top to bottom.
     *
     * @return list<array{id: string, title: string}>
     */
    public static function screens(Board $board): array
    {
        $frames = array_filter(
            (array) ($board->content['items'] ?? []),
            fn ($item) => is_array($item) && ($item['kind'] ?? null) === 'frame',
        );

        usort($frames, fn (array $a, array $b) => [round(($a['y'] ?? 0) / 200), $a['x'] ?? 0] <=> [round(($b['y'] ?? 0) / 200), $b['x'] ?? 0]);

        return array_map(
            fn (array $frame) => ['id' => (string) $frame['id'], 'title' => trim((string) ($frame['text'] ?? '')) ?: 'Untitled screen'],
            $frames,
        );
    }

    /**
     * A screen: a frame at the device's size, white, ready to draw on.
     *
     * @return array<string, mixed>
     */
    private static function screen(string $id, string $name, int $x, int $width, int $height): array
    {
        return [
            'id' => $id, 'kind' => 'frame', 'x' => $x, 'y' => 0, 'width' => $width, 'height' => $height,
            'text' => $name, 'fill' => '#ffffff', 'stroke' => '#e2e8f0',
            'to' => null, 'fit' => 'fill', 'src' => '', 'from' => null, 'rich' => false, 'align' => 'center',
            'hidden' => false, 'locked' => false, 'points' => [], 'endHead' => 'arrow', 'padding' => 12,
            'routing' => 'elbow', 'fontSize' => 16, 'headSize' => 10, 'rotation' => 0, 'lineStyle' => 'solid',
            'lineWidth' => 2, 'pdfHidden' => false, 'startHead' => 'none', 'fontFamily' => 'sans', 'verticalAlign' => 'middle',
            'symbol' => '', 'on' => false,
        ];
    }
}
