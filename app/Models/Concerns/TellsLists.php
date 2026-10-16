<?php

namespace App\Models\Concerns;

use App\Support\Live\Listed;

/**
 * Lists it is shown in follow it as it is made, changed and deleted
 * (Listed). A model saved again and again while it is edited -- a note, a
 * board -- names in LISTED_BY what its lists show of it, and is told only
 * when one of those changes.
 */
trait TellsLists
{
    protected static function bootTellsLists(): void
    {
        static::created(fn (self $model) => Listed::changed($model));
        static::deleted(fn (self $model) => Listed::changed($model));
        static::updated(function (self $model) {
            $shown = defined(static::class.'::LISTED_BY') ? constant(static::class.'::LISTED_BY') : null;

            if ($shown === null || $model->wasChanged($shown)) {
                Listed::changed($model);
            }
        });
    }
}
