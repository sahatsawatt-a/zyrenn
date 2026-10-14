<?php

namespace App\Mcp\Tools\Product;

use App\Mcp\Tools\ProductTool;
use App\Models\Product\Requirement;
use App\Support\Product\Keeper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Save a requirement')]
#[Description('Write a requirement of a project\'s product (REQ-n): left without "code", a new one -- from a request ("request": RQ-n) or as a change to another requirement ("changes": REQ-n, which stays as it was) -- or, with "code", change one, sending only what changes. Lists ("criteria", "questions", "assumptions", "parts", "tickets") come whole and replace what it had. It is "verified" or "released" only once every criterion is met: tick them ("met": true) as they are checked, never because the work is done. Do not guess what was not said: put it in "questions", or in "assumptions" with "understanding" "partial".')]
class SaveRequirement extends ProductTool
{
    protected function arguments(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->max(16)->description('The requirement to change, e.g. "REQ-9". Left out, a new one is made.'),
            'title' => $schema->string()->max(200)->description('What the product must do, in a line. Needed for a new one.'),
            'need' => $schema->string()->max(5000)->description('The problem behind it: who needs it, and why.'),
            'body' => $schema->string()->max(20000)->description('More about it, plain text.'),
            'kind' => $schema->string()->enum(Requirement::KINDS)->description('new (the default), additional, change, constraint or quality.'),
            'status' => $schema->string()->enum(Requirement::STATUSES)->description('draft, clarifying, ready, building, verifying, verified, released, dropped.'),
            'understanding' => $schema->string()->enum(Requirement::UNDERSTANDING)->description('How well it is understood: unknown, partial, confirmed.'),
            'priority' => $schema->string()->enum(Requirement::PRIORITIES)->description('low, normal, high.'),
            'owner' => $schema->string()->max(255)->description('The email of the project owner or editor who owns it; "" for nobody.'),
            'criteria' => $schema->array()->max(50)->items($schema->object([
                'text' => $schema->string()->max(500)->description('Something that can be checked, e.g. "Typing #RRGGBB sets the colour".')->required(),
                'met' => $schema->boolean()->description('Checked and found to hold.'),
            ]))->description('What it is checked against: acceptance criteria.'),
            'questions' => $schema->array()->max(50)->items($schema->string()->max(500))->description('What is still to be asked.'),
            'assumptions' => $schema->array()->max(50)->items($schema->string()->max(500))->description('What is assumed and not confirmed.'),
            'parts' => $schema->array()->max(100)->items($schema->string()->max(16))->description('Codes of the parts it is about.'),
            'tickets' => $schema->array()->max(100)->items($schema->integer())->description('Numbers of the tickets that are the work for it.'),
            'request' => $schema->string()->max(16)->description('For a new one: the request it is worked out from, e.g. "RQ-4".'),
            'changes' => $schema->string()->max(16)->description('For a new one: the requirement it changes, e.g. "REQ-2".'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $project = $this->targetProject($request, changes: true);
        $user = $this->targetUser($request);

        $validated = $request->validate([
            'code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'need' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'kind' => ['sometimes', 'in:'.implode(',', Requirement::KINDS)],
            'status' => ['sometimes', 'in:'.implode(',', Requirement::STATUSES)],
            'understanding' => ['sometimes', 'in:'.implode(',', Requirement::UNDERSTANDING)],
            'priority' => ['sometimes', 'in:'.implode(',', Requirement::PRIORITIES)],
            'owner' => ['sometimes', 'nullable', 'string', 'max:255'],
            'criteria' => ['sometimes', 'array', 'max:50'],
            'criteria.*.text' => ['required', 'string', 'max:500'],
            'criteria.*.met' => ['sometimes', 'boolean'],
            'questions' => ['sometimes', 'array', 'max:50'],
            'questions.*' => ['nullable', 'string', 'max:500'],
            'assumptions' => ['sometimes', 'array', 'max:50'],
            'assumptions.*' => ['nullable', 'string', 'max:500'],
            'parts' => ['sometimes', 'array', 'max:100'],
            'parts.*' => ['string', 'max:16'],
            'tickets' => ['sometimes', 'array', 'max:100'],
            'tickets.*' => ['integer', 'min:1'],
            'request' => ['sometimes', 'nullable', 'string', 'max:16'],
            'changes' => ['sometimes', 'nullable', 'string', 'max:16'],
        ]);

        $changes = $validated;

        if (array_key_exists('owner', $changes)) {
            $email = trim((string) $changes['owner']);
            $changes['owner_id'] = Keeper::owner($project, $email === '' ? null : ($project->members()->where('email', $email)->value('users.id')
                ?? throw ValidationException::withMessages(['owner' => "Nobody in \"{$project->name}\" has the email {$email}."])));
        }

        if (array_key_exists('parts', $changes)) {
            $changes['elements'] = array_map(fn (string $code) => $this->partCoded($project, $code, 'parts')->id, $changes['parts']);
        }

        if (array_key_exists('tickets', $changes)) {
            $changes['tickets'] = array_map(fn (int $number) => $project->tickets()->where('number', $number)->value('id')
                ?? throw ValidationException::withMessages(['tickets' => "\"{$project->name}\" has no ticket #{$number}. See list-tickets."]), $changes['tickets']);
        }

        $requirement = DB::transaction(function () use ($project, $user, $validated, $changes) {
            if (filled($validated['code'] ?? null)) {
                foreach (['request', 'changes'] as $fixed) {
                    if (filled($validated[$fixed] ?? null)) {
                        throw ValidationException::withMessages([$fixed => "\"{$fixed}\" is said when a requirement is made, not changed afterwards."]);
                    }
                }

                $requirement = $this->requirementCoded($project, $validated['code']);
            } else {
                if (blank($validated['title'] ?? null)) {
                    throw ValidationException::withMessages(['title' => 'A new requirement needs a "title": what the product must do, in a line.']);
                }

                $requirement = Keeper::addRequirement(
                    $project,
                    $user,
                    $validated['title'],
                    $validated['need'] ?? null,
                    $validated['kind'] ?? null,
                    filled($validated['request'] ?? null) ? $this->requestCoded($project, $validated['request'], 'request') : null,
                    filled($validated['changes'] ?? null) ? $this->requirementCoded($project, $validated['changes'], 'changes') : null,
                );
            }

            return Keeper::changeRequirement($requirement, $changes);
        });

        return Response::structured([
            ...$this->requirementShown($requirement->refresh()),
            'url' => $this->productUrl($project),
        ]);
    }
}
