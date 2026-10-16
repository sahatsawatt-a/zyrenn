<?php

namespace App\Mcp\Tools\Boards;

use App\Mcp\Tools\BoardTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TEXT'
List the user's own shapes -- drawings and groups of items saved from a board ("My shapes" in the shape
library), to put on any board with place-my-shape. Given a "project", its shapes too.
TEXT)]
class ListMyShapes extends BoardTool
{
    use FindsMyShapes;

    protected function arguments(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $shapes = $this->shapesFor($this->targetUser($request), $this->targetOwner($request));

        return Response::structured(['shapes' => $shapes->map(fn ($shape) => $this->shapeDescribed($shape))->values()->all()]);
    }
}
