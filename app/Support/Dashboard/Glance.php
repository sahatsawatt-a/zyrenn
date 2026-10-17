<?php

namespace App\Support\Dashboard;

use App\Models\AuditEvent;
use App\Models\Board\Board;
use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\Note\Note;
use App\Models\Product\ProductElement;
use App\Models\Product\ProductRequest;
use App\Models\Project;
use App\Models\Table\Table;
use App\Models\Ticket\Ticket;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Workflow\RunStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A project at a glance: its tickets by how far along they are, what is
 * being built, whether its workflows and browser checks are running well,
 * and what happened in it lately -- as a card on someone's own dashboard,
 * or in full on the project's.
 */
final class Glance
{
    /** Product parts still being made, as the tree says. */
    private const BUILDING = ['building', 'partial', 'verifying'];

    /** Workflow audit lines too frequent to be news. */
    private const QUIET = ['workflow.run'];

    /**
     * A card for each of the user's projects, the busiest first; the page
     * shows the first few as cards and the rest by name.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function projects(User $user): array
    {
        $projects = $user->projects()->get();

        return $projects
            ->map(function (Project $project) {
                $tickets = self::tickets($project);
                $activity = self::activity($project, 1);

                return [
                    'ref_id' => $project->ref_id,
                    'name' => $project->name,
                    'role' => $project->getRelationValue('pivot')?->getAttribute('role'),
                    'tickets' => $tickets['counts'],
                    'building' => self::building($project, 3),
                    'running' => self::running($project),
                    'last' => $activity[0] ?? null,
                ];
            })
            ->sortByDesc(fn (array $card) => $card['last']['at'] ?? '')
            ->values()
            ->all();
    }

    /**
     * A project's own dashboard, in full.
     *
     * @return array<string, mixed>
     */
    public static function project(Project $project): array
    {
        return [
            'tickets' => self::tickets($project, withLists: true),
            'building' => self::building($project, 6),
            'running' => self::running($project),
            'activity' => self::activity($project, 8),
        ];
    }

    /**
     * How many tickets stand where; and, in full, who has those in
     * progress and what those on hold wait on.
     *
     * @return array{counts: array{open: int, in_progress: int, on_hold: int}, in_progress?: array<int, array<string, mixed>>, on_hold?: array<int, array<string, mixed>>}
     */
    private static function tickets(Project $project, bool $withLists = false): array
    {
        $counts = Ticket::query()->where('project_id', $project->id)
            ->whereIn('status', Ticket::UNSETTLED)
            ->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status');
        $line = fn (Ticket $ticket) => [
            'number' => $ticket->number,
            'title' => $ticket->title,
            'assignee' => $ticket->assignee?->name,
            'reason' => $ticket->reason,
            'href' => route('projects.tickets.index', [$project, 'ticket' => $ticket->number]),
        ];

        return [
            'counts' => [
                'open' => (int) ($counts[Ticket::OPEN] ?? 0),
                'in_progress' => (int) ($counts[Ticket::IN_PROGRESS] ?? 0),
                'on_hold' => (int) ($counts[Ticket::ON_HOLD] ?? 0),
            ],
            ...$withLists ? [
                'in_progress' => $project->tickets()->where('status', Ticket::IN_PROGRESS)->with('assignee:id,name')
                    ->latest('updated_at')->limit(4)->get()->map($line)->values()->all(),
                'on_hold' => $project->tickets()->where('status', Ticket::ON_HOLD)->with('assignee:id,name')
                    ->latest('updated_at')->limit(2)->get()->map($line)->values()->all(),
            ] : [],
        ];
    }

    /**
     * What the product tree says is being made.
     *
     * @return array<int, array{code: string, name: string, status: string}>
     */
    private static function building(Project $project, int $most): array
    {
        return ProductElement::query()->where('project_id', $project->id)
            ->where('kind', 'capability')->whereIn('status', self::BUILDING)
            ->orderByRaw("case status when 'building' then 0 when 'verifying' then 1 else 2 end")
            ->latest('updated_at')->limit($most)
            ->get(['code', 'name', 'status'])
            ->map(fn (ProductElement $part) => ['code' => $part->code, 'name' => $part->name, 'status' => $part->status])
            ->values()
            ->all();
    }

