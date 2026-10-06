<?php

namespace App\Models\Drive;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\HasRefId;
use App\Models\Concerns\RecordsEditor;
use App\Models\Owner;
use App\Models\User;
use Database\Factories\Drive\DriveFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A file in a user's or a project's Drive. The bytes live on the private
 * `local` disk and are only ever streamed back, through the drive.files.show
 * route, to whoever may see them.
 *
 * @property int $id
 * @property string $ref_id
 * @property int|null $folder_id
 * @property string $name
 * @property string $path
 * @property string|null $mime
 * @property string|null $ext
 * @property int $size
 * @property string $kind
 * @property int|null $source_id
 * @property array<string, mixed>|null $edit
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'folder_id', 'path', 'mime', 'ext', 'size', 'kind', 'source_id', 'edit'])]
class DriveFile extends Model
{
    /** @use HasFactory<DriveFileFactory> */
    use BelongsToOwner, HasFactory, HasRefId, RecordsEditor;

    public const DISK = 'local';

    /**
     * Images the browser can show in an <img>. SVG is safe there: scripts in
     * an image context never run.
     */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml'];

    /**
     * Videos a browser's <video> can play: MP4 (and its M4V and QuickTime
     * cousins, which hold the same H.264 inside), WebM and Ogg.
     */
    public const VIDEO_MIMES = ['video/mp4', 'video/x-m4v', 'video/quicktime', 'video/webm', 'video/ogg'];

    /** The largest upload, in kilobytes (docker/php/uploads.ini allows the same). */
    public const MAX_KB = 512000;

    protected static function booted(): void
    {
        static::deleted(function (DriveFile $file) {
            Storage::disk(self::DISK)->delete($file->path);
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
            'edit' => 'array',
        ];
    }

    /**
     * @return BelongsTo<DriveFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(DriveFolder::class, 'folder_id');
    }

    /**
     * The picture this one was made from in the photo editor.
     *
     * @return BelongsTo<DriveFile, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_id');
    }

    /**
     * Store an upload in the owner's Drive, as uploaded by $by.
     */
    public static function store(UploadedFile $upload, Owner $owner, ?DriveFolder $folder = null, ?User $by = null): self
    {
        $ext = strtolower($upload->getClientOriginalExtension() ?: $upload->guessExtension() ?: 'bin');
        $mime = $upload->getMimeType() ?: $upload->getClientMimeType();
        $path = $upload->storeAs($owner->driveDirectory(), Str::random(32).'.'.$ext, self::DISK);

        $file = new self([
            'folder_id' => $folder?->id,
            'name' => $upload->getClientOriginalName(),
            'path' => $path,
            'mime' => $mime,
            'ext' => $ext,
            'size' => $upload->getSize(),
            'kind' => self::kindFor($mime, $ext),
        ]);
        $file->created_by = $by?->id;
        $owner->driveFiles()->save($file);

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
     * Whether the file can be played in a note, on a board or in the Drive.
     */
    public function isVideo(): bool
    {
        return in_array($this->mime, self::VIDEO_MIMES, true);
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
            'is_video' => $this->isVideo(),
            'url' => $this->url(),
            'created_at' => $this->created_at,
        ];
    }
}
