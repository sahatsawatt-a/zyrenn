<?php

namespace App\Http\Controllers\Board;

use App\Events\PrototypeCommentChanged;
use App\Http\Controllers\Controller;
use App\Models\Board\Board;
use App\Models\Prototype\PrototypeComment;
use App\Models\Ticket\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Comments on a prototype, pinned to spots on its screens: read and made by
 * anyone in its project -- a viewer above all, who is who a prototype is
 * shown to -- resolved by the one who wrote it or anyone who can change the
 * prototype, and made a ticket on the project when they are work to do.
 * Each change is told live on the board's channel.
 */
class PrototypeCommentController extends Controller
{
    public function index(Board $board): JsonResponse
    {
        Gate::authorize('view', $board);
        abort_unless($board->isPrototype(), 404);

        return response()->json([
            'comments' => $board->prototypeComments()
                ->with(['user:id,name', 'ticket:id,number,status'])
                ->oldest()
                ->get()
                ->map(fn (PrototypeComment $comment) => $comment->shown())
                ->values(),
        ]);
    }

    public function store(Request $request, Board $board): JsonResponse
    {
        Gate::authorize('view', $board);
        abort_unless($board->isPrototype(), 404);

        $valid = $request->validate([
            'screen' => ['required', 'string', 'max:64'],
            'x' => ['required', 'numeric', 'between:0,1'],
            'y' => ['required', 'numeric', 'between:0,1'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        abort_unless(in_array($valid['screen'], array_column(PrototypeController::screens($board), 'id'), true), 422, __('That screen is not on this prototype.'));

        $comment = new PrototypeComment([
            'frame_id' => $valid['screen'],
            'x' => (float) $valid['x'],
            'y' => (float) $valid['y'],
            'body' => trim($valid['body']),
        ]);
        $comment->board_id = $board->id;
        $comment->user_id = $request->user()->id;
        $comment->save();

        return $this->told($board, $comment, 201);
    }

    /**
     * Resolved or open again, by its writer or anyone who can change the
     * prototype; its words changed, by its writer alone.
     */
    public function update(Request $request, PrototypeComment $comment): JsonResponse
    {
        $board = $comment->board;
        Gate::authorize('view', $board);

        $valid = $request->validate([
            'resolved' => ['sometimes', 'boolean'],
            'body' => ['sometimes', 'string', 'max:2000'],
        ]);
        $mine = $comment->user_id === $request->user()->id;

        if (array_key_exists('body', $valid)) {
            abort_unless($mine, 403);
            $comment->body = trim((string) $valid['body']);
        }

        if (array_key_exists('resolved', $valid)) {
            abort_unless($mine || $request->user()->can('update', $board), 403);
            $comment->resolved_at = $valid['resolved'] ? ($comment->resolved_at ?? now()) : null;
        }

        $comment->save();

        return $this->told($board, $comment);
    }

    /**
     * Work to do: a ticket on the prototype's project, saying what was said
     * and where, linked to the comment. Asked again, the one already made.
     */
    public function ticket(Request $request, PrototypeComment $comment): JsonResponse
    {
        $board = $comment->board;
        $project = $board->project;
        abort_if($project === null, 404);
        Gate::authorize('contribute', $project);

        if ($comment->ticket_id === null) {
            $screen = collect(PrototypeController::screens($board))->firstWhere('id', $comment->frame_id);
            $where = route('prototypes.play', $board).'#'.rawurlencode($comment->frame_id);

            $ticket = Ticket::file($project, $request->user(), [
                'type' => Ticket::FEATURE,
                'title' => Str::limit(Str::squish($comment->body), 120, '…'),
                'unit' => 'Prototypes',
                'body' => $comment->body
                    ."\n\nFrom a comment on the prototype \"{$board->title}\", screen \"".($screen['title'] ?? $comment->frame_id).'"'
                    .($comment->user_id !== null ? ', by '.$comment->user?->name : '')
                    .": {$where}",
            ]);

            $comment->ticket_id = $ticket->id;
            $comment->save();
        }

        return $this->told($board, $comment);
    }

    /** What changed, told live, and answered with as the screens show it. */
    private function told(Board $board, PrototypeComment $comment, int $status = 200): JsonResponse
    {
        $comment->load(['user:id,name', 'ticket:id,number,status']);
        broadcast(new PrototypeCommentChanged($board, $comment))->toOthers();

        return response()->json(['comment' => $comment->shown()], $status);
    }
}
