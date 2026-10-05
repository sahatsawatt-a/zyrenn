<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapPlaceDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.serpapi.key' => 'test-key']);
        Cache::flush();
    }

    /** Yu Garden as Google lists it, plus a namesake across the river. */
    private function yuGardenList(): array
    {
        return ['local_results' => [
            [
                'title' => 'Yu Garden Metro Station',
                'gps_coordinates' => ['latitude' => 31.2331, 'longitude' => 121.4905],
            ],
            [
                'title' => 'Yu Garden',
                'type' => 'Botanical garden',
                'rating' => 4.5,
                'reviews' => 5514,
                'phone' => '+86 21 6326 0830',
                'gps_coordinates' => ['latitude' => 31.22723, 'longitude' => 121.49213],
                'operating_hours' => [
                    'sunday' => '9 AM–4:30 PM', 'monday' => 'Closed', 'tuesday' => '9 AM–4:30 PM',
                    'wednesday' => '9 AM–4:30 PM', 'thursday' => '9 AM–4:30 PM', 'friday' => '9 AM–4:30 PM',
                    'saturday' => '9 AM–4:30 PM',
                ],
            ],
        ]];
    }

    private function ask(array $query = [])
    {
        return $this->actingAs(User::factory()->create())->getJson(route('map-services.place-details', $query + [
            'name' => 'Yu Garden', 'lat' => 31.2272, 'lng' => 121.4921,
        ]));
    }

    public function test_guests_are_sent_to_log_in()
    {
        $this->get(route('map-services.place-details', ['name' => 'x', 'lat' => 1, 'lng' => 1]))
            ->assertRedirect(route('login'));
    }

    public function test_a_place_needs_a_name_and_a_real_point()
    {
        Http::fake();

        $this->ask(['name' => '', 'lat' => 95])->assertUnprocessable()->assertJsonValidationErrors(['name', 'lat']);
        Http::assertNothingSent();
    }

    public function test_without_a_key_it_says_so_and_asks_nobody()
    {
        config(['services.serpapi.key' => null]);
        Http::fake();

        $this->ask()->assertStatus(503)->assertJsonPath('message', 'Place details are off: SERPAPI_KEY is not set.');
        Http::assertNothingSent();
    }

    public function test_it_takes_the_listed_place_nearest_the_point_with_its_hours_monday_first()
    {
        Http::fake(['serpapi.com/*' => Http::response($this->yuGardenList())]);

        $this->ask()
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('title', 'Yu Garden')
            ->assertJsonPath('rating', 4.5)
            ->assertJsonPath('distanceMetres', 4)
            ->assertJsonPath('hours.monday', 'Closed')
            ->assertJson(fn ($json) => $json->where('hours', fn ($hours) => array_key_first($hours->all()) === 'monday')->etc());

        // The key goes to SerpAPI and nowhere else -- and the search is near the point
        Http::assertSent(fn ($request) => $request['api_key'] === 'test-key'
            && $request['engine'] === 'google_maps'
            && $request['ll'] === '@31.227200,121.492100,17z');
    }

    public function test_a_single_place_with_its_hours_as_a_list_comes_out_the_same_shape()
    {
        Http::fake(['serpapi.com/*' => Http::response(['place_results' => [
            'title' => 'Siam Paragon',
            'type' => ['Shopping mall', 'Department store'],
            'gps_coordinates' => ['latitude' => 13.74627, 'longitude' => 100.53479],
            'hours' => [['sunday' => '10 AM–10 PM'], ['monday' => '10 AM–10 PM']],
        ]])]);

        $this->ask(['name' => 'Siam Paragon', 'lat' => 13.7462, 'lng' => 100.5347])
            ->assertOk()
            ->assertJsonPath('type', 'Shopping mall, Department store')
            ->assertJsonPath('hours', ['monday' => '10 AM–10 PM', 'sunday' => '10 AM–10 PM']);
    }

    public function test_an_answer_too_far_from_the_point_is_not_taken_for_the_place()
    {
        Http::fake(['serpapi.com/*' => Http::response(['local_results' => [
            ['title' => 'Yu Garden (the other one)', 'gps_coordinates' => ['latitude' => 31.30, 'longitude' => 121.60]],
        ]])]);

        $this->ask()->assertOk()->assertJsonPath('found', false)->assertJsonMissingPath('hours');
    }

    public function test_the_same_place_asked_again_costs_no_second_search()
    {
        Http::fake(['serpapi.com/*' => Http::response($this->yuGardenList())]);

        $this->ask()->assertJsonPath('found', true);
        $this->ask(['lat' => 31.22721])->assertJsonPath('found', true);

        Http::assertSentCount(1);
    }

    public function test_google_finding_nothing_is_an_answer_but_a_spent_plan_is_a_failure()
    {
        Http::fake(['serpapi.com/*' => Http::sequence()
            ->push(['error' => "Google hasn't returned any results for this query."])
            ->push(['error' => 'Your account has run out of searches.'], 429)]);

        $this->ask()->assertOk()->assertJsonPath('found', false);
        $this->ask(['name' => 'Somewhere else'])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Your account has run out of searches.');
    }
}
