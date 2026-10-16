<?php

namespace App\Models\Concerns;

use App\Support\Live\Live;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * Something saved whole from a page, a moment after each change, with a
 * check for someone else's newer save: a trip, a workflow's draft. Each save
 * that changes what is in it counts a new revision; a page saving from an
 * older one is refused (Http\Controllers\Concerns\SavesRevisions), and
 * whoever else has it open hears of each new one -- their page loads it, or,
 * holding changes of its own, asks which to keep (useRevisionedSave).
 *
 * @property int $revision
 */
trait KeepsRevisions
{
    /**
     * The columns whose change is a new revision.
     *
     * @return list<string>
     */
    abstract protected function revisedBy(): array;

    /** What those who have it open are told: it was "saved" anew, or "deleted". */
    abstract protected function changed(string $what): ShouldBroadcast;

    public static function bootKeepsRevisions(): void
    {
        static::saving(function (self $model) {
            if ($model->exists && $model->isDirty($model->revisedBy())) {
                $model->revision++;
            }
        });

        static::saved(function (self $model) {
            if ($model->wasChanged('revision')) {
                Live::tell($model->changed('saved'));
            }
        });

        static::deleted(fn (self $model) => Live::tell($model->changed('deleted')));
    }
}
