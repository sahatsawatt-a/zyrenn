<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Remembers who changed it last. Whoever is signed in when it is saved --
 * on its page, or over MCP with their token. Saves made for someone without
 * a session (the collaboration server, the admin MCP) name them themselves.
 *
 * @property int|null $updated_by
 */
trait RecordsEditor
{
    protected static function bootRecordsEditor(): void
    {
        static::saving(function (self $model) {
            $editor = Auth::user();

            if ($editor instanceof User && $model->isDirty() && ! $model->isDirty('updated_by')) {
                $model->updated_by = $editor->id;
            }
        });
    }

    /**
     * Who changed it last.
     *
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
