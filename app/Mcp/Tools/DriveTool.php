<?php

namespace App\Mcp\Tools;

use App\Models\Drive\DriveFile;
use App\Models\Drive\DriveFolder;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\JsonSchema\Types\Type;

/**
 * Base for the Drive tools shared by both MCP servers. The user scoping and
 * folder-path handling live in ScopedTool.
 *
 * @extends ScopedTool<DriveFolder>
 */
abstract class DriveTool extends ScopedTool
{
    /** Text a tool will inline in a response, rather than point at. */
    protected const MAX_INLINE_BYTES = 256 * 1024;

    /**
     * @return HasMany<DriveFolder, User>
     */
    protected function folders(User $user): HasMany
    {
        return $user->driveFolders();
    }

    protected function findFile(User $user, string $refId): ?DriveFile
    {
        return $user->driveFiles()->where('ref_id', $refId)->first();
    }

    /**
     * Schema for the file reference argument shared by single-file tools.
     */
    protected function refIdArgument(JsonSchema $schema): Type
    {
        return $schema->string()->description('The file\'s ref_id, as returned by list-drive or upload-file (e.g. "k3x9m2p7qa").')->required();
    }

    /**
     * The file's folder as a path, e.g. "Screenshots/2026", or null at the top level.
     *
     * @param  array<int, string>|null  $paths  folder paths by id, when listing many files
     */
    protected function folderPath(DriveFile $file, ?array $paths = null): ?string
    {
        if ($file->folder_id === null) {
            return null;
        }

        return $paths[$file->folder_id]
            ?? implode('/', array_map(fn (DriveFolder $folder) => $folder->name, $file->folder->ancestry()));
    }

    /**
     * The shape every Drive tool reports a file in.
     *
     * `markdown` is the ready-made line for putting an image into a note with
     * create-note or update-note, so a client never has to build the URL.
     *
     * @param  array<int, string>|null  $paths  folder paths by id, when listing many files
     * @return array<string, mixed>
     */
    protected function summary(DriveFile $file, ?array $paths = null): array
    {
        return [
            'ref_id' => $file->ref_id,
            'user_id' => $file->user_id,
            'name' => $file->name,
            'folder' => $this->folderPath($file, $paths),
            'mime' => $file->mime,
            'kind' => $file->kind,
            'size' => $file->size,
            'is_image' => $file->isImage(),
            'url' => $file->url(),
            'updated_at' => $file->updated_at?->toIso8601String(),
            ...$file->isImage() ? ['markdown' => '!['.$file->name.']('.$file->url().')'] : [],
        ];
    }
}
