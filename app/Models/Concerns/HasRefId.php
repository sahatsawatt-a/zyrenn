<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * A short public reference id, used in URLs so internal ids are never exposed.
 */
trait HasRefId
{
    protected static function bootHasRefId(): void
    {
        static::creating(function (self $model) {
            $model->ref_id ??= static::newRefId();
        });
    }

    /**
     * Generate an unused public reference id, e.g. "k3x9m2p7qa".
     */
    public static function newRefId(): string
    {
        do {
            $refId = Str::lower(Str::random(10));
        } while (static::query()->where('ref_id', $refId)->exists());

        return $refId;
    }

    public function getRouteKeyName(): string
    {
        return 'ref_id';
    }
}
