<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

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
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Sanctum's token table has no foreign key, so remove a user's tokens with them.
     */
    protected static function booted(): void
    {
        // Drive rows go with the database cascade, which skips model events, so drop the bytes here
        static::deleted(function (User $user) {
            Storage::disk(DriveFile::DISK)->deleteDirectory('drive/'.$user->id);
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

    /**
     * Get the notes owned by the user.
     *
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * Get the files in the user's Drive.
     *
     * @return HasMany<DriveFile, $this>
     */
    public function driveFiles(): HasMany
    {
        return $this->hasMany(DriveFile::class);
    }

    /**
     * Get the folders in the user's Drive.
     *
     * @return HasMany<DriveFolder, $this>
     */
    public function driveFolders(): HasMany
    {
        return $this->hasMany(DriveFolder::class);
    }

    /**
     * Get the folders the user files notes in.
     *
     * @return HasMany<NoteFolder, $this>
     */
    public function noteFolders(): HasMany
    {
        return $this->hasMany(NoteFolder::class);
    }
}
