<?php

namespace App\Http\Controllers\Note;

use App\Http\Controllers\Concerns\ActsForOwner;
use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\Note\NoteFolder;
use App\Support\Folders;
use App\Support\Note\NoteExport;
use App\Support\Note\NotePdf;
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

        $folders = Folders::all(NoteFolder::class, $owner)->keyBy('id');
        $open = filled($validated['folder'] ?? null) ? $folders->firstWhere('ref_id', $validated['folder']) : null;
        $top = $open instanceof NoteFolder ? NoteExport::fileName($open->name) : 'Notes';

        // Each folder's path inside the zip, from the one exported
        $pathOf = function (?int $id) use ($folders, $open, $top): ?string {
            $names = [];

            for ($folder = $folders->get($id); $folder instanceof Folder; $folder = $folders->get($folder->parent_id)) {
                if ($open && $folder->id === $open->id) {
                    return implode('/', [$top, ...$names]);
                }

                array_unshift($names, NoteExport::fileName($folder->name));
            }

            // Outside the folder exported, or at the top when all are
            return $open ? null : implode('/', [$top, ...$names]);
        };

        $notes = [];

        foreach ($owner->notes()->orderBy('title')->get() as $note) {
            $folder = $pathOf($note->folder_id);

            if ($folder !== null) {
                $notes[] = ['note' => $note, 'folder' => $folder];
            }
        }

        $zip = NoteExport::zip($notes, $request->user(), NotePdf::rawSessionCookie($request));

        return response()->download($zip['path'], $top.'.zip', array_filter([
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
