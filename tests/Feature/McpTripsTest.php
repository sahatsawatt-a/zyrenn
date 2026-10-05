<?php

namespace Tests\Feature;

use App\Mcp\Servers\GlobalServer;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Places\DeletePlace;
use App\Mcp\Tools\Places\ListPlaces;
use App\Mcp\Tools\Places\SavePlace;
use App\Mcp\Tools\Places\SearchPlaces;
use App\Mcp\Tools\Trips\CreateTrip;
use App\Mcp\Tools\Trips\DeleteTrip;
use App\Mcp\Tools\Trips\GetTrip;
use App\Mcp\Tools\Trips\ListTripFolders;
use App\Mcp\Tools\Trips\ListTrips;
use App\Mcp\Tools\Trips\UpdateTrip;
use App\Models\Map\Place;
use App\Models\Map\Trip;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * The trip and saved-place tools: a trip made, read a part at a time, and
 * changed by id, as an assistant planning one would.
 */
class McpTripsTest extends TestCase
{
    use RefreshDatabase;

    private const HOTEL = ['name' => 'Hotel Indigo', 'lat' => 31.2347, 'lng' => 121.4972];

    /**
     * Three days in Shanghai: in through Pudong, a hotel for the whole stay,
     * home from Hongqiao.
     */
    private function shanghai(User $user, array $also = []): Trip
    {
        UserServer::actingAs($user)->tool(CreateTrip::class, [
            'title' => 'Shanghai',
            'start_date' => '2026-11-03',
            'currency' => '¥',
            'stays' => [[...self::HOTEL, 'check_in' => '2026-11-03T15:00', 'check_out' => '2026-11-06T11:00', 'cost' => 1800]],
            'arrival' => ['airport' => ['name' => 'Pudong Airport', 'lat' => 31.1443, 'lng' => 121.8083], 'at' => '2026-11-03T10:00'],
            'departure' => ['airport' => ['name' => 'Hongqiao Airport', 'lat' => 31.1979, 'lng' => 121.3363], 'at' => '2026-11-05T18:00'],
            'days' => [
                ['stops' => [['name' => 'The Bund', 'lat' => 31.2397, 'lng' => 121.4906, 'minutes' => 90]]],
                ['mode' => 'auto', 'start' => '10:00', 'stops' => [
                    ['name' => 'Yu Garden', 'lat' => 31.2272, 'lng' => 121.4921],
                    ['rest' => 'hotel'],
                ]],
                ['stops' => [['name' => 'Xintiandi', 'lat' => 31.2196, 'lng' => 121.4747]]],
            ],
            ...$also,
        ])->assertOk();

        return $user->trips()->sole();
    }

    /**
     * A stop's id, by its name.
     */
    private function stopId(Trip $trip, string $name): string
    {
        foreach ($trip->refresh()->content['days'] as $day) {
            foreach ($day['stops'] as $stop) {
                if ($stop['name'] === $name) {
                    return $stop['id'];
                }
            }
        }

        $this->fail("No stop \"{$name}\".");
    }

    public function test_create_trip_lays_out_its_days_hotels_and_flights()
    {
        $user = User::factory()->create();
        $trip = $this->shanghai($user, ['folder' => 'Travel/2026']);
        $doc = $trip->content;

        $this->assertSame('Travel', $trip->folder->parent->name);
        $this->assertSame(['2026-11-03', '¥', 3], [$doc['startDate'], $doc['currency'], count($doc['days'])]);
        $this->assertSame(['auto', '10:00'], [$doc['days'][1]['mode'], $doc['days'][1]['start']]);
        $this->assertSame(['pedestrian', '09:00'], [$doc['days'][0]['mode'], $doc['days'][0]['start']]);
        $this->assertSame(['Pudong Airport', '2026-11-03T10:00'], [$doc['arrival']['airport']['name'], $doc['arrival']['at']]);
        $this->assertSame(['Hotel Indigo', '2026-11-06T11:00'], [$doc['stays'][0]['place']['name'], $doc['stays'][0]['checkOut']]);

        // A rest at the hotel is at the hotel
        $rest = $doc['days'][1]['stops'][1];
        $this->assertSame(['Rest at Hotel Indigo', 'hotel', 90], [$rest['name'], $rest['rest'], $rest['minutes']]);
        $this->assertEquals([self::HOTEL['lat'], self::HOTEL['lng']], [$rest['lat'], $rest['lng']]);

        $this->assertStringContainsString('The Bund', $trip->plain_text);
    }

