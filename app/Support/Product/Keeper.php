<?php

namespace App\Support\Product;

use App\Models\Product\ProductElement;
use App\Models\Product\ProductLink;
use App\Models\Product\ProductRequest;
use App\Models\Product\Requirement;
use App\Models\Project;
use App\Models\User;
use App\Support\Live\Listed;
use Illuminate\Validation\ValidationException;

/**
 * What keeping a project's product takes, the same whoever asks -- the page
 * or an AI client over MCP: parts made with codes never given twice and a
 * history of what changed, never a part under itself, requests kept word for
 * word, and a requirement verified only once every criterion is met.
 * Arguments come in checked; what is wrong with them besides is refused here.
 */
final class Keeper
{
    /**
     * A new part of the product, at the end of what is under its parent.
     */
    public static function addPart(Project $project, User $by, string $kind, string $name, ?ProductElement $under = null, ?string $summary = null, bool $shared = false, ?string $status = null): ProductElement
    {
        $part = new ProductElement([
            'name' => trim($name),
            'summary' => $summary,
            'shared' => $shared,
            'parent_id' => $under?->id,
            'position' => (int) $project->productElements()->where('parent_id', $under?->id)->max('position') + 1,
        ]);
        $part->project_id = $project->id;
        $part->kind = $kind;
        $part->code = Codes::next($project, $kind === ProductElement::COMPONENT ? Codes::COMPONENT : Codes::CAPABILITY);

        if ($status !== null) {
            $part->status = $status;
        }

        $part->save();
        $part->note('made', $by, to: $part->name);

        return $part;
    }

    /**
     * Change a part: any of name, summary, status, shared, and -- with
     * "parent" given, null for the top -- where it sits. Each change is noted.
     *
     * @param  array{name?: string, summary?: ?string, status?: string, shared?: bool, parent?: ?ProductElement}  $changes
     */
    public static function changePart(ProductElement $part, User $by, array $changes): ProductElement
    {
        if (array_key_exists('parent', $changes)) {
            $parent = $changes['parent'];

            // Never under itself, nor under anything under it
            for ($at = $parent; $at !== null; $at = $at->parent) {
                if ($at->is($part)) {
                    throw ValidationException::withMessages(['parent' => __('A part cannot go under itself, or under what is under it.')]);
                }
            }

            if ($part->parent_id !== $parent?->id) {
                $part->note('moved', $by, $part->parent->name ?? 'the top', $parent->name ?? 'the top');
                $part->parent_id = $parent?->id;
                $part->position = (int) $part->project->productElements()->where('parent_id', $parent?->id)->max('position') + 1;
            }
        }

        foreach (['name' => 'renamed', 'status' => 'status', 'summary' => 'summary', 'shared' => 'shared'] as $field => $what) {
            if (array_key_exists($field, $changes) && $part->getAttribute($field) !== $changes[$field]) {
                $part->note($what, $by, self::said($part->getAttribute($field)), self::said($changes[$field]));
                $part->setAttribute($field, $changes[$field]);
            }
        }

        $part->save();

        return $part;
    }

    /**
     * Take a part away -- one with nothing under it. Its code is not given again.
     */
    public static function removePart(ProductElement $part): void
    {
        if ($part->children()->exists()) {
            throw ValidationException::withMessages(['element' => __(':code has parts under it: move or take them away first.', ['code' => $part->code])]);
        }

        $part->delete();
    }

    /**
     * What a part uses, depends on, extends, replaces or is related to.
     */
    public static function link(ProductElement $from, ProductElement $to, string $kind, User $by): void
    {
        if ($to->is($from)) {
            throw ValidationException::withMessages(['to' => __('A part is not linked to itself.')]);
        }

        $link = ProductLink::query()->firstOrCreate(['from_id' => $from->id, 'to_id' => $to->id, 'kind' => $kind]);

        if ($link->wasRecentlyCreated) {
            $from->note('linked', $by, str_replace('_', ' ', $kind), "{$to->code} {$to->name}");
        }
    }

