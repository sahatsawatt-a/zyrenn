<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use App\Models\Board\Board;
use App\Support\Board\BoardShapes;
use App\Support\Board\CircuitLayout;
use App\Support\Board\Deck;
use App\Support\Live\Collab;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Put one of the user's own shapes (list-my-shapes) on a board, as one group: its items made new, the lines
among them joined to the copies. Its top left goes at "x","y" -- under everything on the board when left
out -- and "width" scales it, its height in step.
TEXT)]
class PlaceMyShape extends BoardTool
{
    use FindsMyShapes;

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $schema->string()->max(16)->description('The board\'s ref_id.')->required(),
            'shape' => $schema->string()->max(80)->description('The shape\'s ref_id or name, as list-my-shapes gives it.')->required(),
            'x' => $schema->number()->min(-100000)->max(100000),
            'y' => $schema->number()->min(-100000)->max(100000),
            'width' => $schema->number()->min(4)->max(20000)->description('How wide it is put down; left out, as it was saved.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->targetUser($request);
        $owner = $this->targetOwner($request, changes: true);
        $validated = $request->validate([
            'ref_id' => ['required', 'string', 'max:16'],
            'shape' => ['required', 'string', 'max:80'],
            'x' => ['sometimes', 'numeric', 'between:-100000,100000'],
            'y' => ['sometimes', 'numeric', 'between:-100000,100000'],
            'width' => ['sometimes', 'numeric', 'between:4,20000'],
        ]);

        /** @var Board|null $board */
        $board = $this->find($owner, $validated['ref_id']);

        if (! $board) {
            return $this->notFound($validated['ref_id']);
        }

        $shape = $this->shapeCalled($user, $owner, $validated['shape']);

        if (! $shape) {
            return Response::error("There is no shape \"{$validated['shape']}\". list-my-shapes lists them.");
        }

        Collab::flush($board);
        $board->refresh();
        $current = array_values(array_filter($board->content['items'] ?? [], 'is_array'));
        $at = CircuitLayout::under($current);
        $placed = BoardShapes::placed(
            $shape,
            array_column($current, 'id'),
            (float) ($validated['x'] ?? $at['x']),
            (float) ($validated['y'] ?? $at['y']),
            isset($validated['width']) ? (float) $validated['width'] : null,
        );

        // Open somewhere: the live copy takes them, and hands the app the board
        if (Collab::apply($board, [['do' => 'set', 'items' => $placed]], $user) === null) {
            $board->content = Deck::content([...$current, ...$placed], Deck::of($board->content));
            $board->save();
        }

        return Response::structured([
            'placed' => $shape->name,
            'group' => $placed[0]['group'] ?? null,
            'added' => array_column($placed, 'id'),
        ]);
    }
}
