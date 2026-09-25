<?php

namespace App\Models;

use App\Models\Concerns\HasRefId;
use Database\Factories\DriveFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A file in a user's Drive. The bytes live on the private `local` disk and are
 * only ever streamed back to their owner through the drive.files.show route.
 *
 * @property int $id
 * @property string $ref_id
 * @property int $user_id
 * @property int|null $folder_id
 * @property string $name
 * @property string $path
 * @property string|null $mime
 * @property string|null $ext
 * @property int $size
 * @property string $kind
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'folder_id', 'path', 'mime', 'ext', 'size', 'kind'])]
class DriveFile extends Model
{
    /** @use HasFactory<DriveFileFactory> */
    use HasFactory, HasRefId;

    public const DISK = 'local';

    /**
     * Images the browser can show in an <img>. SVG is safe there: scripts in
     * an image context never run.
     */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml'];

    protected static function booted(): void
    {
        static::deleted(function (DriveFile $file) {
            Storage::disk(self::DISK)->delete($file->path);
        });
    }

    /**
     * Get the user that owns the file.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<DriveFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(DriveFolder::class, 'folder_id');
    }

    /**
     * Store an upload in the user's Drive.
     */
    public static function store(UploadedFile $upload, User $user, ?DriveFolder $folder = null): self
    {
        $ext = strtolower($upload->getClientOriginalExtension() ?: $upload->guessExtension() ?: 'bin');
        $mime = $upload->getMimeType() ?: $upload->getClientMimeType();
        $path = $upload->storeAs('drive/'.$user->id, Str::random(32).'.'.$ext, self::DISK);

        $file = new self([
            'folder_id' => $folder?->id,
            'name' => $upload->getClientOriginalName(),
            'path' => $path,
            'mime' => $mime,
            'ext' => $ext,
            'size' => $upload->getSize(),
            'kind' => self::kindFor($mime, $ext),
        ]);
        $file->user()->associate($user)->save();

        return $file;
    }

    /**
     * Bucket a file by its mime type (preferred) or extension.
     */
    public static function kindFor(?string $mime, ?string $ext): string
    {
        $mime = strtolower((string) $mime);
        $ext = strtolower((string) $ext);

        return match (true) {
            $mime === 'application/pdf' || $ext === 'pdf' => 'pdf',
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'audio/') => 'audio',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'text/') || in_array($ext, ['doc', 'docx', 'md', 'rtf', 'odt', 'ppt', 'pptx', 'xls', 'xlsx', 'csv'], true) => 'doc',
            in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'], true) => 'archive',
            default => 'other',
        };
    }

    /**
     * Whether the file can be placed in a note as an image.
     */
    public function isImage(): bool
    {
        return in_array($this->mime, self::IMAGE_MIMES, true);
    }

    /**
     * The URL the file is served from.
     */
    public function url(): string
    {
        return route('drive.files.show', $this, absolute: false);
    }

    /**
     * The shape the client sees.
     *
     * @return array<string, mixed>
     */
    public function card(): array
    {
        return [
            'ref_id' => $this->ref_id,
            'name' => $this->name,
            'kind' => $this->kind,
            'mime' => $this->mime,
            'size' => $this->size,
            'is_image' => $this->isImage(),
            'url' => $this->url(),
            'created_at' => $this->created_at,
        ];
    }
}
