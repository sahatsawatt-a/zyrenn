<?php

namespace App\Models;

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
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withPivot('role')->withTimestamps();
    }

    /**
     * The projects nobody but this user runs. They'd be left with no owner if
     * the user went, so the account can't be deleted while there are any.
     *
     * @return Collection<int, Project>
     */
    public function soleOwnedProjects(): Collection
    {
        return $this->projects()
            ->wherePivot('role', Project::OWNER)
            ->whereDoesntHave('members', fn (Builder $others) => $others
                ->whereKeyNot($this->id)
                ->where('project_user.role', Project::OWNER))
            ->orderBy('name')
            ->get();
    }
}
