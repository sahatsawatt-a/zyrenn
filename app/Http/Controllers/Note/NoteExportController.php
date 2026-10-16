<?php

namespace App\Http\Controllers\Note;

use App\Http\Controllers\Concerns\ActsForOwner;
use App\Http\Controllers\Controller;
use App\Models\Note\NoteFolder;
use App\Support\Chrome\Chrome;
use App\Support\Folders;
use App\Support\Note\NoteExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class NoteExportController extends Controller
{
    use ActsForOwner;

    /**
     * A folder of notes -- everything in it, its folders too -- or all of
     * them, as a zip of Markdown files with their pictures (NoteExport).
     */
    public function show(Request $request): BinaryFileResponse
    {
        $owner = $this->owner($request);

        $validated = $request->validate([
            'folder' => ['nullable', 'string', Folders::rule(NoteFolder::class, $owner)],
        ]);

        // Diagrams are pictured by a browser that opens each note; a big
        // folder is a while, not a page that gives up
        set_time_limit(600);

        ['name' => $name, 'pathOf' => $pathOf]
            = Folders::exported(NoteFolder::class, $owner, $validated['folder'] ?? null, 'Notes', NoteExport::fileName(...));

        $notes = [];

        foreach ($owner->notes()->orderBy('title')->get() as $note) {
            $folder = $pathOf($note->folder_id);

            if ($folder !== null) {
                $notes[] = ['note' => $note, 'folder' => $folder];
            }
        }

        $zip = NoteExport::zip($notes, $request->user(), Chrome::sessionCookie($request));

        return response()->download($zip['path'], $name.'.zip', array_filter([
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
            'X-Export-Warning' => match ($zip['unpictured']) {
                0 => null,
                1 => 'One diagram could not be pictured and is left as its code.',
                default => "{$zip['unpictured']} diagrams could not be pictured and are left as their code.",
            },
        ]))->deleteFileAfterSend();
    }
}
