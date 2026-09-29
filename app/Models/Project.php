<?php

namespace App\Models;

use App\Models\Concerns\HasRefId;
use App\Models\Concerns\OwnsContent;
use App\Models\Drive\DriveFile;
use App\Models\Table\Table;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A shared space with the same notes, boards, tables and Drive a user has of
 * their own. What is in it belongs to the project, not to whoever made it, so
 * it stays when they leave and goes only when the project is deleted.
 *
 * @property int $id
 * @property string $ref_id
 * @property string $name
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class Project extends Model implements Owner
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasRefId, OwnsContent;

    /** Runs the project: its members, its name, deleting it. */
    public const OWNER = 'owner';

    /** Makes and changes what is in it. */
    public const EDITOR = 'editor';

    /** Only looks. */
    public const VIEWER = 'viewer';

    public const ROLES = [self::OWNER, self::EDITOR, self::VIEWER];

    protected static function booted(): void
    {
        // Rows go with the database cascade, which skips model events -- but a
        // table's rows live in a database table of their own, dropped as it is deleted
        static::deleting(function (Project $project) {
            $project->tables()->each(fn (Table $table) => $table->delete());
        });

        static::deleted(function (Project $project) {
            Storage::disk(DriveFile::DISK)->deleteDirectory($project->driveDirectory());
        });
    }

    public function driveDirectory(): string
    {
        return 'drive/projects/'.$this->id;
    }

    /**
     * Everyone in the project, with their role.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Who made the project.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user's role here, or null when they are not a member.
     */
    public function roleOf(User $user): ?string
    {
        return $this->members()->whereKey($user->id)->first()?->getRelationValue('pivot')?->getAttribute('role');
    }
}
