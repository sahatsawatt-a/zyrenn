<?php

namespace App\Support\Workflow\Steps;

use App\Models\Ticket\Ticket;
use Illuminate\Database\Eloquent\Builder;

/**
 * Find tickets: the project's, newest first -- those still to be done unless
 * told otherwise, of a type, about a part, with some words in them.
 */
final class TicketsFind implements Step
{
    /** Said for the status: still to be done, started or not. */
    public const LEFT = 'left';

    public const ALL = 'all';

    public function run(StepContext $step): StepResult
    {
        $project = TicketSteps::project($step);
        $status = (string) ($step->setting('status') ?: self::LEFT);
        $type = $step->setting('type');
        $unit = mb_strtolower(trim((string) $step->template('unit')));
        $search = mb_strtolower(trim((string) $step->template('search')));
        $limit = max(1, min(200, (int) ($step->setting('limit') ?: 50)));

        $tickets = $project->tickets()
            ->with(['assignee:id,email', 'project:id,ref_id'])
            ->when($status === self::LEFT, fn (Builder $query) => $query->whereIn('status', Ticket::UNSETTLED))
            ->when(in_array($status, Ticket::STATUSES, true), fn (Builder $query) => $query->where('status', $status))
            ->when(in_array($type, Ticket::TYPES, true), fn (Builder $query) => $query->where('type', $type))
            ->when($unit !== '', fn (Builder $query) => $query->whereRaw('lower(unit) = ?', [$unit]))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $either) => $either
                ->whereRaw('lower(title) like ?', ["%{$search}%"])
                ->orWhereRaw('lower(body) like ?', ["%{$search}%"])))
            ->orderByDesc('number')
            ->limit($limit)
            ->get();

        return new StepResult([
            'tickets' => $tickets->map(fn (Ticket $ticket) => TicketSteps::out($ticket))->values()->all(),
            'count' => $tickets->count(),
        ]);
    }
}
