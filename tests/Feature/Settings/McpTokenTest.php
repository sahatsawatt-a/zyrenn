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

    /** What the personal server answers `$method` with, for a key. */
    private function mcp(string $token, string $method, array $params = []): array
    {
        // As a client calls, by its key alone: not as whoever is signed in to the page
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)
            ->postJson('/mcp/user', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params])
            ->assertOk()
            ->json('result') ?? [];
    }

    /**
     * Every tool the personal server offers a key, page by page as a client reads them.
     *
     * @return list<string>
     */
    private function toolsFor(string $token): array
    {
        $names = [];
        $cursor = null;

        do {
            $page = $this->mcp($token, 'tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
            $names = [...$names, ...array_column($page['tools'] ?? [], 'name')];
            $cursor = $page['nextCursor'] ?? null;
        } while ($cursor !== null);

        sort($names);

        return $names;
    }

    public function test_a_key_offers_only_the_tools_of_the_parts_chosen_for_it()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('mcp.tokens.store'), ['name' => 'Reader', 'parts' => [
            'notes' => 'read', 'boards' => 'change', 'drive' => 'off',
        ]])->assertRedirect(route('mcp.edit'));
        $token = $user->tokens()->sole();
        $this->assertEqualsCanonicalizing(['mcp', 'mcp:scoped', 'notes:read', 'boards:change'], $token->abilities);

        $plain = $user->createToken('Same', $token->abilities)->plainTextToken;
        $names = $this->toolsFor($plain);

        // Notes to read, boards to read and change, nothing else
        $this->assertContains('get-note', $names);
        $this->assertNotContains('create-note', $names);
        $this->assertContains('update-board', $names);
        $this->assertNotContains('list-drive', $names);
        $this->assertNotContains('list-tickets', $names);

        // A tool left out cannot be called either
        $answer = $this->withToken($plain)->postJson('/mcp/user', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'create-note', 'arguments' => ['title' => 'No']]])->json();
        $this->assertTrue(isset($answer['error']) || ($answer['result']['isError'] ?? false));
        $this->assertSame(0, $user->notes()->count());

        // And what the server says leaves out the parts it may not see
        $instructions = (string) ($this->mcp($plain, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']])['instructions'] ?? '');
        $this->assertStringContainsString('- Notes:', $instructions);
        $this->assertStringNotContainsString('- Drive:', $instructions);
        $this->assertStringNotContainsString('- Tickets', $instructions);
    }

    public function test_a_keys_access_is_changed_later_and_an_old_key_does_everything()
    {
        $user = User::factory()->create();
        $old = $user->createToken('Old', ['mcp']);

        $this->assertContains('create-note', $this->toolsFor($old->plainTextToken));
        $this->actingAs($user)->get(route('mcp.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('tokens.0.parts.drive', 'change')->has('tools.notes'));

        $this->patch(route('mcp.tokens.update', $old->accessToken->id), ['parts' => ['tables' => 'read']])->assertRedirect(route('mcp.edit'));
        $this->assertEqualsCanonicalizing(['mcp', 'mcp:scoped', 'tables:read'], $old->accessToken->fresh()->abilities);
        $this->assertSame(['get-table', 'list-table-folders', 'list-tables'], $this->toolsFor($old->plainTextToken));

        // Someone else's key is not theirs to change
        $theirs = User::factory()->create()->createToken('Theirs', ['mcp'])->accessToken;
        $this->patch(route('mcp.tokens.update', $theirs->id), ['parts' => ['notes' => 'off']])->assertNotFound();
        $this->assertSame(['mcp'], $theirs->fresh()->abilities);
    }

    public function test_tokens_are_deleted_with_their_user()
    {
        $user = User::factory()->create();
        $token = $user->createToken('Claude', ['mcp'])->accessToken;

        $user->delete();

        $this->assertModelMissing($token);
    }
}
