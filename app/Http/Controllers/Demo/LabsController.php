<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Models\Board\Board;
use App\Support\Board\BoardItemProblem;
use App\Support\Board\BoardItems;
use App\Support\Board\CircuitLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * What the labs page asks of the server: things being tried out before they
 * are settled.
 */
class LabsController extends Controller
{
    /**
     * A circuit written as text, laid out here on the server and put on a
     * new board of the person's own -- what create-board does with "circuit",
     * tried without an AI client.
     */
    public function board(Request $request): RedirectResponse
    {
        $validated = $request->validate(['circuit' => ['required', 'string', 'max:8000']]);

        try {
            $specs = CircuitLayout::specs($validated['circuit']);
        } catch (BoardItemProblem $problem) {
            throw ValidationException::withMessages(['circuit' => $problem->getMessage()]);
        }

        $board = (new Board([
            'title' => 'Labs: a circuit laid out by the server',
            'content' => ['items' => BoardItems::fromSpec($specs)],
        ]))->ownedBy($request->user(), $request->user());
        $board->save();

        return to_route('boards.show', $board);
    }
}
