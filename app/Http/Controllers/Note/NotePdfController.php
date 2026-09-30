<?php

namespace App\Http\Controllers\Note;

use App\Http\Controllers\Controller;
use App\Models\Note\Note;
use App\Support\NotePdf;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;

class NotePdfController extends Controller
{
    /**
     * Print the note to PDF, in the style asked for, and send it back as a
     * download. Saving it to the Drive is the same PDF, uploaded by the page
     * like any other file.
     */
    public function show(Request $request, Note $note): Response|JsonResponse
    {
        Gate::authorize('view', $note);

        $style = $request->validate([
            'style' => ['nullable', Rule::in(NotePdf::STYLES)],
        ])['style'] ?? 'simple';

        try {
            $bytes = NotePdf::bytes($note, $style, NotePdf::rawSessionCookie($request), $warning);
        } catch (ConnectionException|RuntimeException $e) {
            report($e);

            return response()->json([
                'message' => __('Couldn’t print this note to PDF. Try again in a moment.'),
            ], 502);
        }

        // A title like "Q3/Q4 plan" is fine as a title and not as a filename
        $name = str_replace(['/', '\\'], '-', trim($note->title) ?: 'Untitled').'.pdf';

        return response($bytes, 200, array_filter([
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $name,
                // Headers are ASCII; a Thai title still arrives through filename*
                (Str::slug($note->title) ?: 'note').'.pdf',
            ),
            'Cache-Control' => 'private, no-store',
            'X-Pdf-Warning' => $warning,
        ]));
    }
}
