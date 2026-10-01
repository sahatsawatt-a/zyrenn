<?php

namespace App\Http\Controllers\Board;

use App\Http\Controllers\Controller;
use App\Models\Board\Board;
use App\Support\BoardParts;
use App\Support\BoardRender;
use App\Support\Live\Collab;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;

class BoardRenderController extends Controller
{
    /**
     * The board drawn for the renderer (BoardRender), with nothing round it:
     * one frame or everything as a picture, or every frame a page. Reached
     * only by a signed link, which is the renderer's whole permission.
     */
    public function page(Request $request, Board $board): InertiaResponse
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['picture', 'pages'])],
            'frame' => ['nullable', 'string', 'max:64'],
        ]);

        $items = BoardRender::itemsForPage($board);

        return Inertia::render('boards/Render', [
            'items' => $items,
            'mode' => $validated['mode'],
            'frame' => $validated['frame'] ?? null,
            'frames' => array_column(BoardParts::frames($items), 'id'),
        ]);
    }

    /**
     * The board as a PDF download: a page for each frame.
     */
    public function pdf(Board $board): Response|JsonResponse
    {
        return $this->send($board, 'pdf', fn () => BoardRender::pdf($board));
    }

    /**
     * The whole board as a PNG download.
     */
    public function png(Board $board): Response|JsonResponse
    {
        return $this->send($board, 'png', fn () => BoardRender::png($board));
    }

    /**
     * @param  callable(): string  $render
     */
    private function send(Board $board, string $type, callable $render): Response|JsonResponse
    {
        Gate::authorize('view', $board);

        // What everyone with it open has drawn, not what was last saved
        Collab::flush($board);
        $board->refresh();

        try {
            $bytes = $render();
        } catch (ConnectionException|RuntimeException $e) {
            report($e);

            return response()->json([
                'message' => __('Couldn’t draw this board as a :type. Try again in a moment.', ['type' => strtoupper($type)]),
            ], 502);
        }

        // A title like "Q3/Q4 plan" is fine as a title and not as a filename
        $name = str_replace(['/', '\\'], '-', trim($board->title) ?: 'Untitled board').".{$type}";

        return response($bytes, 200, [
            'Content-Type' => $type === 'pdf' ? 'application/pdf' : 'image/png',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $name,
                (Str::slug($board->title) ?: 'board').".{$type}",
            ),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
