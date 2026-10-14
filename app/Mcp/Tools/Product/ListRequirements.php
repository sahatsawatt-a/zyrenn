<?php

namespace App\Mcp\Tools\Product;

use App\Mcp\Tools\ProductTool;
use App\Models\Product\Requirement;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Title('List requirements')]
#[Description('List a project\'s requirements (REQ-n) -- what its product must do -- newest first, each in full: title, the need it serves, status, how well it is understood, its acceptance criteria and how many are met, open questions, assumptions, the parts (codes) it is about, its tickets, the request it came from, and the requirement it changes. Those not yet settled unless "status" says otherwise; "part" for those about one part.')]
class ListRequirements extends ProductTool
{
    private const LEFT = 'left';

    private const ALL = 'all';

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum([self::LEFT, self::ALL, ...Requirement::STATUSES])
                ->description('"left" (the default): not yet verified, released or dropped; "all"; or one status: draft, clarifying, ready, building, verifying, verified, released, dropped.'),
            'part' => $schema->string()->max(16)->description('Only those about this part (its code).'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $project = $this->targetProject($request);
        $validated = $request->validate([
            'status' => ['sometimes', 'in:'.implode(',', [self::LEFT, self::ALL, ...Requirement::STATUSES])],
            'part' => ['sometimes', 'nullable', 'string', 'max:16'],
        ]);
        $status = $validated['status'] ?? self::LEFT;
        $part = filled($validated['part'] ?? null) ? $this->partCoded($project, (string) $validated['part'], 'part') : null;

        $requirements = $project->requirements()
            ->when($status === self::LEFT, fn (Builder $query) => $query->whereNotIn('status', ['verified', 'released', 'dropped']))
            ->when(in_array($status, Requirement::STATUSES, true), fn (Builder $query) => $query->where('status', $status))
            ->when($part !== null, fn (Builder $query) => $query->whereHas('elements', fn (Builder $about) => $about->whereKey($part?->id)))
            ->latest('id')
            ->get();

        return Response::structured([
            'project' => $project->only(['ref_id', 'name']),
            'requirements' => $requirements->map(fn (Requirement $one) => $this->requirementShown($one))->all(),
            'url' => $this->productUrl($project),
        ]);
    }
}
