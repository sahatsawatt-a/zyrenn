<?php

namespace Tests\Feature;

use App\Events\TripChanged;
use App\Models\Map\Place;
use App\Models\Map\PlaceList;
use App\Models\Map\Trip;
use App\Models\Project;
use App\Models\User;
use App\Support\Maps\TripDocument;
use App\Support\Maps\Valhalla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    // ---- trips ----

    public function test_guests_are_sent_to_log_in()
    {
        $this->get(route('trips.index'))->assertRedirect(route('login'));
        $this->get(route('maps.index'))->assertRedirect(route('login'));
    }

    public function test_a_new_trip_has_a_day_to_plan_in_and_opens()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('trips.store'));

        $trip = $user->trips()->sole();
        $response->assertRedirect(route('trips.show', $trip));
        $this->assertSame('New trip', $trip->title);
        $this->assertCount(1, $trip->content['days']);
        $this->assertSame($user->id, $trip->created_by);
    }

    public function test_a_plan_brought_along_is_kept_in_its_known_shape()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('trips.store'), [
            'title' => 'Shanghai',
            'content' => [
                'startDate' => '2026-11-13',
                'currency' => '¥',
                'stray' => 'dropped',
                'days' => [[
                    'mode' => 'teleport',
                    'start' => '25:99',
                    'stops' => [
                        ['name' => 'Yu Garden', 'lat' => 31.2272, 'lng' => 121.4921, 'minutes' => 120, 'cost' => '40'],
                        ['name' => 'Nowhere', 'lat' => 999, 'lng' => 0],
                        ['name' => 'Rest', 'rest' => 'here', 'minutes' => 30],
                    ],
                    'legs' => ['a>b' => ['mode' => 'metro', 'minutes' => 20, 'cost' => 4, 'note' => 'Line 2'], 'bad key' => []],
                ]],
            ],
        ]);

        $trip = $user->trips()->sole();
        $day = $trip->content['days'][0];

        $this->assertArrayNotHasKey('stray', $trip->content);
        $this->assertSame('pedestrian', $day['mode']);
        $this->assertSame('09:00', $day['start']);
        // The stop with nowhere to be is left out; the rest needs no place
        $this->assertSame(['Yu Garden', 'Rest'], array_column($day['stops'], 'name'));
        $this->assertSame(40.0, (float) $day['stops'][0]['cost']);
        $this->assertSame(['a>b'], array_keys($day['legs']));
        $this->assertStringContainsString('Yu Garden', (string) $trip->plain_text);
    }

    public function test_saving_counts_revisions_and_turns_away_a_save_from_an_old_copy()
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create();
        $this->assertSame(0, $trip->revision);

        $this->actingAs($user)
            ->patchJson(route('trips.update', $trip), ['title' => 'Shanghai', 'revision' => 0])
            ->assertOk()
            ->assertJsonPath('revision', 1);

        // Another tab, still on revision 0, would undo that
        $this->actingAs($user)
            ->patchJson(route('trips.update', $trip), ['title' => 'Old', 'revision' => 0])
            ->assertStatus(409)
            ->assertJsonPath('trip.title', 'Shanghai')
            ->assertJsonPath('trip.revision', 1);

        $this->assertSame('Shanghai', $trip->fresh()->title);
    }

    public function test_hotels_are_kept_in_the_order_they_are_stayed_in()
    {
        $hotel = fn (string $name, string $checkIn) => ['place' => ['name' => $name, 'lat' => 31.2, 'lng' => 121.4], 'checkIn' => $checkIn, 'checkOut' => ''];

        $doc = TripDocument::normalize(['stays' => [
            $hotel('Not booked yet', ''),
            $hotel('Water town', '2026-11-05T15:00'),
            $hotel('First', '2026-11-03T15:00'),
        ]]);

        $this->assertSame(['First', 'Water town', 'Not booked yet'], array_column(array_column($doc['stays'], 'place'), 'name'));
    }

    public function test_saving_a_trip_tells_whoever_else_has_it_open()
    {
        Event::fake([TripChanged::class]);
        $user = User::factory()->create(['name' => 'Ada']);
        $trip = Trip::factory()->for($user)->create();

        $this->actingAs($user)->patchJson(route('trips.update', $trip), ['title' => 'Shanghai', 'revision' => 0])->assertOk();

        Event::assertDispatched(TripChanged::class, fn (TripChanged $event) => $event->change === 'saved'
            && $event->broadcastWith() === ['change' => 'saved', 'revision' => 1, 'edited_by' => 'Ada']
            && $event->broadcastOn()->name === "presence-trips.{$trip->ref_id}");

        // Nothing new, nothing to tell
        $this->actingAs($user)->patchJson(route('trips.update', $trip), ['title' => 'Shanghai', 'revision' => 1])->assertOk();
        Event::assertDispatchedTimes(TripChanged::class, 1);

        $trip->delete();
        Event::assertDispatched(TripChanged::class, fn (TripChanged $event) => $event->change === 'deleted');
    }

    public function test_a_trips_totals_count_its_stops_the_rides_on_its_routes_and_each_hotel_once()
    {
        $doc = TripDocument::normalize([
            'startDate' => '2026-11-03',
            'currency' => '¥',
            'stays' => [['id' => 'inn', 'place' => ['name' => 'Inn', 'lat' => 31.23, 'lng' => 121.49], 'checkIn' => '2026-11-03T15:00', 'checkOut' => '2026-11-05T11:00', 'cost' => 900]],
            'days' => [
                ['stops' => [
                    ['id' => 'a', 'name' => 'A', 'lat' => 31.24, 'lng' => 121.49, 'cost' => 50],
                    ['id' => 'b', 'name' => 'B', 'lat' => 31.22, 'lng' => 121.47, 'cost' => 20],
                ], 'legs' => [
                    'a>b' => ['mode' => 'metro', 'minutes' => 20, 'cost' => 4],
                    'inn>a' => ['mode' => 'walk', 'minutes' => 10, 'cost' => 0],
                    // Left over from a route since changed: not on it, not counted
                    'b>a' => ['mode' => 'taxi', 'minutes' => 15, 'cost' => 99],
                ]],
                ['stops' => [['name' => 'Rest', 'rest' => 'here', 'minutes' => 30]]],
            ],
        ]);
        $doc['stays'][0]['place']['id'] = 'inn';

        $totals = TripDocument::totals($doc);

        $this->assertSame([70.0, 4.0, 900.0, 974.0], [$totals['stops_cost'], $totals['rides_cost'], $totals['stays_cost'], $totals['total_cost']]);
        $this->assertSame(['2026-11-03', '2026-11-04', 2, 2], [$totals['start_date'], $totals['end_date'], $totals['day_count'], $totals['nights']]);
        $this->assertSame([74.0, 0.0], array_column($totals['days'], 'cost'));
        // A rest isn't a place to see
        $this->assertSame([2, 0], array_column($totals['days'], 'stops'));
    }

    public function test_a_trip_is_offered_to_a_note_and_read_there_with_its_totals()
    {
        $user = User::factory()->create();
        Trip::factory()->for($user)->create(['title' => 'Kyoto']);
        $trip = Trip::factory()->for($user)->create([
            'title' => 'Shanghai',
            'content' => ['startDate' => '2026-11-03', 'days' => [['stops' => [['name' => 'Bund', 'lat' => 31.24, 'lng' => 121.49, 'cost' => 50]]]]],
        ]);

        $this->actingAs($user)->getJson(route('trips.pick', ['q' => 'shang']))
            ->assertOk()
            ->assertJsonCount(1, 'trips')
            ->assertJsonPath('trips.0.ref_id', $trip->ref_id);

        $this->actingAs($user)->getJson(route('trips.content', $trip))
            ->assertOk()
            ->assertJsonPath('title', 'Shanghai')
            ->assertJsonPath('content.days.0.stops.0.name', 'Bund')
            ->assertJsonPath('totals.total_cost', 50)
            ->assertJsonPath('totals.days.0.date', '2026-11-03');

        // A note shared with someone doesn't open the trip it shows to them
        $this->actingAs(User::factory()->create())->getJson(route('trips.content', $trip))->assertForbidden();
    }

    public function test_a_saved_place_on_a_trip_follows_the_place_and_only_the_owners()
    {
        $user = User::factory()->create();
        $mine = Place::factory()->for(PlaceList::factory()->for($user), 'list')->create(['name' => 'Old name', 'lat' => 31.1, 'lng' => 121.1]);
        $theirs = Place::factory()->create(['name' => 'Not yours', 'lat' => 10, 'lng' => 10]);
        $trip = Trip::factory()->for($user)->create(['content' => ['startDate' => '2026-11-03', 'days' => [['stops' => [
            // Written before it was called placeRef
            ['name' => 'Old name', 'lat' => 31.1, 'lng' => 121.1, 'savedId' => $mine->ref_id],
            ['name' => 'Kept copy', 'lat' => 31.3, 'lng' => 121.3, 'placeRef' => $theirs->ref_id],
        ]]]]]);

        $mine->update(['name' => 'Renamed on the map', 'lat' => 31.2, 'lng' => 121.2]);

        $this->actingAs($user)->getJson(route('trips.content', $trip))
            ->assertOk()
            ->assertJsonPath('content.days.0.stops.0.placeRef', $mine->ref_id)
            ->assertJsonPath('content.days.0.stops.0.name', 'Renamed on the map')
            ->assertJsonPath('content.days.0.stops.0.lat', 31.2)
            // Someone else's place is never read through: the copy stands
            ->assertJsonPath('content.days.0.stops.1.name', 'Kept copy');

        // Gone from the map, the stop keeps the copy it had
        $mine->delete();
        $this->actingAs($user)->getJson(route('trips.content', $trip))
            ->assertJsonPath('content.days.0.stops.0.name', 'Old name');
    }

    public function test_someone_elses_trip_is_not_theirs_to_see_or_change()
    {
        $trip = Trip::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('trips.show', $trip))->assertForbidden();
        $this->actingAs($stranger)->patchJson(route('trips.update', $trip), ['title' => 'Mine'])->assertForbidden();
        $this->actingAs($stranger)->delete(route('trips.destroy', $trip))->assertForbidden();
    }

    public function test_a_projects_viewers_see_its_trips_and_only_editors_change_them()
    {
        $project = Project::factory()->create();
        $trip = Trip::factory()->create(['user_id' => null, 'project_id' => $project->id]);
        $viewer = User::factory()->create();
        $editor = User::factory()->create();
        $project->members()->attach($viewer, ['role' => Project::VIEWER]);
        $project->members()->attach($editor, ['role' => Project::EDITOR]);

        $this->actingAs($viewer)->get(route('trips.show', $trip))->assertOk();
        $this->actingAs($viewer)->patchJson(route('trips.update', $trip), ['title' => 'No'])->assertForbidden();
        $this->actingAs($editor)->patchJson(route('trips.update', $trip), ['title' => 'Yes'])->assertOk();
        $this->actingAs($viewer)->post(route('projects.trips.store', $project))->assertForbidden();
    }

    public function test_deleting_a_trip_goes_back_to_the_list()
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create();

        $this->actingAs($user)->delete(route('trips.destroy', $trip))->assertRedirect(route('trips.index'));
        $this->assertModelMissing($trip);
    }

    // ---- saved places ----

    public function test_lists_take_the_next_colour_and_places_go_in_ones_own_list()
    {
        $user = User::factory()->create();

        $first = $this->actingAs($user)->postJson(route('place-lists.store'), ['name' => 'Saved'])->assertCreated();
        $second = $this->actingAs($user)->postJson(route('place-lists.store'), ['name' => 'Want to go'])->assertCreated();
        $this->assertNotSame($first->json('list.color'), $second->json('list.color'));

        $this->actingAs($user)->postJson(route('places.store'), [
            'list' => $first->json('list.ref_id'),
            'name' => 'Siam Paragon',
            'lat' => 13.7462,
            'lng' => 100.5347,
        ])->assertCreated()->assertJsonPath('place.list', $first->json('list.ref_id'));

        // Someone else's list is not one to save into
        $theirs = PlaceList::factory()->create();
        $this->actingAs($user)->postJson(route('places.store'), [
            'list' => $theirs->ref_id, 'name' => 'x', 'lat' => 0, 'lng' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('list');

        $this->assertSame(1, $user->places()->count());
        $this->assertSame($user->id, $user->places()->sole()->user_id);
    }

    public function test_a_deleted_list_hands_its_places_to_another()
    {
        $user = User::factory()->create();
        $keep = PlaceList::factory()->for($user)->create(['sort_order' => 0]);
        $gone = PlaceList::factory()->for($user)->create(['sort_order' => 1]);
        $place = Place::factory()->create(['list_id' => $gone->id]);

        $this->actingAs($user)->deleteJson(route('place-lists.destroy', $gone))
            ->assertOk()
            ->assertJsonPath('moved_to', $keep->ref_id);

        $this->assertSame($keep->id, $place->fresh()->list_id);
        $this->assertModelMissing($gone);
    }

    public function test_saved_places_are_offered_wherever_a_place_is_chosen()
    {
        $user = User::factory()->create();
        $list = PlaceList::factory()->for($user)->create(['name' => 'Food', 'color' => '#10b981']);
        Place::factory()->for($list, 'list')->create(['name' => 'Din Tai Fung']);
        Place::factory()->create(['name' => 'Someone else’s']);

        $this->actingAs($user)->getJson(route('places.pick'))
            ->assertOk()
            ->assertJsonCount(1, 'places')
            ->assertJsonPath('places.0.name', 'Din Tai Fung')
            ->assertJsonPath('places.0.list', 'Food')
            ->assertJsonPath('places.0.color', '#10b981');
    }

    public function test_someone_elses_place_is_not_theirs_to_change()
    {
        $place = Place::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patchJson(route('places.update', $place), ['name' => 'Mine'])
            ->assertForbidden();
    }

    // ---- other services, through us ----

    public function test_search_asks_photon_once_and_names_the_app()
    {
        Http::fake(['photon.komoot.io/*' => Http::response(['features' => [[
            'geometry' => ['coordinates' => [121.4921, 31.2272]],
            'properties' => ['name' => 'Yu Garden', 'street' => 'Anren Street', 'city' => 'Shanghai', 'country' => 'China', 'osm_key' => 'tourism', 'osm_value' => 'attraction'],
        ]]])]);

        $user = User::factory()->create();
        $ask = fn () => $this->actingAs($user)->getJson(route('map-services.search', ['q' => 'Yu Garden', 'lat' => 31.23, 'lng' => 121.47]));

        $ask()->assertOk()
            ->assertJsonPath('hits.0.name', 'Yu Garden')
            ->assertJsonPath('hits.0.address', 'Anren Street, Shanghai, China')
            ->assertJsonPath('hits.0.kind', 'tourism/attraction');
        $ask()->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_starts_with($request->header('User-Agent')[0] ?? '', 'ZyrenN-Maps/'));
    }

    public function test_a_route_comes_back_as_plain_points_with_its_legs()
    {
        // Two legs: (13.0, 100.0) -> (13.1, 100.1) -> (13.2, 100.2)
        Http::fake(['valhalla1.openstreetmap.de/route' => Http::response(['trip' => [
            'summary' => ['length' => 30.5, 'time' => 2400],
            'legs' => [
                ['shape' => self::encode([[13.0, 100.0], [13.1, 100.1]]), 'summary' => ['length' => 15, 'time' => 1200], 'maneuvers' => [['instruction' => 'Drive north.', 'length' => 15, 'time' => 1200, 'begin_shape_index' => 0]]],
                ['shape' => self::encode([[13.1, 100.1], [13.2, 100.2]]), 'summary' => ['length' => 15.5, 'time' => 1200], 'maneuvers' => [['instruction' => 'Arrive.', 'length' => 0, 'time' => 0, 'begin_shape_index' => 1]]],
            ],
        ]])]);

        $route = $this->actingAs(User::factory()->create())
            ->postJson(route('map-services.route'), [
                'stops' => [['lat' => 13.0, 'lng' => 100.0], ['lat' => 13.1, 'lng' => 100.1], ['lat' => 13.2, 'lng' => 100.2]],
                'costing' => 'auto',
            ])
            ->assertOk()
            ->assertJsonPath('km', 30.5)
            ->assertJsonCount(2, 'legs')
            ->assertJsonPath('steps.1.instruction', 'Arrive.');

        // [lng, lat] -- the GeoJSON way round -- whole numbers coming back as such in JSON
        $this->assertEquals([100.0, 13.0], $route->json('line.0'));
        $this->assertEquals([100.2, 13.2], $route->json('line.3'));

        Http::assertSent(fn ($request) => $request->header('X-Client-Id')[0] === Valhalla::CLIENT_ID);
    }

    public function test_a_trips_routes_come_in_one_batch_cached_ones_without_asking()
    {
        $route = ['trip' => [
            'summary' => ['length' => 2.0, 'time' => 600],
            'legs' => [['shape' => self::encode([[13.0, 100.0], [13.01, 100.01]]), 'summary' => ['length' => 2.0, 'time' => 600], 'maneuvers' => []]],
        ]];
        Http::fake(['valhalla1.openstreetmap.de/route' => Http::sequence()
            ->push($route)
            ->push($route)
            ->push(['error' => 'No path could be found for input'], 400)]);

        $user = User::factory()->create();
        $a = [['lat' => 13.0, 'lng' => 100.0], ['lat' => 13.01, 'lng' => 100.01]];
        $b = [['lat' => 13.1, 'lng' => 100.1], ['lat' => 13.11, 'lng' => 100.11]];
        $c = [['lat' => 13.2, 'lng' => 100.2], ['lat' => 31.1, 'lng' => 121.8]];

        // One already known: it comes from the cache
        $this->actingAs($user)->postJson(route('map-services.route'), ['stops' => $a, 'costing' => 'pedestrian'])->assertOk();

        $this->actingAs($user)->postJson(route('map-services.routes'), ['jobs' => [
            ['key' => 'day1', 'stops' => $a, 'costing' => 'pedestrian'],
            ['key' => 'day1:in', 'stops' => $b, 'costing' => 'auto'],
            ['key' => 'day2', 'stops' => $c, 'costing' => 'pedestrian'],
        ]])
            ->assertOk()
            ->assertJsonPath('routes.day1.seconds', 600)
            ->assertJsonPath('routes.day1:in.km', 2)
            // One that can't be routed says so, and the others still come
            ->assertJsonPath('routes.day2.error', 'No path could be found for input');

        // The cached one wasn't asked for again: one single, two in the batch
        Http::assertSentCount(3);
    }

    public function test_the_router_saying_no_is_passed_on_in_its_words()
    {
        Http::fake(['valhalla1.openstreetmap.de/*' => Http::response(['error' => 'No path could be found for input'], 400)]);

        $this->actingAs(User::factory()->create())
            ->postJson(route('map-services.route'), [
                'stops' => [['lat' => 13.0, 'lng' => 100.0], ['lat' => 31.1, 'lng' => 121.8]],
                'costing' => 'pedestrian',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No path could be found for input');
    }

    public function test_a_map_style_is_served_trimmed_with_todays_tiles_and_kept_when_they_are_down()
    {
        Http::fake([
            'tiles.openfreemap.org/styles/liberty' => Http::sequence()
                ->push([
                    'version' => 8,
                    'sources' => [
                        'ne2_shaded' => ['type' => 'raster', 'tiles' => ['https://tiles.openfreemap.org/natural_earth/ne2sr/{z}/{x}/{y}.png']],
                        'openmaptiles' => ['type' => 'vector', 'url' => 'https://tiles.openfreemap.org/planet'],
                    ],
                    'layers' => [
                        ['id' => 'relief', 'type' => 'raster', 'source' => 'ne2_shaded'],
                        ['id' => 'water', 'type' => 'symbol', 'source' => 'openmaptiles', 'layout' => ['text-font' => ['Noto Sans Italic']]],
                        ['id' => 'background', 'type' => 'background'],
                        ['id' => 'shield', 'type' => 'symbol', 'source' => 'openmaptiles', 'filter' => ['all', ['<=', ['get', 'ref_length'], 6], ['==', ['get', 'class'], 'motorway']]],
                    ],
                ])
                ->push('down', 503),
            'tiles.openfreemap.org/planet' => Http::response(['tiles' => ['https://tiles.openfreemap.org/planet/20261001_pt/{z}/{x}/{y}.pbf'], 'maxzoom' => 14]),
        ]);

        $user = User::factory()->create();
        $style = $this->actingAs($user)->getJson(route('map-services.style', 'liberty'))->assertOk();

        $this->assertArrayNotHasKey('ne2_shaded', $style->json('sources'));
        $this->assertSame(['https://tiles.openfreemap.org/planet/20261001_pt/{z}/{x}/{y}.pbf'], $style->json('sources.openmaptiles.tiles'));
        $this->assertSame(['water', 'background', 'shield'], array_column($style->json('layers'), 'id'));
        $this->assertSame(['Noto Sans Regular'], $style->json('layers.0.layout.text-font'));
        // A road without a ref_length isn't measured against one, so MapLibre needn't warn of it
        $this->assertSame(
            ['all', ['all', ['has', 'ref_length'], ['<=', ['get', 'ref_length'], 6]], ['==', ['get', 'class'], 'motorway']],
            $style->json('layers.2.filter'),
        );

        // A day on, OpenFreeMap is down: yesterday's style still draws a map
        Cache::forget('maps:style:liberty');
        $this->actingAs($user)->getJson(route('map-services.style', 'liberty'))
            ->assertOk()
            ->assertJsonPath('sources.openmaptiles.tiles.0', 'https://tiles.openfreemap.org/planet/20261001_pt/{z}/{x}/{y}.pbf');
    }

    /**
     * Points as an encoded polyline, six decimal places -- what Valhalla sends.
     *
     * @param  list<array{float, float}>  $points  [lat, lng]
     */
    private static function encode(array $points): string
    {
        $out = '';
        $last = [0, 0];

        foreach ($points as $point) {
            foreach ([0, 1] as $axis) {
                $value = (int) round($point[$axis] * 1e6);
                $delta = $value - $last[$axis];
                $last[$axis] = $value;
                $delta = $delta < 0 ? ~($delta << 1) : $delta << 1;

                while ($delta >= 0x20) {
                    $out .= chr((0x20 | ($delta & 0x1F)) + 63);
                    $delta >>= 5;
                }

                $out .= chr($delta + 63);
            }
        }

        return $out;
    }
}
