<?php

namespace App\Support\Dashboard;

use App\Models\Browser\BrowserRun;
use App\Models\Browser\BrowserSuite;
use App\Models\Chat\ChatRoom;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use App\Models\Workflow\Workflow;
use App\Models\Workflow\WorkflowNodeRun;
use App\Models\Workflow\WorkflowRun;
use App\Models\Workflow\WorkflowVersion;
use App\Support\Workflow\NodeStatus;
use App\Support\Workflow\RunStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What waits on someone, most pressing first: steps of their workflows
 * asking for approval, tickets given to them, chats with something unread,
 * browser checks and workflow runs that failed in the last day -- in all
 * their spaces, or in one project.
 *
 * @phpstan-type Item array{kind: string, title: string, context: string, at: string|null, href: string, priority?: string, answer?: string}
 */
final class Waiting
{
    /** How far back a failure is still news. */
    private const FAILED_WITHIN_HOURS = 24;

    private const MOST = 12;

    /**
     * @return array<int, Item>
     */
    public static function for(User $user, ?Project $project = null): array
    {
        $items = [
            ...self::approvals($user, $project),
            ...self::tickets($user, $project),
            ...self::chats($user, $project),
            ...self::failedChecks($user, $project),
            ...self::failedRuns($user, $project),
        ];

        return array_slice($items, 0, self::MOST);
    }

    /**
     * Workflows the user may change -- their own, or their projects' where
     * they own or edit -- narrowed to one project when asked.
     *
     * @return Builder<Workflow>
     */
    private static function changeable(User $user, ?Project $project): Builder
    {
        return Workflow::query()->where(fn (Builder $q) => $project
            ? $q->where('project_id', $project->id)
            : $q->where('user_id', $user->id)
                ->orWhereIn('project_id', $user->projects()->wherePivotIn('role', [Project::OWNER, Project::EDITOR])->select('projects.id')));
    }

