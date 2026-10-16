<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Models\Board\Board;
use App\Models\Board\BoardShape;
use App\Models\Project;
use App\Models\User;
use App\Support\Board\BoardShapes;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Shapes of one's own (BoardShape), as a board's shape library offers them:
 * the user's own, on any board, and on a project's board the project's too.
 */
class BoardShapeController extends Controller
{
    /** The shapes to put on this board. */
    public function index(Request $request, Board $board): JsonResponse
    {
        Gate::authorize('view', $board);

        return response()->json(['shapes' => self::offered($request->user(), $board)
            ->map(fn (BoardShape $shape) => self::shown($shape, $request->user()))
            ->values()]);
    }

    /**
     * Keeps what was picked on the board as a shape: the user's own, or, on
     * a project's board and asked for, the project's.
     */
    public function store(Request $request, Board $board): JsonResponse
    {
        Gate::authorize('view', $board);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'items' => ['required', 'array', 'min:1', 'max:'.BoardShapes::MOST_ITEMS],
            'items.*' => ['array'],
            'items.*.kind' => ['required', 'string'],
            'preview' => ['nullable', 'string', 'max:300000', 'starts_with:data:image/png;base64,'],
            'for_project' => ['sometimes', 'boolean'],
        ]);

        $project = $board->project;
        $forProject = $request->boolean('for_project') && $project instanceof Project;

        if ($forProject && $request->user()->cannot('update', $board)) {
            throw ValidationException::withMessages(['for_project' => 'Only its owners and editors keep a shape for the whole project.']);
        }

        // As drawn, every field: what is validated above is only that each is an item
        $made = BoardShapes::fromItems(array_values((array) $request->input('items')));
        $shape = (new BoardShape([
            'name' => trim($validated['name']),
            'items' => $made['items'],
            'width' => $made['width'],
            'height' => $made['height'],
            'preview' => $validated['preview'] ?? null,
        ]))->ownedBy($forProject ? $project : $request->user(), $request->user());
        $shape->save();

        return response()->json(self::shown($shape, $request->user()), 201);
    }

    public function update(Request $request, BoardShape $boardShape): JsonResponse
    {
        Gate::authorize('update', $boardShape);

        $boardShape->update($request->validate(['name' => ['required', 'string', 'max:80']]));

        return response()->json(self::shown($boardShape, $request->user()));
    }

    public function destroy(BoardShape $boardShape): JsonResponse
    {
        Gate::authorize('delete', $boardShape);

        $boardShape->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * The user's own shapes, then the project's when the board is one of its.
     *
     * @return Collection<int, BoardShape>
     */
    public static function offered(User $user, Board $board): Collection
    {
        $own = $user->boardShapes()->orderBy('name')->get();
        $shared = $board->project?->boardShapes()->orderBy('name')->get() ?? new Collection;

        return $own->concat($shared);
    }

    /** @return array<string, mixed> */
    public static function shown(BoardShape $shape, User $user): array
    {
        return [
            'ref_id' => $shape->ref_id,
            'name' => $shape->name,
            'items' => $shape->items,
            'width' => $shape->width,
            'height' => $shape->height,
            'preview' => $shape->preview,
            // Kept for a project, rather than for whoever made it
            'shared' => $shape->project_id !== null,
            'can_change' => $user->can('update', $shape),
        ];
    }
}
