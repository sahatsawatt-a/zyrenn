<?php

namespace App\Support\Workflow\Steps;

use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * What the Tickets steps share: the project they work in -- the workflow's
 * own, or for one's own workflow a project chosen, where whoever it acts as
 * may change things -- and a ticket given out the same way each time.
 */
final class TicketSteps
{
    /** What a ticket step gives out before it has one: each part as nothing. */
    public const EMPTY = ['number' => null, 'ref_id' => null, 'type' => null, 'status' => null, 'reason' => null, 'priority' => null, 'title' => null, 'unit' => null, 'assignee' => null, 'url' => null];

    /**
     * @throws StepFailed when there is no such project, or it is not one to change
     */
    public static function project(StepContext $step): Project
    {
        $owner = $step->owner();
        $named = trim((string) $step->setting('project'));

        if ($owner instanceof Project) {
            // A project's workflow works in its own project, and no other
            if ($named !== '' && $named !== $owner->ref_id) {
                throw new StepFailed('A project\'s workflow works only with its own project\'s tickets.');
            }

            return $owner;
        }

        if ($named === '') {
            throw new StepFailed('Choose the project whose tickets these are.');
        }

        $actor = $step->actor();
        $project = $actor->projects()->where('projects.ref_id', $named)->first()
            ?? $actor->projects()->whereRaw('lower(projects.name) = ?', [mb_strtolower($named)])->first();

        if (! $project instanceof Project) {
            throw new StepFailed("There is no project \"{$named}\" this workflow can work in.");
        }

        if (! in_array($project->roleOf($actor), [Project::OWNER, Project::EDITOR], true)) {
            throw new StepFailed("Only an owner or editor of \"{$project->name}\" changes its tickets.");
        }

        return $project;
    }

    /** The project's ticket of that number. */
    public static function ticket(StepContext $step, Project $project): Ticket
    {
        $number = trim((string) $step->template('number'));

        if (! ctype_digit(ltrim($number, '#'))) {
            throw new StepFailed("\"{$number}\" is not a ticket's number.");
        }

        return $project->tickets()->where('number', (int) ltrim($number, '#'))->first()
            ?? throw new StepFailed("\"{$project->name}\" has no ticket #".ltrim($number, '#').'.');
    }

    /**
     * Whom a ticket is given to, by their email: "" for nobody.
     *
     * @throws StepFailed when nobody in the project has that email
     */
    public static function member(Project $project, string $email): ?User
    {
        $email = trim($email);

        if ($email === '') {
            return null;
        }

        return $project->members()->where('email', $email)->first()
            ?? throw new StepFailed("Nobody in \"{$project->name}\" has the email {$email}.");
    }

    /**
     * Saved, or the step failing with what the ticket said was wrong -- such
     * as no word on why it is put on hold.
     */
    public static function saved(callable $save): Ticket
    {
        try {
            return $save();
        } catch (ValidationException $problem) {
            throw new StepFailed((string) collect($problem->errors())->flatten()->first());
        }
    }

    /** @return array<string, mixed> */
    public static function out(Ticket $ticket): array
    {
        return [
            'number' => $ticket->number,
            'ref_id' => $ticket->ref_id,
            'type' => $ticket->type,
            'status' => $ticket->status,
            'reason' => $ticket->reason,
            'priority' => $ticket->priority,
            'title' => $ticket->title,
            'unit' => $ticket->unit,
            'assignee' => $ticket->assignee?->email,
            'url' => route('projects.tickets.index', ['project' => $ticket->project, 'ticket' => $ticket->number]),
            // When it came, last changed, and was done or turned down
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
        ];
    }
}
