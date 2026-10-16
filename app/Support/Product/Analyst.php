<?php

namespace App\Support\Product;

use App\Models\Agent\Agent;
use App\Models\Product\ProductElement;
use App\Models\Product\ProductRequest;
use App\Models\Product\Requirement;
use App\Models\User;
use App\Support\Agents\AgentRunner;
use App\Support\Ai\AiFailed;

/**
 * A request of the product, worked out by an agent into requirements -- a
 * draft for a person to read, change and keep, never kept by itself. The
 * agent is told the product's parts and its open requirements, and asked for
 * the draft in one shape whatever its own instructions; what comes back is
 * held to that shape, and to parts the product has.
 *
 * @phpstan-type Drafted array{title: string, need: string|null, kind: string, understanding: string, criteria: list<string>, questions: list<string>, assumptions: list<string>, parts: list<string>}
 */
final class Analyst
{
    /** The most requirements one request is worked out into. */
    private const MOST = 8;

    /** The most of each list a requirement is given. */
    private const LIST = 12;

    /** The shape asked for, whatever the agent was made to do otherwise. */
    private const SHAPE = <<<'TEXT'
        Work the request out into requirements. Answer with JSON alone, in this shape:
        {"requirements": [{"title": "...", "need": "...", "kind": "new" | "additional" | "change" | "constraint" | "quality", "understanding": "unknown" | "partial" | "confirmed", "criteria": ["..."], "questions": ["..."], "assumptions": ["..."], "parts": ["CAP-12"]}], "note": "..."}
        - title: what the product must do, in a line. need: who needs it, and why.
        - kind: "change" when it changes what a part already does; "new" for something it does not do yet.
        - understanding: "confirmed" only when the request says it all; "partial" when you had to assume or ask.
        - criteria: things someone can check. questions: what is unclear. assumptions: what you took to be so.
        - parts: codes from the product's parts below, the ones it is about; none when none fits.
        - note: one line for the team, e.g. that it is a bug rather than a request, or that it repeats a requirement there is already.
        TEXT;

    public function __construct(private AgentRunner $runner) {}

    /**
     * @return array{requirements: list<Drafted>, note: string|null, agent: string, version: int, model: string, actions: list<mixed>}
     *
     * @throws AiFailed when it cannot be asked, or answers in some other shape
     */
    public function workOut(Agent $agent, ProductRequest $request, User $for): array
    {
        $project = $request->project;
        $answer = $this->runner->ask($agent, null, $request->original, more: self::SHAPE."\n\n".$this->brief($request), for: $for, changes: false);
        $drafted = $this->held(is_array($answer['json']) ? $answer['json'] : null, array_values(array_map('strval', $project->productElements()->pluck('code')->all())));

        if ($drafted === null) {
            throw new AiFailed("{$agent->name} did not answer with requirements in the shape asked for. It said: ".mb_strimwidth($answer['text'], 0, 300, '…'));
        }

        return [...$drafted, 'agent' => $agent->name, 'version' => $answer['version'], 'model' => $answer['model'], 'actions' => $answer['actions']];
    }

    /**
     * What the agent is told besides the request: who asked, the product's
     * parts, and the requirements it already has that are not settled.
     */
    private function brief(ProductRequest $request): string
    {
        $project = $request->project;
        $parts = $project->productElements()->with('parent:id,name')->where('status', '!=', 'retired')->orderBy('id')->get();
        $open = $project->requirements()->whereNotIn('status', ['verified', 'released', 'dropped'])->orderBy('id')->get(['code', 'title', 'status']);

        return implode("\n", array_filter([
            $request->from_name ? "Asked by: {$request->from_name}" : null,
            '',
            "The product's parts (code, name, the part it is under, how it stands):",
            $parts->isEmpty() ? '(none yet)' : $parts->map(fn (ProductElement $one) => "{$one->code} {$one->name}".($one->parent ? " (under {$one->parent->name})" : '').($one->kind === ProductElement::CAPABILITY ? " [{$one->status}]" : ''))->implode("\n"),
            '',
            'Requirements it already has, not yet settled:',
            $open->isEmpty() ? '(none)' : $open->map(fn (Requirement $one) => "{$one->code} {$one->title} ({$one->status})")->implode("\n"),
        ], fn (?string $line) => $line !== null));
    }

    /**
     * The draft as it may be kept: each requirement with a title, lists of
     * text, kinds and understanding there are, and parts the product has.
     *
     * @param  array<mixed>|null  $json
     * @param  list<string>  $codes
     * @return array{requirements: list<Drafted>, note: string|null}|null
     */
    private function held(?array $json, array $codes): ?array
    {
        $given = $json['requirements'] ?? (array_is_list($json ?? []) ? $json : null);

        if (! is_array($given)) {
            return null;
        }

        $texts = fn (mixed $list, int $max) => array_values(array_slice(array_filter(
            array_map(fn ($one) => is_string($one) ? mb_substr(trim($one), 0, $max) : '', is_array($list) ? $list : []),
            fn (string $one) => $one !== '',
        ), 0, self::LIST));

        $requirements = [];

        foreach (array_slice($given, 0, self::MOST) as $one) {
            $title = is_array($one) && is_string($one['title'] ?? null) ? mb_substr(trim($one['title']), 0, 200) : '';

            if ($title === '') {
                continue;
            }

            $requirements[] = [
                'title' => $title,
                'need' => is_string($one['need'] ?? null) && trim($one['need']) !== '' ? mb_substr(trim($one['need']), 0, 5000) : null,
                'kind' => in_array($one['kind'] ?? null, Requirement::KINDS, true) ? $one['kind'] : 'new',
                'understanding' => in_array($one['understanding'] ?? null, Requirement::UNDERSTANDING, true) ? $one['understanding'] : 'partial',
                'criteria' => $texts($one['criteria'] ?? [], 500),
                'questions' => $texts($one['questions'] ?? [], 500),
                'assumptions' => $texts($one['assumptions'] ?? [], 500),
                // Only parts there are: a code made up is left out
                'parts' => array_values(array_unique(array_intersect(array_map(fn ($code) => is_string($code) ? mb_strtoupper(trim($code)) : '', is_array($one['parts'] ?? null) ? $one['parts'] : []), $codes))),
            ];
        }

        if ($requirements === []) {
            return null;
        }

        return [
            'requirements' => $requirements,
            'note' => is_string($json['note'] ?? null) && trim($json['note']) !== '' ? mb_substr(trim($json['note']), 0, 500) : null,
        ];
    }
}
