<?php

namespace App\Http\Controllers\Drive;

use App\Http\Controllers\Concerns\ActsForOwner;
use App\Http\Controllers\Controller;
use App\Models\Drive\DriveFile;
use App\Models\Drive\DriveFolder;
use App\Support\Folders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DriveFileController extends Controller
{
    use ActsForOwner;

    /**
     * Types the browser may render in place; anything else is downloaded, so
     * an uploaded HTML page can never run on this origin.
     */
    private const INLINE_MIMES = [
        ...DriveFile::IMAGE_MIMES,
        ...DriveFile::VIDEO_MIMES,
        'application/pdf',
        'text/plain',
    ];

    /**
     * Upload files into the Drive.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $owner = $this->owner($request, 'contribute');

        $request->validate([
            'files' => ['required', 'array', 'max:50'],
            'files.*' => ['required', 'file', 'max:'.DriveFile::MAX_KB],
            'folder' => ['nullable', 'string', Folders::rule(DriveFolder::class, $owner)],
        ]);

        $folder = $request->filled('folder')
            ? $owner->driveFolders()->where('ref_id', $request->string('folder'))->first()
            : null;

        $files = collect($request->file('files'))
            ->map(fn ($upload) => DriveFile::store($upload, $owner, $folder, $request->user()));

        if ($request->expectsJson()) {
            return response()->json([
                'files' => $files->map(fn (DriveFile $file) => $file->card()),
            ], 201);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('Uploaded :count file.|Uploaded :count files.', $files->count())]);

        return back();
    }

    /**
     * Send a file to whoever may see it. Served from disk rather than streamed,
     * so a browser can ask for a range of it: a video seeks by fetching the
     * part it jumps to, and Safari won't play one at all without that.
     */
    public function show(Request $request, DriveFile $file): BinaryFileResponse
    {
        Gate::authorize('view', $file);

        return $this->send($request, $file);
    }

    /**
     * Send a file to whoever holds a signed link to it: the renderer drawing
     * a board (App\Support\Board\BoardRender), which is signed in as nobody.
     */
    public function signed(Request $request, DriveFile $file): BinaryFileResponse
    {
        return $this->send($request, $file);
    }

    private function send(Request $request, DriveFile $file): BinaryFileResponse
    {
        $disk = Storage::disk(DriveFile::DISK);

        abort_unless($disk->exists($file->path), 404);

        $inline = ! $request->boolean('download') && in_array($file->mime, self::INLINE_MIMES, true);

        $response = response()->file($disk->path($file->path), [
            'Content-Type' => $file->mime ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            // Opened directly, an SVG is a document: keep any script in it from running.
            // A video opened directly is a document too, which plays only with media-src.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);

        // A header can't carry a slash in a file name, and needs a plain-ASCII fallback
        $name = str_replace(['/', '\\'], '-', $file->name);

        return $response->setContentDisposition(
            $inline ? 'inline' : 'attachment',
            $name,
            str_replace('%', '', Str::ascii($name)),
        );
    }

    /**
     * Rename a file or move it to another folder.
     */
    public function update(Request $request, DriveFile $file): RedirectResponse
    {
        Gate::authorize('update', $file);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'folder' => ['sometimes', 'nullable', 'string', Folders::rule(DriveFolder::class, $file->owner())],
        ]);

        if (array_key_exists('name', $validated)) {
            $file->name = $validated['name'];
        }

        if (array_key_exists('folder', $validated)) {
            $file->folder_id = Folders::idOf(DriveFolder::class, $validated['folder']);
        }

        $file->save();

        return back();
    }

    /**
     * Delete a file and its bytes.
     */
    public function destroy(DriveFile $file): RedirectResponse
    {
        Gate::authorize('delete', $file);

        $file->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('File deleted.')]);

        return back();
    }
}
