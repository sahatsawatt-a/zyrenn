<?php

namespace App\Mcp\Tools\Product;

use App\Mcp\Tools\ProductTool;
use App\Models\Product\ProductElement;
use App\Models\Product\ProductLink;
use App\Models\Project;
use App\Models\User;
use App\Support\Live\Listed;
use App\Support\Product\Keeper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Change a product tree')]
#[Description('Change a project\'s product tree in one call, all of it or none: "add" parts, "update" them, "link" and "unlink" what they use or depend on, link "tickets" to them as the work on them, and "remove" parts with nothing under them. Done in that order. A part added here can be named by its "key" in the same call, before it has a code -- to put parts under it, or link to it -- so a whole tree can be laid out at once. A component is a part of the product (a screen, a module, a service); a capability is something it lets someone do, under a component. Codes are given, never chosen, and never given again. Answers with the codes given and the parts it touched, as they are after; get-product reads the whole tree.')]
class UpdateProduct extends ProductTool
{
    protected function arguments(JsonSchema $schema): array
    {
        $part = fn (string $what) => $schema->string()->max(64)->description($what);
        $status = $schema->string()->enum(ProductElement::STATUSES)->description('How a capability stands: planned, building, partial, verifying, live, issues (live, with known problems), blocked, retired.');

        return [
            'add' => $schema->array()->max(200)->items($schema->object([
                'key' => $part('A name for it within this call, to put other new parts under it or link to it, e.g. "board".'),
                'kind' => $schema->string()->enum([ProductElement::COMPONENT, ProductElement::CAPABILITY])->required(),
                'name' => $schema->string()->max(160)->required(),
                'under' => $part('The code of the part it goes under, or the key of one added in this call; left out, at the top.'),
                'summary' => $schema->string()->max(5000)->description('What it does today, in a sentence or two.'),
                'status' => $status,
                'shared' => $schema->boolean()->description('Built once and used from several places, e.g. a colour picker.'),
            ]))->description('Parts to add.'),
            'update' => $schema->array()->max(200)->items($schema->object([
                'code' => $part('The part\'s code.')->required(),
                'name' => $schema->string()->max(160),
                'summary' => $schema->string()->max(5000),
                'status' => $status,
                'shared' => $schema->boolean(),
                'under' => $part('Move it under this part (code or key); "" for the top.'),
            ]))->description('Parts to change, sending only what changes.'),
            'link' => $schema->array()->max(200)->items($schema->object([
                'from' => $part('The part that uses, depends on, ... (code or key).')->required(),
                'kind' => $schema->string()->enum(ProductElement::LINKS)->description('uses, depends_on, extends, replaces or related.')->required(),
                'to' => $part('The part it uses, depends on, ... (code or key).')->required(),
            ]))->description('Links to make, apart from where parts sit in the tree.'),
            'unlink' => $schema->array()->max(200)->items($schema->object([
                'from' => $part('Code.')->required(),
                'kind' => $schema->string()->enum(ProductElement::LINKS)->required(),
                'to' => $part('Code.')->required(),
            ]))->description('Links to take away.'),
            'tickets' => $schema->array()->max(200)->items($schema->object([
                'code' => $part('The part (code or key).')->required(),
                'number' => $schema->integer()->description('The ticket\'s number in the project (see list-tickets).')->required(),
                'linked' => $schema->boolean()->description('false to take the ticket off the part; true by default.'),
            ]))->description('Tickets that are work on a part -- a bug in it, a feature for it.'),
            'remove' => $schema->array()->max(200)->items($schema->string()->max(16))->description('Codes of parts to take away; each must have nothing under it.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $project = $this->targetProject($request, changes: true);
        $user = $this->targetUser($request);

        $validated = $request->validate([
            'add' => ['sometimes', 'array', 'max:200'],
            'add.*.key' => ['sometimes', 'nullable', 'string', 'max:64', 'distinct'],
            'add.*.kind' => ['required', Rule::in([ProductElement::COMPONENT, ProductElement::CAPABILITY])],
            'add.*.name' => ['required', 'string', 'max:160'],
            'add.*.under' => ['sometimes', 'nullable', 'string', 'max:64'],
            'add.*.summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'add.*.status' => ['sometimes', Rule::in(ProductElement::STATUSES)],
            'add.*.shared' => ['sometimes', 'boolean'],
            'update' => ['sometimes', 'array', 'max:200'],
            'update.*.code' => ['required', 'string', 'max:64'],
            'update.*.name' => ['sometimes', 'required', 'string', 'max:160'],
            'update.*.summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'update.*.status' => ['sometimes', Rule::in(ProductElement::STATUSES)],
            'update.*.shared' => ['sometimes', 'boolean'],
            'update.*.under' => ['sometimes', 'nullable', 'string', 'max:64'],
            'link' => ['sometimes', 'array', 'max:200'],
            'link.*.from' => ['required', 'string', 'max:64'],
            'link.*.kind' => ['required', Rule::in(ProductElement::LINKS)],
            'link.*.to' => ['required', 'string', 'max:64'],
            'unlink' => ['sometimes', 'array', 'max:200'],
            'unlink.*.from' => ['required', 'string', 'max:64'],
            'unlink.*.kind' => ['required', Rule::in(ProductElement::LINKS)],
            'unlink.*.to' => ['required', 'string', 'max:64'],
            'tickets' => ['sometimes', 'array', 'max:200'],
            'tickets.*.code' => ['required', 'string', 'max:64'],
            'tickets.*.number' => ['required', 'integer', 'min:1'],
            'tickets.*.linked' => ['sometimes', 'boolean'],
            'remove' => ['sometimes', 'array', 'max:200'],
            'remove.*' => ['string', 'max:16'],
        ]);

        [$given, $touched] = DB::transaction(fn () => $this->apply($project, $user, $validated));

        // The parts this call touched, as they are now; get-product reads the whole tree
        $after = (new GetProduct)->tree($project, only: $touched);

        return Response::structured([
            'given' => (object) $given,
            'parts' => $after['parts'],
            'links' => $after['links'],
            'gaps' => $after['gaps'],
            'removed' => array_values(array_map('mb_strtoupper', $validated['remove'] ?? [])),
            'url' => $after['url'],
        ]);
    }

    /**
     * Each change in turn. What was added, as key => code, and the codes of
     * every part touched.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function apply(Project $project, User $user, array $validated): array
    {
        /** @var array<string, ProductElement> $keyed */
        $keyed = [];
        $given = [];

        $touched = [];

        // A part named by a key given in this call, or by its code
        $find = function (string $named, string $argument) use ($project, &$keyed, &$touched): ProductElement {
            $part = $keyed[$named] ?? $this->partCoded($project, $named, $argument);
            $touched[] = $part->code;

            return $part;
        };

        foreach ($validated['add'] ?? [] as $at => $one) {
            $under = filled($one['under'] ?? null) ? $find($one['under'], "add.{$at}.under") : null;

            $part = Keeper::addPart($project, $user, $one['kind'], $one['name'], $under, $one['summary'] ?? null, (bool) ($one['shared'] ?? false), $one['status'] ?? null);
            $given[$one['key'] ?? $part->name] = $part->code;
            $touched[] = $part->code;

            if (filled($one['key'] ?? null)) {
                $keyed[$one['key']] = $part;
            }
        }

        foreach ($validated['update'] ?? [] as $at => $one) {
            $changes = array_intersect_key($one, array_flip(['name', 'summary', 'status', 'shared']));

            if (array_key_exists('under', $one)) {
                $changes['parent'] = filled($one['under']) ? $find($one['under'], "update.{$at}.under") : null;
            }

            Keeper::changePart($find($one['code'], "update.{$at}.code"), $user, $changes);
        }

        foreach ($validated['link'] ?? [] as $at => $one) {
            Keeper::link($find($one['from'], "link.{$at}.from"), $find($one['to'], "link.{$at}.to"), $one['kind'], $user);
        }

        foreach ($validated['unlink'] ?? [] as $at => $one) {
            $link = ProductLink::query()->where([
                'from_id' => $find($one['from'], "unlink.{$at}.from")->id,
                'to_id' => $find($one['to'], "unlink.{$at}.to")->id,
                'kind' => $one['kind'],
            ])->first() ?? throw ValidationException::withMessages(["unlink.{$at}" => "{$one['from']} does not {$one['kind']} {$one['to']}."]);

            Keeper::unlink($link, $user);
        }

        foreach ($validated['tickets'] ?? [] as $at => $one) {
            $ticket = $project->tickets()->where('number', $one['number'])->first()
                ?? throw ValidationException::withMessages(["tickets.{$at}.number" => "\"{$project->name}\" has no ticket #{$one['number']}. See list-tickets."]);
            $part = $find($one['code'], "tickets.{$at}.code");

            ($one['linked'] ?? true) ? $part->tickets()->syncWithoutDetaching([$ticket->id]) : $part->tickets()->detach($ticket->id);
            Listed::changed($part);
        }

        foreach ($validated['remove'] ?? [] as $at => $code) {
            Keeper::removePart($this->partCoded($project, $code, "remove.{$at}"));
        }

        return [$given, array_values(array_unique($touched))];
    }
}
