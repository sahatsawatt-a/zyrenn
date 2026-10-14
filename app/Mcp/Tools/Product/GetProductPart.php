<?php

namespace App\Mcp\Tools\Product;

use App\Mcp\Tools\ProductTool;
use App\Models\Product\ProductLink;
use App\Models\Product\Requirement;
use App\Models\Ticket\Ticket;
use App\Support\Product\Tracker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Title('Get a part of a product')]
#[Description('Read one part of a project\'s product in full, by its code: what it does today, where it sits and what is under it, what it uses and what uses it, its requirements and tickets, its history, and "impact" -- what a change to it would reach: the parts that use or depend on it (and on those), with their unsettled requirements and open tickets. Read this before changing a shared part.')]
class GetProductPart extends ProductTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->max(16)->description('The part\'s code, e.g. "CAP-12".')->required(),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $project = $this->targetProject($request);
        $validated = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $part = $this->partCoded($project, $validated['code']);
        $part->load(['parent:id,code,name', 'children:id,parent_id,code,name,kind', 'links.to:id,code,name', 'linkedFrom.from:id,parent_id,code,name', 'linkedFrom.from.parent:id,name', 'requirements', 'tickets', 'events.user:id,name']);

        $impact = Tracker::impact($part);

        $line = fn (array $one) => "{$one['code']} {$one['name']}".($one['under'] !== null ? " ({$one['under']})" : '');

        return Response::structured(array_filter([
            'code' => $part->code,
            'kind' => $part->kind,
            'name' => $part->name,
            'status' => $part->status,
            'summary' => $part->summary,
            'shared' => $part->shared,
            'under' => $part->parent ? "{$part->parent->code} {$part->parent->name}" : null,
            'parts_under' => $part->children->map(fn ($child) => "{$child->code} {$child->name}")->all(),
            'links' => $part->links->map(fn (ProductLink $link) => "{$link->kind} {$link->to->code} {$link->to->name}")->all(),
            'linked_from' => $part->linkedFrom->map(fn (ProductLink $link) => "{$link->from->code} {$link->from->name}".($link->from->parent ? " ({$link->from->parent->name})" : '')." {$link->kind} it")->all(),
            'requirements' => $part->requirements->map(fn (Requirement $one) => "{$one->code} {$one->title} ({$one->status})")->all(),
            'tickets' => $part->tickets->map(fn (Ticket $ticket) => "#{$ticket->number} {$ticket->type}: {$ticket->title} ({$ticket->status})")->all(),
            'impact' => [
                'direct' => array_map($line, $impact['direct']),
                'further' => array_map($line, $impact['further']),
                'requirements' => array_map(fn (array $one) => "{$one['code']} {$one['title']} ({$one['status']})", $impact['requirements']),
                'open_tickets' => array_map(fn (array $one) => "#{$one['number']} {$one['type']}: {$one['title']}", $impact['open_tickets']),
            ],
            'history' => $part->events->take(20)->map(fn ($event) => trim("{$event->created_at?->toDateString()} {$event->what}".($event->from !== null ? " {$event->from} →" : '').($event->to !== null ? " {$event->to}" : '').($event->user ? " by {$event->user->name}" : '')))->all(),
            'url' => $this->productUrl($project),
        ], fn ($value) => $value !== null && $value !== []));
    }
}
