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
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
            // A page for each frame the PDF keeps
            'frames' => BoardRender::pages($items),
        ]);
    }

    /**
     * The board as a PDF download: a page for each frame not kept out of it.
     */
    public function pdf(Board $board): Response|JsonResponse
    {
        return $this->send($board, 'pdf', fn () => BoardRender::pdf($board));
    }

    /**
     * A PNG download of one frame, named by id or title -- or, with none
     * asked for, of the whole board.
     */
    public function png(Request $request, Board $board): Response|JsonResponse
    {
        $named = $request->validate([
            'frame' => ['nullable', 'string', 'max:255'],
        ])['frame'] ?? null;

        return $this->send($board, 'png', function () use ($board, $named) {
            if ($named === null) {
                return [BoardRender::png($board), null];
            }

            $frame = BoardParts::frame($board->content['items'] ?? [], $named);

            abort_if($frame === null, 404, __('This board has no frame “:frame”.', ['frame' => $named]));

            return [BoardRender::png($board, $frame), trim((string) ($frame['text'] ?? '')) ?: null];
        });
    }

    /**
     * @param  callable(): (string|array{string, string|null})  $render  the bytes, or
     *                                                                   the bytes and what to add to the name
     */
    private function send(Board $board, string $type, callable $render): Response|JsonResponse
    {
        Gate::authorize('view', $board);

        // What everyone with it open has drawn, not what was last saved
        Collab::flush($board);
        $board->refresh();

        try {
            [$bytes, $part] = (array) $render() + [1 => null];
        } catch (HttpExceptionInterface $e) {
            // Asked for something that isn't there: not the renderer's fault
            throw $e;
        } catch (ConnectionException|RuntimeException $e) {
            report($e);

            return response()->json([
                'message' => __('Couldn’t draw this board as a :type. Try again in a moment.', ['type' => strtoupper($type)]),
            ], 502);
        }

        // A title like "Q3/Q4 plan" is fine as a title and not as a filename
        $title = (trim($board->title) ?: 'Untitled board').($part !== null ? " - {$part}" : '');
        $name = str_replace(['/', '\\'], '-', $title).".{$type}";

        return response($bytes, 200, [
            'Content-Type' => $type === 'pdf' ? 'application/pdf' : 'image/png',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $name,
                (Str::slug($title) ?: 'board').".{$type}",
            ),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