    /**
     * Whether its workflows and checks run well, and new requests.
     *
     * @return array{workflows_on: int, runs_failed: int, suites: int, suites_failing: int, requests_new: int}
     */
    private static function running(Project $project): array
    {
        $workflows = Workflow::query()->where('project_id', $project->id);
        $suites = BrowserSuite::query()->where('project_id', $project->id);
        $latestRuns = BrowserRun::query()->whereIn('suite_id', (clone $suites)->select('id'))
            ->where('trial', false)->whereIn('state', [BrowserRun::PASSED, BrowserRun::FAILED])
            ->groupBy('suite_id')->selectRaw('max(id)');

        return [
            'workflows_on' => (clone $workflows)->where('enabled', true)->count(),
            'runs_failed' => WorkflowRun::query()
                ->whereIn('workflow_id', (clone $workflows)->select('id'))
                ->whereHas('version', fn (Builder $v) => $v->where('kind', '!=', WorkflowVersion::TEST))
                ->where('status', RunStatus::Failed->value)
                ->where('finished_at', '>=', Carbon::now()->subDay())
                ->count(),
            'suites' => (clone $suites)->count(),
            'suites_failing' => BrowserRun::query()->whereIn('id', $latestRuns)->where('state', BrowserRun::FAILED)->count(),
            'requests_new' => ProductRequest::query()->where('project_id', $project->id)->where('status', 'new')->count(),
        ];
    }

    /**
     * What happened in it lately, by whom: notes, boards and tables changed,
     * tickets filed and moved on, workflows and connections set up.
     *
     * @return array<int, array{who: string|null, text: string, at: string, href: string|null}>
     */
    public static function activity(Project $project, int $most): array
    {
        $since = Carbon::now()->subDays(30);

        $edits = collect();

        foreach ([[Note::class, 'note', 'notes.show'], [Board::class, 'board', 'boards.show'], [Table::class, 'table', 'tables.show']] as [$class, $word, $page]) {
            $edits = $edits->concat($class::query()
                ->where('project_id', $project->id)->where('updated_at', '>=', $since)
                ->with('editor:id,name')->latest('updated_at')->limit($most)->get()
                ->map(fn (Model $thing) => [
                    'who' => $thing->getRelationValue('editor')?->getAttribute('name'),
                    'text' => "edited the {$word} “".($thing->getAttribute('title') ?: 'Untitled').'”',
                    'at' => $thing->getAttribute('updated_at'),
                    'href' => route($page, $thing),
                ]));
        }

        $tickets = Ticket::query()->where('project_id', $project->id)->where('updated_at', '>=', $since)
            ->with(['creator:id,name', 'assignee:id,name'])->latest('updated_at')->limit($most)->get()
            ->map(fn (Ticket $ticket) => [
                // Filed, by whoever filed it; moved on, by whoever has it
                'who' => $ticket->status === Ticket::OPEN || $ticket->assignee === null
                    ? $ticket->creator?->name
                    : $ticket->assignee->name,
                'text' => match ($ticket->status) {
                    Ticket::DONE => "closed #{$ticket->number} {$ticket->title}",
                    Ticket::DROPPED => "turned down #{$ticket->number} {$ticket->title}",
                    Ticket::ON_HOLD => "put #{$ticket->number} {$ticket->title} on hold",
                    Ticket::IN_PROGRESS => "is on #{$ticket->number} {$ticket->title}",
                    default => "filed #{$ticket->number} {$ticket->title}",
                },
                'at' => $ticket->updated_at,
                'href' => route('projects.tickets.index', [$project, 'ticket' => $ticket->number]),
            ]);

        $audit = AuditEvent::query()->where('project_id', $project->id)->where('created_at', '>=', $since)
            ->whereNotIn('action', self::QUIET)->with('actor:id,name')->latest('id')->limit($most)->get()
            ->map(fn (AuditEvent $event) => [
                'who' => $event->actor?->name,
                'text' => self::said($event),
                'at' => $event->created_at,
                'href' => null,
            ]);

        /** @var Collection<int, array{who: string|null, text: string, at: mixed, href: string|null}> $all */
        $all = $edits->concat($tickets)->concat($audit);

        return $all
            ->filter(fn (array $line) => $line['at'] !== null)
            ->sortByDesc(fn (array $line) => Carbon::parse($line['at'])->getTimestamp())
            ->take($most)
            ->map(fn (array $line) => [...$line, 'at' => Carbon::parse($line['at'])->toIso8601String()])
            ->values()
            ->all();
    }

    private static function said(AuditEvent $event): string
    {
        $name = '“'.($event->subject_name ?: 'one').'”';

        return match ($event->action) {
            'workflow.published' => "published the workflow {$name}",
            'workflow.enabled' => "switched on {$name}",
            'workflow.disabled' => "switched off {$name}",
            'workflow.approved' => "approved a step of {$name}",
            'workflow.rejected' => "rejected a step of {$name}",
            'workflow.created' => "made the workflow {$name}",
            'workflow.deleted' => "deleted the workflow {$name}",
            'workflow.stopped' => "stopped a run of {$name}",
            'connection.added' => "added the connection {$name}",
            'connection.changed' => "changed the connection {$name}",
            'connection.removed' => "removed the connection {$name}",
            default => str_replace('.', ' ', $event->action)." {$name}",
        };
    }
}
