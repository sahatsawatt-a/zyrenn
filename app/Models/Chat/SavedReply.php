<?php

namespace App\Models\Chat;

use App\Models\Concerns\HasRefId;
use App\Models\Project;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An answer a project gives its customers often, kept once and put in a
 * chat with a click. Its words may name {name}, the customer it is said to,
 * and {me}, whoever says it: filled in as it is put in.
 *
 * @property int $id
 * @property string $ref_id
 * @property int $project_id
 * @property string $title
 * @property string $body
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'body'])]
class SavedReply extends Model
{
    use HasRefId;

    /** How many a project keeps, and how long one may be. */
    public const MOST = 200;

    public const MAX_TITLE = 80;

    public const MAX_BODY = 4000;

    /**
     * Get the project it is one of.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array{ref_id: string, title: string, body: string}
     */
    public function shown(): array
    {
        return $this->only(['ref_id', 'title', 'body']);
    }
}
