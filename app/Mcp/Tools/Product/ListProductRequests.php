<?php

namespace App\Mcp\Tools\Product;

use App\Mcp\Tools\ProductTool;
use App\Models\Product\ProductRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Title('List product requests')]
#[Description('List what has been asked of a project\'s product (RQ-n), newest first, each word for word as it was asked -- typed in, kept from a customer\'s chat, or from a ticket -- with who asked, how far it has been worked out, and the requirements (REQ-n) made from it. Those still to work out unless "status" says otherwise.')]
class ListProductRequests extends ProductTool
{
    private const LEFT = 'left';

    private const ALL = 'all';

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum([self::LEFT, self::ALL, ...ProductRequest::STATUSES])
                ->description('"left" (the default): new, analysing or clarifying; "all"; or one status: new, analysing, clarifying, split (worked out into requirements), rejected, done.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $project = $this->targetProject($request);
        $validated = $request->validate(['status' => ['sometimes', 'in:'.implode(',', [self::LEFT, self::ALL, ...ProductRequest::STATUSES])]]);
        $status = $validated['status'] ?? self::LEFT;

        $requests = $project->productRequests()
            ->with(['requirements:id,product_request_id,code', 'chatMessage.room:id,ref_id,title', 'ticket:id,number'])
            ->when($status === self::LEFT, fn (Builder $query) => $query->whereIn('status', ['new', 'analysing', 'clarifying']))
            ->when(in_array($status, ProductRequest::STATUSES, true), fn (Builder $query) => $query->where('status', $status))
            ->latest('id')
            ->get();

        return Response::structured([
            'project' => $project->only(['ref_id', 'name']),
            'requests' => $requests->map(fn (ProductRequest $one) => array_filter([
                'code' => $one->code,
                'original' => $one->original,
                'from' => $one->from_name,
                'source' => $one->source,
                'chat' => $one->chatMessage?->room->ref_id,
                'ticket' => $one->ticket?->number,
                'status' => $one->status,
                'requirements' => $one->requirements->pluck('code')->all(),
                'at' => $one->created_at?->toIso8601String(),
            ], fn ($value) => $value !== null && $value !== []))->all(),
            'url' => $this->productUrl($project),
        ]);
    }
}
