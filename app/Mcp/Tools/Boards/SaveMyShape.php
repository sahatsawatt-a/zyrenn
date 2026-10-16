<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use App\Models\Board\Board;
use App\Models\Board\BoardShape;
use App\Models\Project;
use App\Support\Board\BoardShapes;
use App\Support\Live\Collab;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Keep items of a board as one of the user's own shapes ("My shapes"), to put on any board: a drawing, a
logo, a group of shapes and words. Named by "items" (their ids, as get-board gives them); lines running
only between them come too. A copy: changing it later changes no board. On a project's board,
"for_project" keeps it for everyone in the project.
TEXT)]
class SaveMyShape extends BoardTool
{
    use FindsMyShapes;

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $schema->string()->max(16)->description('The board\'s ref_id.')->required(),
            'items' => $schema->array()->items($schema->string()->max(64))->description('The ids of the items it is made of.')->required(),
            'name' => $schema->string()->max(80)->required(),
            'for_project' => $schema->boolean()->description('On a project\'s board: kept for everyone in the project, not only for the user.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request);
        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'items' => ['required', 'array', 'min:1', 'max:'.BoardShapes::MOST_ITEMS],
            'items.*' => ['string', 'max:64'],
            'name' => ['required', 'string', 'max:80'],
            'for_project' => ['sometimes', 'boolean'],
        ]);

        /** @var Board|null $board */
        $board = $this->find($owner, $validated['ref_id']);

        if (! $board) {
            return $this->notFound($validated['ref_id']);
        }

        Collab::flush($board);
        $board->refresh();
        $current = array_values(array_filter($board->content['items'] ?? [], 'is_array'));
        $wanted = array_flip($validated['items']);
        $picked = array_values(array_filter($current, fn (array $item) => isset($wanted[$item['id'] ?? ''])
            // A line running only between them comes too
            || (($item['kind'] ?? '') === 'arrow' && isset($wanted[$item['from']['item'] ?? ''], $wanted[$item['to']['item'] ?? '']))));

        $missing = array_values(array_diff($validated['items'], array_column($picked, 'id')));

        if ($missing !== []) {
            return Response::error('The board has no item "'.$missing[0].'". get-board lists its items.');
        }

        $forProject = ($validated['for_project'] ?? false) && $board->project instanceof Project;

        if ($forProject && $user->cannot('update', $board)) {
            return Response::error('Only its owners and editors keep a shape for the whole project.');
        }

        try {
            $made = BoardShapes::fromItems($picked);
        } catch (ValidationException $problem) {
            return Response::error((string) collect($problem->errors())->flatten()->first());
        }

        $shape = (new BoardShape([
            'name' => trim($validated['name']),
            'items' => $made['items'],
            'width' => $made['width'],
            'height' => $made['height'],
        ]))->ownedBy($forProject ? $board->project : $user, $user);
        $shape->save();

        return Response::structured(['saved' => $this->shapeDescribed($shape)]);
    }
}
