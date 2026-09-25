<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class McpTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_mcp_settings_page_lists_only_mcp_tokens()
    {
        $user = User::factory()->create();
        $user->createToken('Claude', ['mcp']);
        $user->createToken('Other API', ['something-else']);

        $this->actingAs($user)
            ->get(route('mcp.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Mcp')
                ->where('endpoint', url('/mcp/user'))
                ->has('tokens', 1)
                ->where('tokens.0.name', 'Claude'));
    }

    public function test_user_can_create_an_mcp_token()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('mcp.tokens.store'), ['name' => 'Laptop'])
            ->assertRedirect(route('mcp.edit'))
            ->assertInertiaFlash('mcpToken');

        $token = $user->tokens()->sole();
        $this->assertSame('Laptop', $token->name);
        $this->assertSame(['mcp'], $token->abilities);
    }

    public function test_token_name_is_required()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('mcp.tokens.store'), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    public function test_user_can_revoke_only_their_own_tokens()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = $user->createToken('Mine', ['mcp'])->accessToken;
        $theirs = $other->createToken('Theirs', ['mcp'])->accessToken;

        $this->actingAs($user)->delete(route('mcp.tokens.destroy', $theirs->id));
        $this->assertModelExists($theirs);

        $this->actingAs($user)
            ->delete(route('mcp.tokens.destroy', $mine->id))
            ->assertRedirect(route('mcp.edit'));
        $this->assertModelMissing($mine);
    }

    public function test_tokens_are_deleted_with_their_user()
    {
        $user = User::factory()->create();
        $token = $user->createToken('Claude', ['mcp'])->accessToken;

        $user->delete();

        $this->assertModelMissing($token);
    }
}
