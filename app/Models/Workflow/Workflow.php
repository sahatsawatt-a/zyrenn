<?php

namespace App\Models\Workflow;

use App\Events\WorkflowChanged;
use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\HasRefId;
use App\Models\Concerns\RecordsEditor;
use App\Models\User;
use App\Support\Live\Live;
use App\Support\Workflow\Definition;
use Database\Factories\Workflow\WorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A workflow: steps drawn on a canvas and joined up, filed in folders like a
 * board. What is drawn is the draft (definition). Publishing freezes it into
 * a WorkflowVersion, which is what runs on its own; the draft can change
 * again without touching what runs.
 *
 * @property int $id
 * @property string $ref_id
 * @property int|null $folder_id
 * @property string $title
 * @property array<string, mixed>|null $definition
 * @property int $revision
 * @property bool $enabled
 * @property int|null $published_version_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkflowVersion|null $publishedVersion
 *
 * @phpstan-import-type Shape from Definition
 */
#[Fillable(['title', 'definition'])]
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use BelongsToOwner, HasFactory, HasRefId, RecordsEditor;

    /**
     * Mirror the column defaults so new instances match what the database stores.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'title' => '',
        'revision' => 0,
        'enabled' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Keep the draft in its known shape, and count each change to it.
     */
    protected static function booted(): void
    {
        static::saving(function (Workflow $workflow) {
            if ($workflow->definition === null || $workflow->isDirty('definition')) {
                $workflow->definition = $workflow->definition === null
                    ? Definition::blank()
                    : Definition::normalize($workflow->definition);
            }

            if ($workflow->exists && $workflow->isDirty(['title', 'definition', 'folder_id'])) {
                $workflow->revision++;
            }
        });

        // Whoever else has it open hears of it: an editor with nothing
        // unsaved loads the new copy; one with changes of its own asks which to keep
        static::saved(function (Workflow $workflow) {
            if ($workflow->wasChanged('revision')) {
                Live::tell(new WorkflowChanged($workflow, 'saved'));
            }
        });
        static::deleted(fn (Workflow $workflow) => Live::tell(new WorkflowChanged($workflow, 'deleted')));
    }

    /**
     * @return BelongsTo<WorkflowFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(WorkflowFolder::class);
    }

    /**
     * Every frozen copy of it, published or tried.
     *
     * @return HasMany<WorkflowVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    /**
     * Its runs, the latest last.
     *
     * @return HasMany<WorkflowRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    /**
     * The version that runs on its own.
     *
     * @return BelongsTo<WorkflowVersion, $this>
     */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'published_version_id');
    }

    /**
     * The draft, in its kept shape.
     *
     * @return Shape
     */
    public function draft(): array
    {
        return Definition::normalize($this->definition ?? Definition::blank());
    }

    /**
     * Whether the draft has changed since it was last published.
     */
    public function hasUnpublishedChanges(): bool
    {
        return $this->publishedVersion?->revision !== $this->revision;
    }

    /**
     * Freeze the draft as it stands into a new version. The caller has made
     * sure there is nothing in the way (Definition::problems).
     */
    public function freeze(string $kind, User $by): WorkflowVersion
    {
        return DB::transaction(function () use ($kind, $by) {
            // Numbered one after another, even when two people publish at once
            $this->newQuery()->whereKey($this->id)->lockForUpdate()->first();

            /** @var WorkflowVersion $version */
            $version = $this->versions()->create([
                'number' => (int) $this->versions()->max('number') + 1,
                'kind' => $kind,
                'revision' => $this->revision,
                'schema_version' => Definition::SCHEMA_VERSION,
                'definition' => $this->definition,
                'created_by' => $by->id,
            ]);

            if ($kind === WorkflowVersion::PUBLISHED) {
                DB::afterCommit(fn () => Live::tell(new WorkflowChanged($this, 'published')));

                // Not a change to the draft, so it neither counts as one nor names an editor
                $this->newQuery()->whereKey($this->id)->update(['published_version_id' => $version->id]);
                $this->setRawAttributes([...$this->getAttributes(), 'published_version_id' => $version->id], true);
                $this->setRelation('publishedVersion', $version);
            }

            return $version;
        });
    }
}
