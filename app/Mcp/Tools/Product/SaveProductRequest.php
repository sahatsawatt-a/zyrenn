<?php

namespace App\Mcp\Tools\Product;

use App\Mcp\Tools\ProductTool;
use App\Models\Product\ProductRequest;
use App\Support\Product\Keeper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Keep a product request')]
#[Description('Keep what was asked of a project\'s product as a request (RQ-n), word for word -- in "original", exactly as it was said, not a summary -- or, with "code", move one along by its "status". What a request says is never changed afterwards: what it means is written as requirements (save-requirement, with "request").')]
class SaveProductRequest extends ProductTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->max(16)->description('A request already kept, e.g. "RQ-4", to change its status. Left out, a new one is kept.'),
            'original' => $schema->string()->max(20000)->description('For a new one: what was asked, word for word.'),
            'from' => $schema->string()->max(160)->description('For a new one: who asked.'),
            'status' => $schema->string()->enum(ProductRequest::STATUSES)->description('new, analysing, clarifying, split (worked out into requirements), rejected, done.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $project = $this->targetProject($request, changes: true);
        $validated = $request->validate([
            'code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'original' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'from' => ['sometimes', 'nullable', 'string', 'max:160'],
            'status' => ['sometimes', 'in:'.implode(',', ProductRequest::STATUSES)],
        ]);

        if (filled($validated['code'] ?? null)) {
            if (array_key_exists('original', $validated)) {
                throw ValidationException::withMessages(['original' => 'What was asked is kept as it was asked: write what it means as a requirement (save-requirement, with "request").']);
            }

            $kept = $this->requestCoded($project, $validated['code']);
            $kept->update(array_intersect_key($validated, ['status' => true]));
        } else {
            if (blank($validated['original'] ?? null)) {
                throw ValidationException::withMessages(['original' => 'Give what was asked, word for word, in "original" -- or a request\'s "code" to change its status.']);
            }

            $kept = Keeper::record($project, $this->targetUser($request), $validated['original'], 'typed', $validated['from'] ?? null);

            if (isset($validated['status'])) {
                $kept->update(['status' => $validated['status']]);
            }
        }

        return Response::structured([
            'code' => $kept->code,
            'original' => $kept->original,
            'from' => $kept->from_name,
            'status' => $kept->status,
            'url' => $this->productUrl($project),
        ]);
    }
}
