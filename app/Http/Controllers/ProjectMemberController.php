<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Who is in a project and in what role. Owners add, change and remove
 * members; anyone can leave. A project always keeps at least one owner.
 */
class ProjectMemberController extends Controller
{
    /**
     * Add someone with an account to the project, by their email address.
     */
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('manageMembers', $project);

        $validated = $request->validate([
            'email' => ['required', 'email', Rule::exists('users', 'email')],
            'role' => ['required', Rule::in(Project::ROLES)],
        ], [
            'email.exists' => __('Nobody has an account with that email address.'),
        ]);

        $user = User::query()->where('email', $validated['email'])->firstOrFail();

        if ($project->roleOf($user) !== null) {
            throw ValidationException::withMessages(['email' => __(':name is already in the project.', ['name' => $user->name])]);
        }

        $project->members()->attach($user, ['role' => $validated['role']]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Added :name.', ['name' => $user->name])]);

        return back();
    }

    /**
     * Change a member's role -- making someone an owner is how a project is
     * handed over.
     */
    public function update(Request $request, Project $project, Membership $membership): RedirectResponse
    {
        Gate::authorize('manageMembers', $project);

        $validated = $request->validate([
            'role' => ['required', Rule::in(Project::ROLES)],
        ]);

        if ($validated['role'] !== Project::OWNER && $project->isLastOwner($membership)) {
            throw ValidationException::withMessages(['role' => __('A project needs an owner. Make someone else an owner first.')]);
        }

        $membership->update($validated);

        return back();
    }

    /**
     * Remove a member, or leave the project yourself.
     */
    public function destroy(Request $request, Project $project, Membership $membership): RedirectResponse
    {
        $leaving = $membership->user_id === $request->user()->id;

        if (! $leaving) {
            Gate::authorize('manageMembers', $project);
        }

        if ($project->isLastOwner($membership)) {
            throw ValidationException::withMessages(['member' => $leaving
                ? __('You are its only owner. Make someone else an owner first, or delete the project.')
                : __('A project needs an owner.')]);
        }

        $membership->delete();

        if ($leaving) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('You left :name.', ['name' => $project->name])]);

            return to_route('notes.index');
        }

        return back();
    }
}
