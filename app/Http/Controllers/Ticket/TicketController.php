<?php

namespace App\Http\Controllers\Ticket;

use App\Http\Controllers\Controller;
use App\Models\Browser\BrowserSuite;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use App\Support\Tickets\Replay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A project's tickets: everyone in it reads them, its owners and editors file
 * them and move them along.
 */
class TicketController extends Controller
{
    /**
     * Every ticket of the project, newest first. The page sorts them into a
     * list or onto a board itself.
     */
    public function index(Project $project): Response
    {
        Gate::authorize('view', $project);

        $tickets = $project->tickets()
            ->with(['creator:id,name', 'assignee:id,name', 'room:id,ref_id', 'suite'])
            ->orderByDesc('number')
            ->get();

        return Inertia::render('tickets/Index', [
            'tickets' => $tickets->map(fn (Ticket $ticket) => $ticket->summary())->values(),
            // Who one can be given to: anyone in the project
            'people' => $project->members()->orderBy('name')->get()
                ->map(fn (User $person) => $person->only(['id', 'name']))
                ->values(),
            // The project's browser checks, one of which a bug may name as
            // the way to make it happen again
            'suites' => $project->browserSuites()->orderBy('title')->get()
                ->map(fn (BrowserSuite $suite) => [
                    'ref_id' => $suite->ref_id,
                    'title' => $suite->title ?: 'Untitled suite',
                    'checks' => array_map(fn (array $check) => [
                        'id' => $check['id'],
                        'title' => trim((string) $check['title']) ?: 'Untitled check',
                    ], $suite->checks),
                ])
                ->values(),
        ]);
    }

    /**
     * File a ticket.
     */
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('contribute', $project);

        $ticket = Ticket::file($project, $request->user(), $this->validated($request, $project, filing: true));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket #:number filed.', ['number' => $ticket->number])]);

        return back();
    }

    /**
     * Change a ticket: what it says, who has it, how far along it is.
     */
    public function update(Request $request, Project $project, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('contribute', $project);

        $ticket->update($this->validated($request, $project));

        return back();
    }

    /**
     * Delete a ticket. What was said about it stays, as the group it was said in.
     */
    public function destroy(Project $project, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('contribute', $project);

        $ticket->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Ticket deleted.')]);

        return back();
    }

    /**
     * Run the bug's browser check, to see whether it still happens. The page
     * follows the run from there.
     */
    public function replay(Request $request, Project $project, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('contribute', $project);

        Replay::start($ticket, $request->user());

        return back();
    }

    /**
     * Open the group the ticket is talked over in, starting it if nobody has.
     */
    public function discuss(Request $request, Project $project, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('view', $project);

        return to_route('chats.show', $ticket->discussion($request->user()));
    }

    /**
     * What a ticket may be given. Filing needs a type and a title; a change
     * names only what changes.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, Project $project, bool $filing = false): array
    {
        $needed = $filing ? 'required' : 'sometimes';

        $validated = $request->validate([
            'type' => [$needed, Rule::in(Ticket::TYPES)],
            'title' => [$needed, 'string', 'max:200'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:64'],
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            // How to make it happen again: steps, what should happen, what does
            'repro' => ['sometimes', 'nullable', 'array'],
            'repro.steps' => ['sometimes', 'nullable', 'array', 'max:30'],
            'repro.steps.*' => ['nullable', 'string', 'max:500'],
            'repro.expected' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'repro.happens' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // And a browser check of the project's that does it
            'check' => ['sometimes', 'nullable', 'array'],
            'check.suite' => ['required_with:check', 'string', 'max:32'],
            'check.check' => ['required_with:check', 'string', 'max:16'],
            'status' => ['sometimes', Rule::in(Ticket::STATUSES)],
            // What it waits on, or why it will not be done
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'priority' => ['sometimes', Rule::in(Ticket::PRIORITIES)],
            // Only someone in the project can be given it
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::in($project->members()->pluck('users.id'))],
            // Pictures and videos, in the Drive or somewhere on the web
            'pictures' => ['sometimes', 'array', 'max:'.Ticket::MEDIA],
            'pictures.*.src' => ['required', 'string', 'max:2048', 'regex:#^(/drive/files/[A-Za-z0-9]+|https?://\S+)$#'],
            'pictures.*.name' => ['nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('unit', $validated)) {
            $validated['unit'] = trim((string) $validated['unit']);
        }

        if (array_key_exists('pictures', $validated)) {
            $validated['pictures'] = Ticket::media(array_values($validated['pictures']));
        }

        if (array_key_exists('repro', $validated)) {
            $validated['repro'] = Ticket::repro($validated['repro']);
        }

        if (array_key_exists('check', $validated)) {
            $check = $validated['check'];
            unset($validated['check']);
            $validated += Replay::find($project, $check['suite'] ?? null, $check['check'] ?? null);
        }

        return $validated;
    }
}
