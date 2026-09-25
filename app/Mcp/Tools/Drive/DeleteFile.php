<?php

namespace App\Mcp\Tools\Drive;

use App\Mcp\Tools\DriveTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Permanently delete a Drive file and its bytes. Any note that shows it will be left with a broken image, so check with list-notes first if you are unsure.')]
class DeleteFile extends DriveTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'ref_id' => $this->refIdArgument($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = $this->targetUser($request);
        $validated = $request->validate(['ref_id' => ['required', 'string', 'max:16']]);

        $file = $this->findFile($user, $validated['ref_id']);

        if (! $file) {
            return Response::error("File {$validated['ref_id']} was not found.");
        }

        $file->delete();

        return Response::text("Deleted file {$validated['ref_id']} (\"{$file->name}\") from the Drive.");
    }
}