    public static function unlink(ProductLink $link, User $by): void
    {
        $link->from->note('unlinked', $by, str_replace('_', ' ', $link->kind), "{$link->to->code} {$link->to->name}");
        $link->delete();
    }

    /**
     * Keep a request, word for word, with a code of its own.
     *
     * @param  array<string, mixed>  $source
     */
    public static function record(Project $project, User $by, string $original, string $kind, ?string $from, array $source = []): ProductRequest
    {
        return $project->productRequests()->create([
            'code' => Codes::next($project, Codes::REQUEST),
            'original' => $original,
            'source' => $kind,
            'status' => 'new',
            'from_name' => $from === null ? null : mb_substr($from, 0, 160),
            'created_by' => $by->id,
            ...$source,
        ]);
    }

    /**
     * A new requirement: from a request, which is then split, or as a change
     * to another requirement, which stays as it was.
     */
    public static function addRequirement(Project $project, User $by, string $title, ?string $need = null, ?string $kind = null, ?ProductRequest $from = null, ?Requirement $changes = null): Requirement
    {
        $requirement = $project->requirements()->create([
            'code' => Codes::next($project, Codes::REQUIREMENT),
            'product_request_id' => $from?->id,
            'changes_id' => $changes?->id,
            'title' => trim($title),
            'need' => $need,
            'kind' => $changes !== null ? 'change' : ($kind ?? 'new'),
            'created_by' => $by->id,
        ]);

        // A request worked out into requirements is split
        if ($from !== null && in_array($from->status, ['new', 'analysing'], true)) {
            $from->update(['status' => 'split']);
        }

        return $requirement;
    }

    /**
     * Change a requirement. Lists come whole: blanks are left out, not
     * refused. Parts (ids) and tickets (ids), when given, replace what it had.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function changeRequirement(Requirement $requirement, array $changes): Requirement
    {
        foreach (['questions', 'assumptions'] as $list) {
            if (array_key_exists($list, $changes)) {
                $changes[$list] = array_values(array_filter(array_map(fn ($one) => trim((string) $one), $changes[$list] ?? []))) ?: null;
            }
        }

        if (array_key_exists('criteria', $changes)) {
            $changes['criteria'] = array_values(array_filter(
                array_map(fn (array $one) => ['text' => trim((string) $one['text']), 'met' => (bool) ($one['met'] ?? false)], $changes['criteria'] ?? []),
                fn (array $one) => $one['text'] !== '',
            )) ?: null;
        }

        $requirement->fill(array_intersect_key($changes, array_flip(['title', 'need', 'body', 'kind', 'status', 'understanding', 'priority', 'owner_id', 'questions', 'assumptions', 'criteria'])));

        // Verified when what it is checked against is met -- never because the work is done
        if (in_array($requirement->status, ['verified', 'released'], true) && $requirement->isDirty(['status', 'criteria']) && ! $requirement->met()) {
            throw ValidationException::withMessages(['status' => __('It is verified once every criterion it is checked against is met: give it criteria, and tick them.')]);
        }

        $requirement->save();

        if (array_key_exists('elements', $changes)) {
            $requirement->elements()->sync($changes['elements']);
        }

        if (array_key_exists('tickets', $changes)) {
            $requirement->tickets()->sync($changes['tickets']);
        }

        // What it is checked against or done by is not a save of its own
        if (array_key_exists('elements', $changes) || array_key_exists('tickets', $changes)) {
            Listed::changed($requirement);
        }

        return $requirement;
    }

    /**
     * Who can own a requirement: an owner or an editor of its project.
     */
    public static function owner(Project $project, ?int $userId): ?int
    {
        if ($userId === null) {
            return null;
        }

        return $project->members()->wherePivotIn('role', [Project::OWNER, Project::EDITOR])->whereKey($userId)->value('users.id')
            ?? throw ValidationException::withMessages(['owner' => __('Only an owner or an editor of the project can own a requirement.')]);
    }

    private static function said(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };
    }
}
