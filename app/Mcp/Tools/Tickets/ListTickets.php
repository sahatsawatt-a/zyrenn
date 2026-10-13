<?php

namespace App\Mcp\Tools\Tickets;

use App\Mcp\Tools\TicketTool;
use App\Models\Ticket\Ticket;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List a project\'s tickets -- the bugs to fix and features to add it has been asked for -- newest first, each as a line: number, type, status, priority, title, unit. Those still to do unless "status" says otherwise. get-ticket reads one in full.')]
class ListTickets extends TicketTool
{
    private const LEFT = 'left';

    private const ALL = 'all';

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum([self::LEFT, self::ALL, ...Ticket::STATUSES])
                ->description('"left" (the default) for what is open or in progress, "all", or one status: "open", "in_progress", "done", "dropped" (looked at and turned down).'),
            'type' => $schema->string()->enum(Ticket::TYPES)->description('Only bugs, or only features.'),
            'unit' => $schema->string()->max(64)->description('Only those about this part of the product.'),
            'search' => $schema->string()->max(255)->description('Only those with this text in the title or the body.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $project = $this->targetProject($request);

        $validated = $request->validate([
            'status' => ['sometimes', 'in:'.implode(',', [self::LEFT, self::ALL, ...Ticket::STATUSES])],
            'type' => ['sometimes', 'in:'.implode(',', Ticket::TYPES)],
            'unit' => ['sometimes', 'string', 'max:64'],
            'search' => ['sometimes', 'string', 'max:255'],
        ]);

        $status = $validated['status'] ?? self::LEFT;
        $search = mb_strtolower(trim($validated['search'] ?? ''));

        $tickets = $project->tickets()
            ->with('assignee:id,email')
            ->when($status === self::LEFT, fn (Builder $query) => $query->whereIn('status', [Ticket::OPEN, Ticket::IN_PROGRESS]))
            ->when(in_array($status, Ticket::STATUSES, true), fn (Builder $query) => $query->where('status', $status))
            ->when(isset($validated['type']), fn (Builder $query) => $query->where('type', $validated['type']))
            ->when(isset($validated['unit']), fn (Builder $query) => $query->whereRaw('lower(unit) = ?', [mb_strtolower(trim($validated['unit']))]))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $either) => $either
                ->whereRaw('lower(title) like ?', ["%{$search}%"])
                ->orWhereRaw('lower(body) like ?', ["%{$search}%"])))
            ->orderByDesc('number')
            ->get();

        return Response::structured([
            'project' => $project->only(['ref_id', 'name']),
            'tickets' => $tickets->map(fn (Ticket $ticket) => $this->described($ticket, full: false))->all(),
            // The parts tickets here are about, to file the next one under the same name
            'units' => $project->tickets()->where('unit', '!=', '')->distinct()->orderBy('unit')->pluck('unit')->all(),
            'url' => route('projects.tickets.index', $project),
        ]);
    }
}
