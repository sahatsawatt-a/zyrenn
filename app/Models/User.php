<?php

namespace App\Models;

use App\Models\Chat\AiConnection;
use App\Models\Chat\ChatRoom;
use App\Models\Concerns\OwnsContent;
use App\Models\Drive\DriveFile;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements Owner, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, OwnsContent, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Sanctum's token table has no foreign key, so remove a user's tokens with them.
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            $user->tokens()->delete();

            // Nobody else could open these once the user was gone
            $user->soloProjects()->each(fn (Project $project) => $project->delete());
        });

        // Drive rows go with the database cascade, which skips model events, so drop the bytes here
        static::deleted(function (User $user) {
            Storage::disk(DriveFile::DISK)->deleteDirectory($user->driveDirectory());
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function driveDirectory(): string
    {
        return 'drive/'.$this->id;
    }

    /**
     * The projects the user is a member of, with their role in each.
     *
     * @return BelongsToMany<Project, $this, Membership>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)
            ->using(Membership::class)
            ->withPivot('id', 'ref_id', 'role')
            ->withTimestamps();
    }

    /**
     * The projects nobody else is in. They go with the user.
     *
     * @return Collection<int, Project>
     */
    public function soloProjects(): Collection
    {
        return $this->projects()
            ->whereDoesntHave('members', fn (Builder $others) => $others->whereKeyNot($this->id))
            ->orderBy('name')
            ->get();
    }

    /**
     * The projects the user alone runs while others are in them. Those would
     * be left with nobody to run them, so the account can't be deleted while
     * there are any: another member has to be made an owner first.
     *
     * @return Collection<int, Project>
     */
    public function projectsNeedingAnOwner(): Collection
    {
        return $this->projects()
            ->wherePivot('role', Project::OWNER)
            ->whereHas('members', fn (Builder $others) => $others->whereKeyNot($this->id))
            ->whereDoesntHave('members', fn (Builder $others) => $others
                ->whereKeyNot($this->id)
                ->where('project_user.role', Project::OWNER))
            ->orderBy('name')
            ->get();
    }

    /**
     * Where the user reaches models: their hosts and keys.
     *
     * @return HasMany<AiConnection, $this>
     */
    public function aiConnections(): HasMany
    {
        return $this->hasMany(AiConnection::class);
    }

    /**
     * The user's chat rooms.
     *
     * @return HasMany<ChatRoom, $this>
     */
    public function chatRooms(): HasMany
    {
        return $this->hasMany(ChatRoom::class);
    }
}