    public function test_a_place_with_nowhere_to_be_is_refused_and_no_trip_is_made()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)->tool(CreateTrip::class, [
            'title' => 'Lost',
            'days' => [['stops' => [['name' => 'Nowhere']]]],
        ])->assertHasErrors(['"Nowhere" needs a "lat" and "lng" -- search-places finds them.']);

        UserServer::actingAs($user)->tool(CreateTrip::class, [
            'title' => 'No hotel',
            'days' => [['stops' => [['rest' => 'hotel']]]],
        ])->assertHasErrors(['Day 1 has no hotel to rest at: book one with add_stays first.']);

        $this->assertSame(0, $user->trips()->count());
    }

    public function test_get_trip_reads_the_outline_or_one_day_with_its_route()
    {
        $user = User::factory()->create();
        $trip = $this->shanghai($user);
        $day = $trip->content['days'][0];

        UserServer::actingAs($user)->tool(GetTrip::class, ['ref_id' => $trip->ref_id])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('title', 'Shanghai')
                ->where('days.1.date', '2026-11-04')
                ->where('days.0.stops.0.name', 'The Bund')
                ->where('days.1.stops.1.rest', 'hotel')
                ->where('stays.0.check_in', '2026-11-03T15:00')
                ->where('departure.airport.name', 'Hongqiao Airport')
                ->where('stays.0.cost', 1800)
                ->where('totals.stays_cost', 1800)
                ->where('totals.total_cost', 1800)
                ->where('totals.nights', 3)
                ->where('days.0.cost', 0)
                ->missing('days.0.legs')
                ->etc());

        // Day 1: off the plane, check in, the Bund, back to sleep
        UserServer::actingAs($user)->tool(GetTrip::class, ['ref_id' => $trip->ref_id, 'day' => '1'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('id', $day['id'])
                ->where('route.0.as', 'arrival airport')
                ->where('route.1.as', 'hotel')
                ->where('route.2.name', 'The Bund')
                ->where('route.3.as', 'hotel')
                ->has('rides', 3)
                ->where('rides.1.to', $day['stops'][0]['id'])
                ->where('rides.1.by_hand', null)
                ->where('stops.0.minutes', 90)
                ->etc());

        // The last day flies home from the airport rather than sleeping
        UserServer::actingAs($user)->tool(GetTrip::class, ['ref_id' => $trip->ref_id, 'day' => $trip->content['days'][2]['id']])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('day', 3)
                ->where('route.2.as', 'departure airport')
                ->etc());

        UserServer::actingAs($user)->tool(GetTrip::class, ['ref_id' => $trip->ref_id, 'day' => '9'])
            ->assertHasErrors(['There is no day 9: the trip has 3.']);
    }

    public function test_update_trip_changes_days_stops_and_rides_by_id()
    {
        $user = User::factory()->create();
        $trip = $this->shanghai($user);
        $revision = $trip->revision;
        $bund = $this->stopId($trip, 'The Bund');
        $xintiandi = $this->stopId($trip, 'Xintiandi');
        $hotel = $trip->content['stays'][0]['place']['id'];

        // Days are named as they were before the call: "3" is still Xintiandi's day once day 2 is gone
        UserServer::actingAs($user)->tool(UpdateTrip::class, [
            'ref_id' => $trip->ref_id,
            'title' => 'Shanghai, again',
            'delete_days' => ['2'],
            'add_stops' => [['day' => '1', 'name' => "Jing'an Temple", 'lat' => 31.2234, 'lng' => 121.4458]],
            'update_stops' => [['id' => $bund, 'day' => '3', 'position' => 1, 'note' => 'At dusk']],
            'add_days' => [['stops' => [['name' => 'Zhujiajiao', 'lat' => 31.1124, 'lng' => 121.0563, 'minutes' => 180]]]],
            'set_rides' => [
                ['from' => $bund, 'to' => $xintiandi, 'mode' => 'metro', 'minutes' => 25, 'cost' => 4, 'note' => 'Line 10'],
                ['from' => $hotel, 'to' => $bund, 'mode' => 'walk', 'minutes' => 10],
            ],
        ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('title', 'Shanghai, again')
            ->has('made.days', 1)
            ->has('made.stops', 2)
            ->etc());

        $doc = $trip->refresh()->content;
        $names = fn (array $day) => array_column($day['stops'], 'name');

        $this->assertSame([["Jing'an Temple"], ['The Bund', 'Xintiandi'], ['Zhujiajiao']], array_map($names, $doc['days']));
        $this->assertSame('At dusk', $doc['days'][1]['stops'][0]['note']);
        $this->assertSame(['metro', 25, 'Line 10'], [
            $doc['days'][1]['legs']["{$bund}>{$xintiandi}"]['mode'],
            $doc['days'][1]['legs']["{$bund}>{$xintiandi}"]['minutes'],
            $doc['days'][1]['legs']["{$bund}>{$xintiandi}"]['note'],
        ]);
        $this->assertSame('walk', $doc['days'][1]['legs']["{$hotel}>{$bund}"]['mode']);
        $this->assertSame($revision + 1, $trip->revision);

        UserServer::actingAs($user)->tool(UpdateTrip::class, [
            'ref_id' => $trip->ref_id,
            'clear_rides' => [['from' => $bund, 'to' => $xintiandi]],
        ])->assertOk();

        $this->assertSame(["{$hotel}>{$bund}"], array_keys($trip->refresh()->content['days'][1]['legs']));
    }

    public function test_a_ride_between_places_not_side_by_side_is_refused_and_nothing_changes()
    {
        $user = User::factory()->create();
        $trip = $this->shanghai($user);
        $revision = $trip->revision;

        UserServer::actingAs($user)->tool(UpdateTrip::class, [
            'ref_id' => $trip->ref_id,
            'title' => 'Changed',
            'set_rides' => [['from' => $this->stopId($trip, 'The Bund'), 'to' => $this->stopId($trip, 'Xintiandi'), 'mode' => 'taxi', 'minutes' => 20]],
        ])->assertHasErrors();

        UserServer::actingAs($user)->tool(UpdateTrip::class, [
            'ref_id' => $trip->ref_id,
            'delete_stops' => ['nothere'],
        ])->assertHasErrors(['There is no stop "nothere". get-trip lists each day\'s stops. Nothing was changed.']);

        $this->assertSame(['Shanghai', $revision], [$trip->refresh()->title, $trip->revision]);
    }

    public function test_hotels_and_flights_change_by_id_and_field()
    {
        $user = User::factory()->create();
        $trip = $this->shanghai($user);
        $stay = $trip->content['stays'][0]['id'];

        UserServer::actingAs($user)->tool(UpdateTrip::class, [
            'ref_id' => $trip->ref_id,
            'update_stays' => [['id' => $stay, 'check_out' => '2026-11-05T12:00']],
            'add_stays' => [['name' => 'Water Town Inn', 'lat' => 31.11, 'lng' => 121.05, 'check_in' => '2026-11-05T14:00', 'check_out' => '2026-11-06T10:00']],
            'arrival' => ['clear_minutes' => 90],
            'departure' => ['airport' => null],
        ])->assertOk();

        $doc = $trip->refresh()->content;

        $this->assertSame(['2026-11-05T12:00', 'Water Town Inn'], [$doc['stays'][0]['checkOut'], $doc['stays'][1]['place']['name']]);
        // Only the fields given change
        $this->assertSame([90, '2026-11-03T10:00'], [$doc['arrival']['clearMinutes'], $doc['arrival']['at']]);
        $this->assertNull($doc['departure']['airport']);
        $this->assertSame('2026-11-05T18:00', $doc['departure']['at']);
    }

    public function test_trips_are_listed_and_deleted_and_a_viewer_only_reads_a_project_trip()
    {
        $user = User::factory()->create();
        $trip = $this->shanghai($user, ['folder' => 'Travel']);

        UserServer::actingAs($user)->tool(ListTrips::class, ['search' => 'xintiandi'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('trips', 1)
                ->where('trips.0.ref_id', $trip->ref_id)
                ->where('trips.0.days', 3)
                ->where('trips.0.start_date', '2026-11-03')
                ->where('trips.0.folder', 'Travel')
                ->etc());

        UserServer::actingAs($user)->tool(ListTripFolders::class)
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('folders.0.path', 'Travel')
                ->where('folders.0.trips_count', 1)
                ->etc());

        // The admin server reaches it as that user
        GlobalServer::tool(ListTrips::class, ['user_id' => $user->id])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->has('trips', 1)->etc());

        UserServer::actingAs($user)->tool(DeleteTrip::class, ['ref_id' => $trip->ref_id])->assertOk();
        $this->assertSame(0, $user->trips()->count());

        $project = Project::factory()->create(['name' => 'Away day']);
        $viewer = User::factory()->create();
        $project->members()->attach($viewer, ['role' => Project::VIEWER]);
        $shared = (new Trip(['title' => 'Offsite']))->ownedBy($project, $user);
        $shared->save();

        UserServer::actingAs($viewer)->tool(GetTrip::class, ['ref_id' => $shared->ref_id, 'project' => 'Away day'])->assertOk();
        UserServer::actingAs($viewer)->tool(UpdateTrip::class, ['ref_id' => $shared->ref_id, 'project' => 'Away day', 'title' => 'Mine'])
            ->assertHasErrors();
        $this->assertSame('Offsite', $shared->refresh()->title);
    }

    public function test_places_are_saved_moved_listed_and_deleted()
    {
        $user = User::factory()->create();
        $server = UserServer::actingAs($user);

        // With no list named, the first -- made as "Saved" when there are none
        $server->tool(SavePlace::class, ['name' => 'Yu Garden', 'lat' => 31.2272, 'lng' => 121.4921])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('list', 'Saved')->etc());
        $server->tool(SavePlace::class, ['name' => 'Din Tai Fung', 'lat' => 31.23, 'lng' => 121.47, 'list' => 'Food', 'note' => 'Xiaolongbao'])->assertOk();

        $this->assertSame(['Saved', 'Food'], $user->placeLists()->orderBy('sort_order')->pluck('name')->all());

        $garden = Place::query()->where('name', 'Yu Garden')->sole();
        $server->tool(SavePlace::class, ['ref_id' => $garden->ref_id, 'list' => 'food', 'note' => 'Go early'])->assertOk();

        $this->assertSame(['Food', 'Go early', 'Yu Garden'], [$garden->refresh()->list->name, $garden->note, $garden->name]);

        $server->tool(ListPlaces::class, ['list' => 'Food', 'search' => 'xiaolong'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('places', 1)
                ->where('places.0.name', 'Din Tai Fung')
                ->where('lists.1.places', 2)
                ->etc());

        $server->tool(SavePlace::class, ['name' => 'No coordinates'])->assertHasErrors();
        $server->tool(DeletePlace::class, ['ref_id' => $garden->ref_id])->assertOk();
        $this->assertSame(1, $user->places()->count());
    }

    public function test_a_projects_places_point_at_the_projects_map()
    {
        $user = User::factory()->create();
        $project = Project::start($user, 'Shanghai');
        $server = UserServer::actingAs($user);

        $server->tool(SavePlace::class, ['project' => 'Shanghai', 'name' => 'Yu Garden', 'lat' => 31.2272, 'lng' => 121.4921])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('url', route('projects.maps.index', $project))->etc());
        $server->tool(ListPlaces::class, ['project' => 'Shanghai'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('url', route('projects.maps.index', $project))->etc());

        // The user's own still point at theirs
        $server->tool(ListPlaces::class)
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('url', route('maps.index'))->etc());
    }

    public function test_a_trip_can_be_made_of_saved_places_and_follows_them()
    {
        $user = User::factory()->create();
        $server = UserServer::actingAs($user);
        $server->tool(SavePlace::class, ['name' => 'Yu Garden', 'lat' => 31.2272, 'lng' => 121.4921, 'kind' => 'tourism/attraction'])->assertOk();
        $server->tool(SavePlace::class, ['name' => 'Inn', 'lat' => 31.23, 'lng' => 121.47])->assertOk();
        [$garden, $inn] = [Place::query()->where('name', 'Yu Garden')->sole(), Place::query()->where('name', 'Inn')->sole()];

        $server->tool(CreateTrip::class, [
            'title' => 'From my places',
            'start_date' => '2026-11-03',
            'stays' => [['place' => $inn->ref_id, 'check_in' => '2026-11-03T15:00', 'check_out' => '2026-11-04T11:00']],
            'days' => [['stops' => [['place' => $garden->ref_id, 'minutes' => 90]]]],
        ])->assertOk();

        $doc = $user->trips()->sole()->content;
        $stop = $doc['days'][0]['stops'][0];

        $this->assertSame(['Yu Garden', 31.2272, 90, $garden->ref_id], [$stop['name'], $stop['lat'], $stop['minutes'], $stop['placeRef']]);
        $this->assertSame([$inn->ref_id, 'tourism/hotel'], [$doc['stays'][0]['place']['placeRef'], $doc['stays'][0]['place']['kind']]);

        // Renamed on the map, the trip says so when it is read
        $garden->update(['name' => 'Yuyuan Garden']);
        $server->tool(GetTrip::class, ['ref_id' => $user->trips()->sole()->ref_id, 'day' => '1'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('stops.0.name', 'Yuyuan Garden')->etc());

        $server->tool(CreateTrip::class, ['title' => 'Nowhere', 'days' => [['stops' => [['place' => 'nothere']]]]])
            ->assertHasErrors(['There is no saved place "nothere". list-places shows them.']);
    }

    public function test_search_places_finds_where_a_place_is()
    {
        Http::fake(['photon.komoot.io/*' => Http::response(['features' => [[
            'geometry' => ['coordinates' => [121.4921, 31.2272]],
            'properties' => ['name' => 'Yu Garden', 'street' => 'Anren Street', 'city' => 'Shanghai', 'country' => 'China', 'osm_key' => 'tourism', 'osm_value' => 'attraction'],
        ]]])]);

        UserServer::actingAs(User::factory()->create())->tool(SearchPlaces::class, ['query' => 'Yu Garden'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('places.0.name', 'Yu Garden')
                ->where('places.0.lat', 31.2272)
                ->where('places.0.address', 'Anren Street, Shanghai, China')
                ->etc());

        // Asked of anywhere, it says nowhere in particular
        Http::assertSent(fn (HttpRequest $request) => ! str_contains($request->url(), 'lat='));
    }
}
