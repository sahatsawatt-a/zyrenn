<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Each connection's row says how its last test went: worked, failed, or not
 * tested yet -- from a test of the saved connection, or from the try the
 * form made of exactly what was saved.
 */
class ConnectionLastTestTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_connection_never_tested_says_so()
    {
        $user = User::factory()->create();
        Connection::factory()->openrouter()->for($user)->create();

        $this->actingAs($user)->get(route('connections.index'))
            ->assertInertia(fn (Assert $page) => $page->where('connections.0.check', null));
    }

    public function test_testing_a_saved_connection_keeps_how_it_went_without_counting_as_an_edit()
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'bad key']], 401)]);
        $user = User::factory()->create();
        $connection = Connection::factory()->openrouter()->for($user)->create(['updated_at' => now()->subDay()]);
        $edited = $connection->updated_at;

        $this->actingAs($user)->getJson(route('connections.test', $connection))
            ->assertOk()
            ->assertJsonPath('ok', false);

        $connection->refresh();
        $this->assertFalse($connection->check_ok);
        $this->assertNotNull($connection->checked_at);
        $this->assertNotSame('', $connection->check_message);
        $this->assertTrue($connection->updated_at->equalTo($edited));

        $this->actingAs($user)->get(route('connections.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('connections.0.check.ok', false)
                ->has('connections.0.check.at'));
    }

    public function test_the_forms_own_try_is_kept_with_what_was_saved()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('connections.store'), [
            'name' => 'Desk',
            'service' => 'ollama',
            'settings' => ['base_url' => ''],
            'tried' => ['ok' => true, 'message' => 'Works: 3 models.'],
        ])->assertRedirect(route('connections.index'));

        $connection = $user->connections()->sole();
        $this->assertTrue($connection->check_ok);
        $this->assertSame('Works: 3 models.', $connection->check_message);
    }

    public function test_a_test_with_nothing_to_ask_yet_is_not_kept_as_one()
    {
        Http::fake();
        $user = User::factory()->create();
        $member = Connection::factory()->linkedin()->for($user)->create(['settings' => ['client_id' => 'app']]);

        $this->actingAs($user)->getJson(route('connections.test', $member))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNull($member->fresh()->checked_at);

        $this->actingAs($user)->post(route('connections.store'), [
            'name' => 'Member',
            'service' => 'linkedin',
            'settings' => ['client_id' => 'app'],
            'secrets' => ['client_secret' => 'shh'],
            'tried' => ['ok' => true, 'message' => 'Nothing to try yet: once it is saved, sign in with LinkedIn.'],
        ]);

        $this->assertNull($user->connections()->where('name', 'Member')->sole()->checked_at);
    }

    public function test_saving_without_a_try_leaves_it_untested()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('connections.store'), [
            'name' => 'Desk',
            'service' => 'ollama',
            'settings' => ['base_url' => ''],
        ]);

        $this->assertNull($user->connections()->sole()->checked_at);
    }
}
