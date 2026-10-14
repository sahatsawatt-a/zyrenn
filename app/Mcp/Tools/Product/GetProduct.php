<?php

namespace App\Mcp\Tools\Product;

use App\Mcp\Tools\ProductTool;
use App\Models\Product\ProductElement;
use App\Models\Product\ProductLink;
use App\Models\Project;
use App\Support\Product\Tracker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Title('Get a product tree')]
#[Description('Read a project\'s product as a tree, in order: components (CMP-n) and the capabilities (CAP-n) under them, each with its status and -- when it has any -- requirements, work (tickets) and open bugs; a component counts the capabilities under it by status. Also what each part uses or depends on ("links"), and the gaps coverage finds, the most pressing first (at most 20, with "gaps_by_kind" counting them all) -- live with open bugs, live but unverified, a requirement with no work, verified but not live, work or a live part with no requirement. "under" reads one part and what is under it. get-product-part reads one part in full.')]
class GetProduct extends ProductTool
{
    /** Gaps by how pressing they are: a live part with open bugs first. */
    private const PRESSING = ['bugs', 'unverified', 'no_work', 'not_live', 'no_requirement'];

    private const GAPS_SHOWN = 20;

    protected function arguments(JsonSchema $schema): array
    {
        return [
            'under' => $schema->string()->max(16)->description('Only this part (its code, e.g. "CMP-2") and what is under it.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $project = $this->targetProject($request);
        $validated = $request->validate(['under' => ['sometimes', 'nullable', 'string', 'max:16']]);
        $top = filled($validated['under'] ?? null) ? $this->partCoded($project, (string) $validated['under'], 'under') : null;

        return Response::structured([
            'project' => $project->only(['ref_id', 'name']),
            ...$this->tree($project, $top),
        ]);
    }

    /**
     * The tree as this tool reads it, from the top or from one part -- or,
     * given $only, just those parts, in tree order.
     *
     * @param  list<string>|null  $only  codes
     * @return array<string, mixed>
     */
    public function tree(Project $project, ?ProductElement $top = null, ?array $only = null): array
    {
        $parts = collect(Tracker::tree($project));
        /** @var array<string, list<array<string, mixed>>> $children */
        $children = [];

        foreach ($parts as $part) {
            $children[(string) ($part['parent'] ?? '')][] = $part;
        }

        $codeOf = $parts->pluck('code', 'ref_id');

        // In order, each after the one it is under, with how deep it is
        $outline = [];
        $walk = function (string $parent, int $depth) use (&$walk, &$outline, &$children, $codeOf): void {
            foreach ($children[$parent] ?? [] as $part) {
                $outline[] = array_filter([
                    'code' => $part['code'],
                    'kind' => $part['kind'],
                    'name' => $part['name'],
                    'status' => $part['kind'] === 'capability' ? $part['status'] : null,
                    'under' => $part['parent'] === null ? null : $codeOf[$part['parent']],
                    'depth' => $depth,
                    'shared' => $part['shared'] ?: null,
                    'capabilities' => $part['counts'] === null || (array) $part['counts'] === [] ? null : (array) $part['counts'],
                    'requirements' => $part['requirements'] ?: null,
                    'work' => $part['work'] ?: null,
                    'open_bugs' => $part['open_bugs'] ?: null,
                ], fn ($value) => $value !== null);
                $walk($part['ref_id'], $depth + 1);
            }
        };

        if ($top !== null) {
            $first = $parts->firstWhere('ref_id', $top->ref_id);
            $children['@'] = [$first];
            $walk('@', 0);
        } else {
            $walk('', 0);
        }

        if ($only !== null) {
            $outline = array_values(array_filter($outline, fn (array $part) => in_array($part['code'] ?? null, $only, true)));
        }

        $shown = array_column($outline, 'code');
        $coverage = Tracker::coverage($project);

        // A capability's name with the component it is under: several may be called "File in folders"
        $names = $parts->pluck('name', 'ref_id');
        $where = $parts->mapWithKeys(fn (array $part) => [$part['code'] => $part['parent'] === null ? '' : " ({$names[$part['parent']]})"]);

        $gaps = array_values(array_filter($coverage['gaps'], fn (array $gap) => in_array($gap['code'], $shown, true)));
        usort($gaps, fn (array $a, array $b) => array_search($a['kind'], self::PRESSING, true) <=> array_search($b['kind'], self::PRESSING, true));

        return [
            'parts' => $outline,
            'links' => ProductLink::query()->with(['from:id,code', 'to:id,code'])
                ->whereHas('from', fn ($query) => $query->where('project_id', $project->id))
                ->get()
                ->filter(fn (ProductLink $link) => in_array($link->from->code, $shown, true) || in_array($link->to->code, $shown, true))
                ->map(fn (ProductLink $link) => "{$link->from->code} {$link->kind} {$link->to->code}")
                ->values()->all(),
            // The most pressing first, and no more than a screen of them
            'gaps' => array_map(fn (array $gap) => "{$gap['code']} {$gap['name']}{$where[$gap['code']]}: {$gap['about']}", array_slice($gaps, 0, self::GAPS_SHOWN)),
            'gaps_by_kind' => (object) array_count_values(array_column($gaps, 'kind')),
            'requests_open' => $project->productRequests()->whereIn('status', ['new', 'analysing', 'clarifying'])->count(),
            'requirements_open' => $project->requirements()->whereNotIn('status', ['verified', 'released', 'dropped'])->count(),
            'url' => $this->productUrl($project),
        ];
    }
}
