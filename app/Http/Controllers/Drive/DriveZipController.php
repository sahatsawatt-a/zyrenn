<?php

namespace App\Http\Controllers\Drive;

use App\Http\Controllers\Concerns\ActsForOwner;
use App\Http\Controllers\Controller;
use App\Models\Drive\DriveFile;
use App\Models\Drive\DriveFolder;
use App\Support\Folders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class DriveZipController extends Controller
{
    use ActsForOwner;

    /**
     * Kinds already compressed -- pictures, films, sound, PDFs, archives --
     * stored in the zip as they are: squeezing them again only costs time.
     */
    private const STORED = ['image', 'video', 'audio', 'pdf', 'archive'];

    /**
     * A folder of the Drive -- its files and its folders, as they sit in it --
     * or the whole Drive, as one zip download.
     */
    public function show(Request $request): BinaryFileResponse
    {
        $owner = $this->owner($request);

        $validated = $request->validate([
            'folder' => ['nullable', 'string', Folders::rule(DriveFolder::class, $owner)],
        ]);

        // A big folder is a while to zip, not a page that gives up
        set_time_limit(600);

        ['name' => $zipName, 'top' => $top, 'pathOf' => $pathOf]
            = Folders::exported(DriveFolder::class, $owner, $validated['folder'] ?? null, 'Drive', self::fileName(...));

        $path = tempnam(sys_get_temp_dir(), 'drive-');
        $zip = new ZipArchive;

        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The zip could not be opened');
        }

        $disk = Storage::disk(DriveFile::DISK);
        $taken = [];

        foreach ($owner->driveFiles()->orderBy('name')->get() as $file) {
            $folder = $pathOf($file->folder_id);

            if ($folder === null || ! Gate::allows('view', $file) || ! $disk->exists($file->path)) {
                continue;
            }

            // Two files of one name in a folder stay two files
            $name = self::fileName($file->name);
            $entry = "{$folder}/{$name}";

            for ($n = 2; isset($taken[$entry]); $n++) {
                $entry = $folder.'/'.preg_replace('/(\.[^.]*)?$/', " ({$n})$1", $name, 1);
            }

            $taken[$entry] = true;
            $zip->addFile($disk->path($file->path), $entry);
            $zip->setCompressionName($entry, in_array($file->kind, self::STORED, true) ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE);
        }

        // An empty folder is still a zip, with the folder in it
        if ($taken === []) {
            $zip->addEmptyDir($top);
        }

        $zip->close();

        return response()->download($path, $zipName.'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend();
    }

    /** A name a disk takes: no slashes or marks one refuses. */
    private static function fileName(string $name): string
    {
        $name = trim((string) preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]+/u', '-', $name), ' .');

        return $name === '' ? 'Untitled' : mb_substr($name, 0, 200);
    }
}
