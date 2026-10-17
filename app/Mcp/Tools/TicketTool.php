<?php

namespace App\Mcp\Tools;

use App\Models\Project;
use App\Models\Ticket\Ticket;
use App\Models\User;
use App\Support\Tickets\Replay;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

/**
 * Base for the ticket tools. Tickets are a project's alone -- what it has
 * been asked to fix or to add -- so every call names its project: any member
 * reads them, its owners and editors file and change them.
 */
abstract class TicketTool extends UserTool
{
    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
            'project' => $schema->string()->max(255)->description('The project whose tickets these are, by ref_id or name (see list-projects).')->required(),
        ];
    }

    /**
     * The project the call names. $changes when it files or changes a ticket.
     */
    protected function targetProject(Request $request, bool $changes = false): Project
    {
        return $this->projectCalled($request, 'Tickets', $changes ? 'can file or change tickets' : null);
    }

    /**
     * One of the project's tickets, by the number people call it.
     */
    protected function ticketNumbered(Project $project, int $number): Ticket
    {
        $ticket = $project->tickets()->where('number', $number)->first();

        if (! $ticket) {
            throw ValidationException::withMessages([
                'number' => "\"{$project->name}\" has no ticket #{$number}. See list-tickets.",
            ]);
        }

        return $ticket;
    }

    /**
     * What a ticket may be given, as the tools take it.
     *
     * @return array<string, Type>
     */
    protected function ticketArguments(JsonSchema $schema, bool $filing): array
    {
        $type = $schema->string()->enum(Ticket::TYPES)->description('"bug" for something to fix, "feature" for something to add.');
        $title = $schema->string()->max(200)->description('What it is, in a line.');

        return [
            'type' => $filing ? $type->required() : $type,
            'title' => $filing ? $title->required() : $title,
            'body' => $schema->string()->max(20000)->description('For a bug: what was done, what happened, what was expected. For a feature: what it should do, and for whom. Plain text.'),
            'unit' => $schema->string()->max(64)->description('The part of the product it is about, in the project\'s own words, e.g. "Boards" (list-tickets shows the ones in use).'),
            'priority' => $schema->string()->enum(Ticket::PRIORITIES)->description('"normal" unless said otherwise.'),
            'assignee' => $schema->string()->max(255)->description('The email of the project member it is given to; "" for nobody.'),
            'pictures' => $schema->array()->items($schema->string()->max(2048))->description('Pictures and videos of it: each the "url" of a file in the project\'s Drive, e.g. "/drive/files/k3x9m2p7qa" (upload with upload-file or request-upload, with the same "project"), or an https link (one ending .mp4, .webm, .mov, .m4v or .ogv is a video). Replaces the ones it had.'),
            // How to make a bug happen again: optional, and a bug's alone in the app's form
            'steps' => $schema->array()->max(30)->items($schema->string()->max(500))->description('For a bug: the steps that make it happen, in order, one each (no numbers). [] clears them.'),
            'expected' => $schema->string()->max(2000)->description('For a bug: what should happen after those steps.'),
            'happens' => $schema->string()->max(2000)->description('For a bug: what happens instead.'),
            'check' => $schema->object([
                'suite' => $schema->string()->max(200)->description('The suite, by ref_id or title (list-browser-suites, with the same "project").')->required(),
                'check' => $schema->string()->max(200)->description('The check in it, by id or title.')->required(),
            ])->description('For a bug: the project\'s browser check that does those steps and passes once it is fixed -- run it with run-browser-suite "checks". null takes it off.'),
        ];
    }

    /**
     * The same, checked, as the columns a ticket keeps them in.
     *
     * @return array<string, mixed>
     */
    protected function ticketDetails(Request $request, Project $project, bool $filing, ?Ticket $ticket = null): array
    {
        $needed = $filing ? 'required' : 'sometimes';

        $validated = $request->validate([
            'type' => [$needed, 'in:'.implode(',', Ticket::TYPES)],
            'title' => [$needed, 'string', 'max:200'],
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:64'],
            'priority' => ['sometimes', 'in:'.implode(',', Ticket::PRIORITIES)],
            'status' => ['sometimes', 'in:'.implode(',', Ticket::STATUSES)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'assignee' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pictures' => ['sometimes', 'array', 'max:12'],
            'pictures.*' => ['string', 'max:2048', 'regex:#^(/drive/files/[A-Za-z0-9]+|https?://\S+)$#'],
            'steps' => ['sometimes', 'nullable', 'array', 'max:30'],
            'steps.*' => ['nullable', 'string', 'max:500'],
            'expected' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'happens' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'check' => ['sometimes', 'nullable', 'array'],
            'check.suite' => ['required_with:check', 'string', 'max:200'],
            'check.check' => ['required_with:check', 'string', 'max:200'],
        ], [
            'pictures.*.regex' => 'A picture or video is a file in the Drive, as "/drive/files/<ref_id>", or an http(s) link.',
        ]);

        $details = array_intersect_key($validated, array_flip(['type', 'title', 'body', 'priority', 'status', 'reason']));

        if (array_key_exists('unit', $validated)) {
            $details['unit'] = trim((string) $validated['unit']);
        }

        if (array_key_exists('assignee', $validated)) {
            $details['assigned_to'] = $this->memberWith($project, trim((string) $validated['assignee']))?->id;
        }

        if (array_key_exists('pictures', $validated)) {
            $details['pictures'] = Ticket::media(array_map(
                fn (string $src) => ['src' => $src, 'name' => basename((string) parse_url($src, PHP_URL_PATH))],
                array_values($validated['pictures']),
            ));
        }

        // Each of the three said alone keeps the other two as they were
        $repro = array_intersect_key($validated, array_flip(['steps', 'expected', 'happens']));

        if ($repro !== []) {
            $details['repro'] = Ticket::repro($repro + ($ticket->repro ?? []));
        }

        if (array_key_exists('check', $validated)) {
            $details += Replay::find($project, $validated['check']['suite'] ?? null, $validated['check']['check'] ?? null);
        }

        return $details;
    }

    /**
     * The bug's browser check as a tool tells of it: no page addresses, the
     * ids run-browser-suite takes.
     *
     * @return array<string, mixed>|null
     */
    private function replay(Ticket $ticket): ?array
    {
        $check = Replay::described($ticket);

        if ($check === null || $check['gone']) {
            return $check === null ? null : ['gone' => true];
        }

        $run = $check['run'];

        return [
            'suite' => $check['suite'],
            'suite_title' => $check['suite_title'],
            'check' => $check['check'],
            'title' => $check['title'],
            'last_run' => $run === null ? null : [
                'ref_id' => $run['ref_id'],
                'state' => $run['state'],
                'still_happens' => $run['ok'] === null ? null : ! $run['ok'],
                'verdict' => $run['verdict'],
                'film' => $run['video']['url'] ?? null,
            ],
        ];
    }

    /**
     * The project member with that email; nobody, for "".
     */
    private function memberWith(Project $project, string $email): ?User
    {
        if ($email === '') {
            return null;
        }

        $member = $project->members()->where('email', $email)->first();

        if (! $member) {
            throw ValidationException::withMessages([
                'assignee' => "Nobody in \"{$project->name}\" has the email {$email}.",
            ]);
        }

        return $member;
    }

    /**
     * A ticket as a tool answers with it: in full, or as a line of a list.
     *
     * @return array<string, mixed>
     */
    protected function described(Ticket $ticket, bool $full = true): array
    {
        $line = [
            'number' => $ticket->number,
            'type' => $ticket->type,
            'status' => $ticket->status,
            'reason' => $ticket->reason,
            'priority' => $ticket->priority,
            'title' => $ticket->title,
            'unit' => $ticket->unit,
            'assignee' => $ticket->assignee?->email,
            'pictures' => count(array_filter($ticket->pictures ?? [], fn (array $one) => ($one['kind'] ?? 'image') !== 'video')),
            'videos' => count(array_filter($ticket->pictures ?? [], fn (array $one) => ($one['kind'] ?? 'image') === 'video')),
            'replayable' => $ticket->browser_check !== null,
            'updated_at' => $ticket->updated_at?->toIso8601String(),
        ];

        if (! $full) {
            return $line;
        }

        return [
            ...$line,
            'ref_id' => $ticket->ref_id,
            'body' => $ticket->body ?? '',
            // How to make it happen again, and the check that does
            'repro' => $ticket->repro,
            'check' => $this->replay($ticket),
            'pictures' => array_column(array_filter($ticket->pictures ?? [], fn (array $one) => ($one['kind'] ?? 'image') !== 'video'), 'src'),
            'videos' => array_column(array_filter($ticket->pictures ?? [], fn (array $one) => ($one['kind'] ?? 'image') === 'video'), 'src'),
            'filed_by' => $ticket->creator?->name,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'url' => route('projects.tickets.index', [$ticket->project, 'ticket' => $ticket->number]),
        ];
    }
}
