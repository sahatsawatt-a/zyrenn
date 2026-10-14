<?php

namespace App\Support\Product;

use App\Models\Product\ProductElement;
use App\Models\Product\ProductLink;
use App\Models\Product\Requirement;
use App\Models\Project;
use App\Models\Ticket\Ticket;
use Illuminate\Support\Collection;

/**
 * What a project's product tracking says, worked out from what is kept:
 * how each part of the tree stands (a component by the capabilities under
 * it, counted, never as a percentage), what a change to a part would reach,
 * and where the trail from requirement to work to release has gaps.
 */
final class Tracker
{
    /** Requirement states that are past or out of the way. */
    private const SETTLED = ['verified', 'released'];

    /**
     * Every part, flat, each with how it stands and what is linked to it.
     *
     * @return list<array<string, mixed>>
     */
    public static function tree(Project $project): array
    {
        $elements = $project->productElements()
            ->withCount([
                'requirements as requirements' => fn ($query) => $query->where('status', '!=', 'dropped'),
                'tickets as work',
                'tickets as open_bugs' => fn ($query) => $query->where('type', Ticket::BUG)->whereIn('status', [Ticket::OPEN, Ticket::IN_PROGRESS]),
            ])
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $children = $elements->groupBy('parent_id');

        // A component counts the capabilities anywhere under it, by how they stand
        $counts = function (ProductElement $element) use (&$counts, $children): array {
            $tally = [];

            foreach ($children[$element->id] ?? [] as $child) {
                $add = $child->kind === ProductElement::CAPABILITY ? [$child->status => 1] : $counts($child);

                foreach ($add as $status => $n) {
                    $tally[$status] = ($tally[$status] ?? 0) + $n;
                }
            }

            return $tally;
        };

        return array_values($elements->map(fn (ProductElement $element) => [
            'ref_id' => $element->ref_id,
            'parent' => $element->parent_id === null ? null : $elements->firstWhere('id', $element->parent_id)?->ref_id,
            'kind' => $element->kind,
            'code' => $element->code,
            'name' => $element->name,
            'summary' => $element->summary,
            'status' => $element->status,
            'shared' => $element->shared,
            'counts' => $element->kind === ProductElement::COMPONENT ? (object) $counts($element) : null,
            'requirements' => (int) $element->getAttribute('requirements'),
            'work' => (int) $element->getAttribute('work'),
            'open_bugs' => (int) $element->getAttribute('open_bugs'),
        ])->all());
    }

    /**
     * What a change to a part would reach: what uses or depends on it --
     * and on those, a step further -- with their requirements still to be
     * settled and their tickets still open.
     *
     * @return array{direct: list<array<string, ?string>>, further: list<array<string, ?string>>, requirements: list<array<string, string>>, open_tickets: list<array<string, mixed>>}
     */
    public static function impact(ProductElement $element): array
    {
        $users = fn (Collection $ids) => ProductLink::query()->whereIn('to_id', $ids)->whereIn('kind', ['uses', 'depends_on', 'extends'])->pluck('from_id')->unique();

        $direct = $users(collect([$element->id]))->reject(fn (int $id) => $id === $element->id);
        $further = $users($direct)->reject(fn (int $id) => $id === $element->id || $direct->contains($id));
        $reached = $direct->merge($further)->push($element->id)->unique();

        // Each with the part it is under: several may be called "File in folders"
        $shown = fn (Collection $ids) => array_values(ProductElement::query()->with('parent:id,name')->whereIn('id', $ids)->orderBy('id')->get()
            ->map(fn (ProductElement $one) => [...$one->only(['ref_id', 'code', 'name']), 'under' => $one->parent?->name])->all());

        return [
            'direct' => $shown($direct),
            'further' => $shown($further),
            'requirements' => array_values(Requirement::query()
                ->whereHas('elements', fn ($query) => $query->whereIn('product_elements.id', $reached))
                ->whereNotIn('status', [...self::SETTLED, 'dropped'])
                ->orderBy('id')->get(['ref_id', 'code', 'title', 'status'])->map->only(['ref_id', 'code', 'title', 'status'])->all()),
            'open_tickets' => array_values(Ticket::query()
                ->whereHas('elements', fn ($query) => $query->whereIn('product_elements.id', $reached))
                ->whereIn('status', [Ticket::OPEN, Ticket::IN_PROGRESS])
                ->orderBy('number')->get(['number', 'type', 'title', 'status'])->map->only(['number', 'type', 'title', 'status'])->all()),
        ];
    }

    /**
     * For each capability, how far its trail goes -- a requirement, work on
     * it, its requirements verified, live -- and what is missing along it.
     *
     * @return array{rows: list<array<string, mixed>>, gaps: list<array{kind: string, code: string, name: string, ref_id: string, about: string}>}
     */
    public static function coverage(Project $project): array
    {
        $capabilities = $project->productElements()
            ->where('kind', ProductElement::CAPABILITY)
            ->where('status', '!=', 'retired')
            ->with(['requirements' => fn ($query) => $query->where('status', '!=', 'dropped'), 'tickets'])
            ->orderBy('id')
            ->get();

        $rows = [];
        $gaps = [];

        foreach ($capabilities as $capability) {
            $requirements = $capability->requirements;
            $required = $requirements->isNotEmpty();
            $worked = $capability->tickets->isNotEmpty();
            $verified = $required && $requirements->every(fn (Requirement $one) => in_array($one->status, self::SETTLED, true));
            $live = in_array($capability->status, ['live', 'issues'], true);
            $openBugs = $capability->tickets->filter(fn (Ticket $ticket) => $ticket->type === Ticket::BUG && ! $ticket->isClosed())->count();

            $rows[] = [
                'ref_id' => $capability->ref_id,
                'code' => $capability->code,
                'name' => $capability->name,
                'status' => $capability->status,
                'required' => $required,
                'worked' => $worked,
                'verified' => $verified,
                'live' => $live,
                'open_bugs' => $openBugs,
            ];

            // What is missing along its trail, each when it is so
            $missing = [
                'no_work' => [$required && ! $worked && ! $live, 'A requirement, and no work on it yet.'],
                'no_requirement' => [($worked || $live) && ! $required, $worked ? 'Work on it, and no requirement saying why.' : 'Live, and no requirement saying what it must do.'],
                'unverified' => [$live && $required && ! $verified, 'Live, but its requirements are not verified.'],
                'not_live' => [$verified && ! $live, 'Verified, but not live.'],
                'bugs' => [$live && $openBugs > 0, "Live with {$openBugs} open ".($openBugs === 1 ? 'bug' : 'bugs').'.'],
            ];

            foreach ($missing as $kind => [$so, $about]) {
                if ($so) {
                    $gaps[] = ['kind' => $kind, 'code' => $capability->code, 'name' => $capability->name, 'ref_id' => $capability->ref_id, 'about' => $about];
                }
            }
        }

        return ['rows' => $rows, 'gaps' => $gaps];
    }
}
