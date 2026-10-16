<?php

namespace App\Support\Workflow\Steps;

use App\Support\Workflow\Seen;

/**
 * Only new items: of a list, the items not seen before -- each told apart by
 * a part of it (its id, its link, an email) or by the whole of it --
 * remembered across runs, or only within the list itself.
 */
final class OnlyNew implements Step
{
    public function run(StepContext $step): StepResult
    {
        $items = $step->formula('items');

        if (! is_array($items) || ! array_is_list($items)) {
            throw new StepFailed('"Items" should come to a list, e.g. {{ node("feed").items }}.');
        }

        $by = trim((string) $step->setting('by'));
        $keys = array_map(fn (mixed $item) => self::keyOf($item, $by), $items);
        $across = $step->setting('across') !== 'list';
        $new = $across ? array_flip(Seen::unseen($step, $keys)) : [];
        $kept = [];
        $given = [];

        foreach ($items as $at => $item) {
            $key = $keys[$at];

            if (isset($given[$key]) || ($across && ! isset($new[$key]))) {
                continue;
            }

            $given[$key] = true;
            $kept[] = $item;
        }

        if ($across) {
            Seen::keep($step, array_keys($given));
        }

        return new StepResult(['items' => $kept, 'count' => count($kept), 'left_out' => count($items) - count($kept)]);
    }

    /**
     * What tells an item apart: the part named (a.b for a part within one),
     * or the whole item when none is named or it has no such part.
     */
    public static function keyOf(mixed $item, string $by): string
    {
        $part = $by !== '' && is_array($item) ? data_get($item, $by) : null;
        $part ??= $item;

        return is_scalar($part) ? (string) $part : (string) json_encode($part, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
