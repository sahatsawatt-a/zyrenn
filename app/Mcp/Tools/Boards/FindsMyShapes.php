<?php

namespace App\Mcp\Tools\Boards;

use App\Models\Board\BoardShape;
use App\Models\Owner;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shapes of one's own as a board offers them: the user's, and, working in a
 * project, the project's.
 */
trait FindsMyShapes
{
    /** @return Collection<int, BoardShape> */
    protected function shapesFor(User $user, Owner $owner): Collection
    {
        $own = $user->boardShapes()->orderBy('name')->get();

        return $owner instanceof Project ? $own->concat($owner->boardShapes()->orderBy('name')->get()) : $own;
    }

    /** One of them, by its ref_id or its name. */
    protected function shapeCalled(User $user, Owner $owner, string $named): ?BoardShape
    {
        $all = $this->shapesFor($user, $owner);

        return $all->firstWhere('ref_id', $named)
            ?? $all->first(fn (BoardShape $shape) => mb_strtolower($shape->name) === mb_strtolower($named));
    }

    /** @return array<string, mixed> */
    protected function shapeDescribed(BoardShape $shape): array
    {
        return [
            'ref_id' => $shape->ref_id,
            'name' => $shape->name,
            'width' => $shape->width,
            'height' => $shape->height,
            'items' => count($shape->items),
            'kinds' => array_values(array_unique(array_column($shape->items, 'kind'))),
            'for' => $shape->project_id !== null ? 'project' : 'me',
        ];
    }
}
