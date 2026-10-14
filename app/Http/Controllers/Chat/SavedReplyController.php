<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\SavedReply;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A project's saved replies: read by anyone in it, kept by its owners and
 * editors. Answered as JSON, for the chat's composer to show in place.
 */
class SavedReplyController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        return response()->json(['replies' => $this->listed($project)]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('contribute', $project);

        if (SavedReply::where('project_id', $project->id)->count() >= SavedReply::MOST) {
            throw ValidationException::withMessages(['title' => __('A project keeps at most :most saved replies.', ['most' => SavedReply::MOST])]);
        }

        $reply = new SavedReply($this->validated($request));
        $reply->project_id = $project->id;
        $reply->created_by = $request->user()->id;
        $reply->save();

        return response()->json(['reply' => $reply->shown(), 'replies' => $this->listed($project)], 201);
    }

    public function update(Request $request, Project $project, SavedReply $reply): JsonResponse
    {
        Gate::authorize('contribute', $project);
        abort_unless($reply->project_id === $project->id, 404);

        $reply->update($this->validated($request));

        return response()->json(['reply' => $reply->shown(), 'replies' => $this->listed($project)]);
    }

    public function destroy(Project $project, SavedReply $reply): JsonResponse
    {
        Gate::authorize('contribute', $project);
        abort_unless($reply->project_id === $project->id, 404);

        $reply->delete();

        return response()->json(['replies' => $this->listed($project)]);
    }

    /**
     * @return array{title: string, body: string}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:'.SavedReply::MAX_TITLE],
            'body' => ['required', 'string', 'max:'.SavedReply::MAX_BODY],
        ]);

        return ['title' => trim($validated['title']), 'body' => trim($validated['body'])];
    }

    /**
     * @return list<array{ref_id: string, title: string, body: string}>
     */
    private function listed(Project $project): array
    {
        return array_values(SavedReply::where('project_id', $project->id)->orderBy('title')->get()->map->shown()->all());
    }
}
