<?php

namespace App\Http\Controllers\Drive;

use App\Http\Controllers\Controller;
use App\Models\Drive\DriveFile;
use App\Models\Drive\DriveFolder;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DriveFileController extends Controller
{
    /**
     * Types the browser may render in place; anything else is downloaded, so
     * an uploaded HTML page can never run on this origin.
     */
    private const INLINE_MIMES = [
        ...DriveFile::IMAGE_MIMES,
        'application/pdf',
        'text/plain',
    ];

    /**
     * Upload files into the Drive.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'files' => ['required', 'array', 'max:50'],
            'files.*' => ['required', 'file', 'max:51200'],
            'folder' => ['nullable', 'string', self::ownFolder($user)],
        ]);

        $folder = $request->filled('folder')
            ? $user->driveFolders()->where('ref_id', $request->string('folder'))->first()
            : null;

        $files = collect($request->file('files'))
            ->map(fn ($upload) => DriveFile::store($upload, $user, $folder));

        if ($request->expectsJson()) {
            return response()->json([
                'files' => $files->map(fn (DriveFile $file) => $file->card()),
            ], 201);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('Uploaded :count file.|Uploaded :count files.', $files->count())]);

        return back();
    }

    /**
     * Stream a file to its owner.
     */
    public function show(Request $request, DriveFile $file): StreamedResponse
    {
        Gate::authorize('view', $file);

        abort_unless(Storage::disk(DriveFile::DISK)->exists($file->path), 404);

        $inline = ! $request->boolean('download') && in_array($file->mime, self::INLINE_MIMES, true);

        return Storage::disk(DriveFile::DISK)->response(
            $file->path,
            $file->name,
            [
                'Content-Type' => $file->mime ?? 'application/octet-stream',
                'Cache-Control' => 'private, max-age=31536000, immutable',
                'X-Content-Type-Options' => 'nosniff',
                // Opened directly, an SVG is a document: keep any script in it from running
                'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
            ],
            $inline ? 'inline' : 'attachment',
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
            'folder' => ['sometimes', 'nullable', 'string', self::ownFolder($request->user())],
        ]);

        if (array_key_exists('name', $validated)) {
            $file->name = $validated['name'];
        }

        if (array_key_exists('folder', $validated)) {
            $file->folder_id = $validated['folder'] === null
                ? null
                : DriveFolder::query()->where('ref_id', $validated['folder'])->value('id');
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

    /**
     * A folder ref_id that belongs to the user.
     */
    public static function ownFolder(User $user): Exists
    {
        return Rule::exists('drive_folders', 'ref_id')->where('user_id', $user->id);
    }
}