    /**
     * @return array<int, Item>
     */
    private static function approvals(User $user, ?Project $project): array
    {
        if ($project && ! in_array($project->roleOf($user), [Project::OWNER, Project::EDITOR], true)) {
            return [];
        }

        return WorkflowNodeRun::query()
            ->where('status', NodeStatus::Waiting->value)
            ->where('waiting_for', 'approval')
            ->whereHas('run', fn (Builder $run) => $run->whereIn('workflow_id', self::changeable($user, $project)->select('id')))
            ->with(['run.workflow.project:id,name'])
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(function (WorkflowNodeRun $step) use ($project) {
                $run = $step->run;
                $workflow = $run->workflow;

                return [
                    'kind' => 'approval',
                    'title' => trim((string) ($step->wait['message'] ?? '')) ?: "Approve “{$step->node_key}”?",
                    'context' => self::where($workflow->project?->name, $project, $workflow->title ?: 'A workflow'),
                    'at' => $step->updated_at?->toIso8601String(),
                    'href' => route('workflows.show', $workflow).'?run='.$run->ref_id,
                    'answer' => route('workflow-runs.answer', [$run, $step->node_id]),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, Item>
     */
    private static function tickets(User $user, ?Project $project): array
    {
        return Ticket::query()
            ->where('assigned_to', $user->id)
            ->whereIn('status', [Ticket::OPEN, Ticket::IN_PROGRESS])
            ->when($project, fn (Builder $q) => $q->where('project_id', $project->id))
            ->with('project:id,ref_id,name')
            ->orderByRaw("case priority when 'high' then 0 when 'normal' then 1 else 2 end")
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (Ticket $ticket) => [
                'kind' => 'ticket',
                'title' => "#{$ticket->number} {$ticket->title}",
                'context' => self::where($ticket->project->name, $project, implode(' · ', array_filter([
                    $ticket->unit ?: null,
                    $ticket->status === Ticket::IN_PROGRESS ? 'in progress' : 'given to you',
                ]))),
                'at' => $ticket->updated_at?->toIso8601String(),
                'href' => route('projects.tickets.index', [$ticket->project, 'ticket' => $ticket->number]),
                'priority' => $ticket->priority,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, Item>
     */
    private static function chats(User $user, ?Project $project): array
    {
        return ChatRoom::query()
            ->visibleTo($user)
            ->when($project, fn (Builder $rooms) => $rooms->where('project_id', $project->id))
            ->with('project:id,name')
            ->withUnreadFor($user)
            ->latest('updated_at')
            ->limit(30)
            ->get()
            ->filter(fn (ChatRoom $room) => (int) $room->unread > 0)
            ->take(4)
            ->map(fn (ChatRoom $room) => [
                'kind' => 'chat',
                'title' => $room->titleFor($user).' · '.$room->unread.' unread',
                'context' => self::where($room->project?->name, $project, $room->kind === ChatRoom::DIRECT ? 'Message' : 'Group'),
                'at' => $room->updated_at?->toIso8601String(),
                'href' => route('chats.show', $room),
            ])
            ->values()
            ->all();
    }

    /**
     * The last run of each of the user's suites, where it failed lately.
     *
     * @return array<int, Item>
     */
    private static function failedChecks(User $user, ?Project $project): array
    {
        $suites = BrowserSuite::query()->where(fn (Builder $q) => $project
            ? $q->where('project_id', $project->id)
            : $q->where('user_id', $user->id)->orWhereIn('project_id', $user->projects()->select('projects.id')));

        return BrowserRun::query()
            ->whereIn('id', BrowserRun::query()
                ->whereIn('suite_id', $suites->select('id'))
                ->where('trial', false)
                ->whereIn('state', [BrowserRun::PASSED, BrowserRun::FAILED])
                ->groupBy('suite_id')
                ->selectRaw('max(id)'))
            ->where('state', BrowserRun::FAILED)
            ->where('ended_at', '>=', Carbon::now()->subHours(self::FAILED_WITHIN_HOURS))
            ->with('suite.project:id,ref_id,name')
            ->latest('ended_at')
            ->limit(3)
            ->get()
            ->map(fn (BrowserRun $run) => [
                'kind' => 'failed_check',
                'title' => ($run->suite->title ?: 'A suite').' failed',
                'context' => self::where($run->suite->project?->name, $project, 'Browser checks'),
                'at' => $run->ended_at?->toIso8601String(),
                'href' => self::checksUrl($run),
            ])
            ->values()
            ->all();
    }

    /**
     * Each workflow's latest real run, where it failed lately.
     *
     * @return array<int, Item>
     */
    private static function failedRuns(User $user, ?Project $project): array
    {
        return WorkflowRun::query()
            ->whereIn('id', WorkflowRun::query()
                ->whereIn('workflow_id', self::changeable($user, $project)->select('id'))
                ->whereHas('version', fn (Builder $v) => $v->where('kind', '!=', WorkflowVersion::TEST))
                ->whereIn('status', [RunStatus::Succeeded->value, RunStatus::Failed->value])
                ->groupBy('workflow_id')
                ->selectRaw('max(id)'))
            ->where('status', RunStatus::Failed->value)
            ->where('finished_at', '>=', Carbon::now()->subHours(self::FAILED_WITHIN_HOURS))
            ->with('workflow.project:id,name')
            ->latest('finished_at')
            ->limit(3)
            ->get()
            ->map(fn (WorkflowRun $run) => [
                'kind' => 'failed_run',
                'title' => ($run->workflow->title ?: 'A workflow').' failed',
                'context' => self::where($run->workflow->project?->name, $project, mb_strimwidth(trim((string) $run->error) ?: 'Workflows', 0, 90, '…')),
                'at' => $run->finished_at?->toIso8601String(),
                'href' => route('workflows.show', $run->workflow).'?run='.$run->ref_id,
            ])
            ->values()
            ->all();
    }

    /**
     * Where a thing is, said as a line: its project first when the
     * dashboard is not that project's own.
     */
    private static function where(?string $projectName, ?Project $project, string $rest): string
    {
        return implode(' · ', array_filter([$project ? null : $projectName, $rest]));
    }

    private static function checksUrl(BrowserRun $run): string
    {
        $suite = $run->suite;
        $base = $suite->project ? route('projects.checks.index', $suite->project) : route('checks.index');

        return $base.'?suite='.$suite->ref_id.'&run='.$run->ref_id;
    }
}
