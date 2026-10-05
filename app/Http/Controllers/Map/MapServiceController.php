<?php

namespace App\Http\Controllers\Map;

use App\Http\Controllers\Controller;
use App\Support\Maps\MapStyle;
use App\Support\Maps\Photon;
use App\Support\Maps\PlaceDetails;
use App\Support\Maps\Valhalla;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * What the map asks other services for -- place search, routes, Google's
 * details, the map style -- asked from here, so the app is named to them, the
 * SerpAPI key never reaches a browser, and each answer is kept for the next
 * person to ask the same.
 */
class MapServiceController extends Controller
{
    /**
     * Places matching what has been typed, nearest the map's middle first.
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return $this->answer(fn () => ['hits' => Photon::search($data['q'], (float) $data['lat'], (float) $data['lng'])]);
    }

    /**
     * What is at a point.
     */
    public function reverse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return $this->answer(fn () => ['hit' => Photon::reverse((float) $data['lat'], (float) $data['lng'])]);
    }

    /**
     * The way through some stops, by car, bike or on foot.
     */
    public function route(Request $request): JsonResponse
    {
        $data = $this->stops($request, 2, Valhalla::MAX_STOPS);

        return $this->answer(fn () => Valhalla::route($data['stops'], $data['costing']), refused: 422);
    }

    /**
     * Many routes at once -- a trip's days and its airport rides -- asked of
     * the router side by side. Each comes back by the name it was asked
     * under: its route, or why there isn't one.
     */
    public function routes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'jobs' => ['required', 'array', 'min:1', 'max:'.Valhalla::MAX_JOBS],
            'jobs.*.key' => ['required', 'string', 'max:80', 'distinct'],
            'jobs.*.costing' => ['required', Rule::in(Valhalla::COSTINGS)],
            'jobs.*.stops' => ['required', 'array', 'min:2', 'max:'.Valhalla::MAX_STOPS],
            'jobs.*.stops.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'jobs.*.stops.*.lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $jobs = [];

        foreach ($data['jobs'] as $job) {
            $jobs[(string) $job['key']] = [
                'stops' => array_values(array_map(fn (array $stop) => ['lat' => (float) $stop['lat'], 'lng' => (float) $stop['lng']], $job['stops'])),
                'costing' => (string) $job['costing'],
            ];
        }

        return response()->json(['routes' => Valhalla::routes($jobs)]);
    }

    /**
     * The quickest order to visit some stops in, first and last held.
     */
    public function quickest(Request $request): JsonResponse
    {
        $data = $this->stops($request, 4, Valhalla::MAX_ORDERED);

        return $this->answer(fn () => ['order' => Valhalla::quickestOrder($data['stops'], $data['costing'])], refused: 422);
    }

    /**
     * Google's opening hours, rating and the like for a place.
     */
    public function placeDetails(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        if (! PlaceDetails::configured()) {
            return response()->json(['message' => __('Place details are off: SERPAPI_KEY is not set.')], 503);
        }

        return $this->answer(fn () => PlaceDetails::lookup($data['name'], (float) $data['lat'], (float) $data['lng']));
    }

    /**
     * A map style, trimmed and with today's tile address, for MapLibre.
     */
    public function style(string $name): JsonResponse
    {
        return $this->answer(fn () => MapStyle::get($name))
            // Rebuilt daily on the server; a browser may keep it an hour
            ->setPublic()->setMaxAge(3600);
    }

    /**
     * @return array{stops: list<array{lat: float, lng: float}>, costing: string}
     */
    private function stops(Request $request, int $min, int $max): array
    {
        $data = $request->validate([
            'stops' => ['required', 'array', "min:{$min}", "max:{$max}"],
            'stops.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'stops.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'costing' => ['required', Rule::in(Valhalla::COSTINGS)],
        ]);

        return [
            'stops' => array_values(array_map(fn (array $stop) => ['lat' => (float) $stop['lat'], 'lng' => (float) $stop['lng']], $data['stops'])),
            'costing' => (string) $data['costing'],
        ];
    }

    /**
     * An answer from elsewhere, or why there isn't one: 502 when the service
     * failed, or `refused` when it said no to the request itself.
     *
     * @param  callable(): mixed  $ask
     */
    private function answer(callable $ask, int $refused = 502): JsonResponse
    {
        try {
            return response()->json($ask());
        } catch (RuntimeException $failure) {
            return response()->json(['message' => $failure->getMessage()], $refused);
        }
    }
}
