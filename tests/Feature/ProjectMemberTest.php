<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who is in a project: owners add, change and remove members, anyone can
 * leave, and a project always keeps an owner.
 */
class ProjectMemberTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->project = Project::factory()->withMember($this->owner)->create();
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $this->project->members()->attach($user, ['role' => $role]);

        return $user;
    }

    private function membershipOf(User $user): Membership
    {
        return $this->project->memberships()->where('user_id', $user->id)->sole();
    }

    public function test_every_membership_gets_a_ref_id_of_its_own()
    {
        $this->assertMatchesRegularExpression('/^[a-z0-9]{10}$/', $this->membershipOf($this->owner)->ref_id);
    }

    public function test_an_owner_adds_someone_by_email()
    {
        $newcomer = User::factory()->create(['email' => 'ada@example.com']);

        $this->actingAs($this->owner)
            ->post(route('projects.members.store', $this->project), ['email' => 'ada@example.com', 'role' => Project::EDITOR])
            ->assertSessionHasNoErrors();

        $this->assertSame(Project::EDITOR, $this->project->roleOf($newcomer));
    }

    public function test_adding_needs_an_account_and_someone_not_already_in()
    {
        $this->actingAs($this->owner)
            ->post(route('projects.members.store', $this->project), ['email' => 'nobody@example.com', 'role' => Project::VIEWER])
            ->assertSessionHasErrors(['email' => 'Nobody has an account with that email address.']);

        $this->actingAs($this->owner)
            ->post(route('projects.members.store', $this->project), ['email' => $this->owner->email, 'role' => Project::VIEWER])
            ->assertSessionHasErrors('email');

        $this->actingAs($this->owner)
            ->post(route('projects.members.store', $this->project), ['email' => User::factory()->create()->email, 'role' => 'admin'])
            ->assertSessionHasErrors('role');
    }

    public function test_only_owners_manage_members()
    {
        $editor = $this->member(Project::EDITOR);
        $viewer = $this->member(Project::VIEWER);

        $this->actingAs($editor)
            ->post(route('projects.members.store', $this->project), ['email' => User::factory()->create()->email, 'role' => Project::VIEWER])
            ->assertForbidden();

        $this->actingAs($editor)
            ->patch(route('projects.members.update', [$this->project, $this->membershipOf($viewer)]), ['role' => Project::OWNER])
            ->assertForbidden();

        $this->actingAs($editor)
            ->delete(route('projects.members.destroy', [$this->project, $this->membershipOf($viewer)]))
            ->assertForbidden();

        $this->assertSame(Project::VIEWER, $this->project->roleOf($viewer));
    }

    public function test_an_owner_changes_roles_and_hands_the_project_over()
    {
        $editor = $this->member(Project::EDITOR);

        $this->actingAs($this->owner)
            ->patch(route('projects.members.update', [$this->project, $this->membershipOf($editor)]), ['role' => Project::OWNER])
            ->assertSessionHasNoErrors();

        // With another owner, the first can step down
        $this->actingAs($this->owner)
            ->patch(route('projects.members.update', [$this->project, $this->membershipOf($this->owner)]), ['role' => Project::VIEWER])
            ->assertSessionHasNoErrors();

        $this->assertSame(Project::OWNER, $this->project->roleOf($editor));
        $this->assertSame(Project::VIEWER, $this->project->roleOf($this->owner));
    }

    public function test_the_last_owner_cant_step_down_or_leave()
    {
        $this->member(Project::EDITOR);
        $mine = $this->membershipOf($this->owner);

        $this->actingAs($this->owner)
            ->patch(route('projects.members.update', [$this->project, $mine]), ['role' => Project::EDITOR])
            ->assertSessionHasErrors('role');

        $this->actingAs($this->owner)
            ->delete(route('projects.members.destroy', [$this->project, $mine]))
            ->assertSessionHasErrors('member');

        $this->assertSame(Project::OWNER, $this->project->roleOf($this->owner));
    }

    public function test_an_owner_removes_a_member()
    {
        $viewer = $this->member(Project::VIEWER);

        $this->actingAs($this->owner)
            ->delete(route('projects.members.destroy', [$this->project, $this->membershipOf($viewer)]))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->project->roleOf($viewer));
        $this->actingAs($viewer)->get(route('projects.notes.index', $this->project))->assertForbidden();
    }

    public function test_anyone_can_leave()
    {
        $viewer = $this->member(Project::VIEWER);

        $this->actingAs($viewer)
            ->delete(route('projects.members.destroy', [$this->project, $this->membershipOf($viewer)]))
            ->assertRedirect(route('notes.index'));

        $this->assertNull($this->project->roleOf($viewer));
    }

    public function test_a_membership_is_found_only_in_its_own_project()
    {
        $other = Project::factory()->withMember($this->owner)->create();
        $elsewhere = $other->memberships()->sole();

        $this->actingAs($this->owner)
            ->delete(route('projects.members.destroy', [$this->project, $elsewhere]))
            ->assertNotFound();
    }
}
