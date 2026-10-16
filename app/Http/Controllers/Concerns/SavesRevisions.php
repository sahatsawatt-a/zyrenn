<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Map\Trip;
use App\Models\Workflow\Workflow;
use Illuminate\Http\JsonResponse;

/**
 * Refusing a save made from an older copy than the one kept now, rather
 * than quietly undoing someone else's change (Models\Concerns\KeepsRevisions).
 */
trait SavesRevisions
{
    /**
     * The refusal (409) when the page saved from another revision than the
     * one kept -- with what is there now under `$as`, for it to take or save
     * over -- or null when it saved from the latest, or said no revision.
     *
     * @param  list<string>  $fields  what of it the page holds
     */
    protected function staleSave(Trip|Workflow $thing, ?int $seen, string $as, array $fields, string $message): ?JsonResponse
    {
        if ($seen === null || $seen === $thing->revision) {
            return null;
        }

        $thing->loadMissing('editor:id,name');

        return response()->json([
            'message' => $message,
            $as => [
                ...$thing->only([...$fields, 'revision', 'updated_at']),
                'edited_by' => $thing->editor?->name,
            ],
        ], 409);
    }
}
