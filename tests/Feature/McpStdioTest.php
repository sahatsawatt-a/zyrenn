<?php

namespace Tests\Feature;

use App\Mcp\Servers\LocalGlobalServer;
use App\Mcp\Servers\LocalUserServer;
use App\Mcp\Tools\Notes\GetNote;
use App\Mcp\Tools\Notes\ListNotes;
use App\Mcp\Tools\Users\ListUsers;
use App\Models\Note\Note;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Request;
use RuntimeException;
use Tests\TestCase;

class McpStdioTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('MCP_TOKEN');

        parent::tearDown();
    }

    public function test_local_user_server_acts_as_the_token_owner()
    {
        $user = User::factory()->create();
        Note::factory()->for($user)->create();
        $other = Note::factory()->create();
        $token = $user->createToken('stdio', ['mcp']);
        putenv('MCP_TOKEN='.$token->plainTextToken);

        LocalUserServer::tool(ListNotes::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->has('notes', 1)->where('notes.0.user_id', $user->id));

        LocalUserServer::tool(GetNote::class, ['ref_id' => $other->ref_id])->assertHasErrors();

        $this->assertNotNull($token->accessToken->refresh()->last_used_at);
    }

    public function test_local_user_server_rejects_missing_or_invalid_tokens()
    {
        $user = User::factory()->create();

        foreach ([null, 'nope', $user->createToken('api', ['something-else'])->plainTextToken] as $value) {
            putenv($value === null ? 'MCP_TOKEN' : 'MCP_TOKEN='.$value);

            try {
                LocalUserServer::tool(ListNotes::class);
                $this->fail('Expected the server to refuse to start.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('MCP_TOKEN', $exception->getMessage());
            }
        }
    }

    public function test_revoking_the_token_cuts_off_a_running_session()
    {
        $user = User::factory()->create();
        $token = $user->createToken('stdio', ['mcp']);
        putenv('MCP_TOKEN='.$token->plainTextToken);

        // Boot the server once (as a long-running stdio process would)...
        LocalUserServer::tool(ListNotes::class)->assertOk();

        // ...then revoke the token: later calls in the same process must fail
        $token->accessToken->delete();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('This MCP token is no longer valid.');
        (new ListNotes)->handle(new Request);
    }

    public function test_local_global_server_follows_the_global_token_switch()
    {
        config(['services.mcp.global_token' => 'secret']);
        $user = User::factory()->create();

        LocalGlobalServer::tool(ListUsers::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('users.0.id', $user->id)->etc());

        config(['services.mcp.global_token' => null]);
        $this->expectException(RuntimeException::class);
        LocalGlobalServer::tool(ListUsers::class);
    }
}
