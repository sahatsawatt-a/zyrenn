<?php

namespace App\Support\Workflow\Steps;

use App\Support\Workflow\Template;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * SerpApi's calls, each a kind of step of its own (ServiceKinds names them;
 * this runs them): a search of Google, of Google News, of Google Images, of
 * Google Maps, of YouTube, of any other engine SerpApi has, the places a
 * search can be made from, and what is left of the key's month.
 *
 * SerpApi calls each engine an API, though they are one address told which
 * engine: each has its own things to ask and its own shape of answer, so
 * the ones most used are each a step that gives out the parts worth having,
 * and "any engine" gives out the answer whole.
 *
 * Nothing here changes anything, so a call is simply made again when it
 * has to be. The key goes in the address, which is the only place SerpApi
 * takes it: nothing of the address is ever said in a failure.
 */
final class SerpApiCall implements Step
{
    private const AT = 'https://serpapi.com';

    /** What Google's "from the last" is written as. */
    private const PERIODS = ['hour' => 'qdr:h', 'day' => 'qdr:d', 'week' => 'qdr:w', 'month' => 'qdr:m', 'year' => 'qdr:y'];

    public function run(StepContext $step): StepResult
    {
        return new StepResult(match ($step->node['type']) {
            'serpapi.google' => self::google($step),
            'serpapi.news' => self::news($step),
            'serpapi.images' => self::images($step),
            'serpapi.maps' => self::maps($step),
            'serpapi.youtube' => self::youtube($step),
            'serpapi.search' => self::any($step),
            'serpapi.locations' => self::locations($step),
            'serpapi.account' => self::account($step),

            default => throw new StepFailed("This app doesn't know the SerpApi step \"{$step->node['type']}\"."),
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function google(StepContext $step): array
    {
        $answer = self::search($step, 'google', [
            'q' => SearchSteps::needs($step, 'q', 'nothing to search for'),
            'location' => trim($step->text('location')),
            ...self::spoken($step),
            'tbs' => self::PERIODS[$step->setting('period')] ?? null,
            'start' => self::from($step, 10),
        ]);
        $results = SearchSteps::rows($answer['organic_results'] ?? null, fn (array $one) => [
            'position' => $one['position'] ?? null,
            'title' => $one['title'] ?? null,
            'link' => $one['link'] ?? null,
            'snippet' => $one['snippet'] ?? null,
            'source' => $one['source'] ?? null,
            'date' => $one['date'] ?? null,
        ]);
        $box = is_array($answer['answer_box'] ?? null) ? $answer['answer_box'] : [];
        $known = is_array($answer['knowledge_graph'] ?? null) ? $answer['knowledge_graph'] : null;

        return [
            'results' => $results,
            // What Google answers with itself, above what it found, when it does
            'answer' => collect([$box['answer'] ?? null, $box['snippet'] ?? null, $box['result'] ?? null])->first(fn ($said) => is_string($said) && $said !== ''),
            'knowledge' => $known === null ? null : [
                'title' => $known['title'] ?? null,
                'type' => $known['type'] ?? null,
                'description' => $known['description'] ?? null,
                'website' => $known['website'] ?? null,
            ],
            'questions' => SearchSteps::rows($answer['related_questions'] ?? null, fn (array $one) => [
                'question' => $one['question'] ?? null,
                'snippet' => $one['snippet'] ?? null,
                'link' => $one['link'] ?? null,
            ]),
            'related' => array_values(array_filter(array_column(SearchSteps::rows($answer['related_searches'] ?? null, fn (array $one) => $one), 'query'), is_string(...))),
            'total' => $answer['search_information']['total_results'] ?? null,
            'text' => SearchSteps::words($results, 'link', 'snippet'),
            'id' => $answer['search_metadata']['id'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function news(StepContext $step): array
    {
        $answer = self::search($step, 'google_news', [
            'q' => trim($step->text('q')),
            ...self::spoken($step),
            'so' => $step->setting('sort') === 'date' ? 1 : null,
        ]);
        $results = SearchSteps::rows($answer['news_results'] ?? null, function (array $one) {
            // Several papers on one story come as the story: its first is given
            $told = is_array($one['highlight'] ?? null) ? $one['highlight'] : (is_array($one['stories'][0] ?? null) ? $one['stories'][0] : []);
            $source = $one['source'] ?? $told['source'] ?? null;

            return [
                'position' => $one['position'] ?? null,
                'title' => $one['title'] ?? $told['title'] ?? null,
                'link' => $one['link'] ?? $told['link'] ?? null,
                'source' => is_array($source) ? ($source['name'] ?? null) : $source,
                'date' => $one['iso_date'] ?? $told['iso_date'] ?? $one['date'] ?? $told['date'] ?? null,
                'thumbnail' => $one['thumbnail'] ?? $told['thumbnail'] ?? null,
            ];
        }, SearchSteps::count($step, 'count', 20, 100));

        return [
            'results' => $results,
            'text' => SearchSteps::words($results, 'link', 'source'),
            'id' => $answer['search_metadata']['id'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function images(StepContext $step): array
    {
        $page = max(1, (int) ($step->setting('page') ?: 1));
        $answer = self::search($step, 'google_images', [
            'q' => SearchSteps::needs($step, 'q', 'nothing to search for'),
            ...self::spoken($step),
            'image_type' => in_array($step->setting('kind'), [null, '', 'any'], true) ? null : $step->setting('kind'),
            'ijn' => $page > 1 ? $page - 1 : null,
        ]);

        return [
            'results' => SearchSteps::rows($answer['images_results'] ?? null, fn (array $one) => [
                'position' => $one['position'] ?? null,
                'title' => $one['title'] ?? null,
                'image' => $one['original'] ?? null,
                'width' => $one['original_width'] ?? null,
                'height' => $one['original_height'] ?? null,
                'thumbnail' => $one['thumbnail'] ?? null,
                'link' => $one['link'] ?? null,
                'source' => $one['source'] ?? null,
            ], SearchSteps::count($step, 'count', 20, 100)),
            'id' => $answer['search_metadata']['id'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function maps(StepContext $step): array
    {
        $answer = self::search($step, 'google_maps', [
            'q' => SearchSteps::needs($step, 'q', 'nothing to search for'),
            'type' => 'search',
            'll' => self::around($step),
            'hl' => trim($step->text('hl')),
            'start' => self::from($step, 20),
        ]);
        // One place that is plainly the one meant comes alone, not in a list
        $found = $answer['local_results'] ?? (is_array($answer['place_results'] ?? null) ? [$answer['place_results']] : null);

        return [
            'results' => SearchSteps::rows($found, fn (array $one) => [
                'position' => $one['position'] ?? null,
                'title' => $one['title'] ?? null,
                'address' => $one['address'] ?? null,
                'lat' => $one['gps_coordinates']['latitude'] ?? null,
                'lng' => $one['gps_coordinates']['longitude'] ?? null,
                'rating' => $one['rating'] ?? null,
                'reviews' => $one['reviews'] ?? null,
                'type' => $one['type'] ?? null,
                'price' => $one['price'] ?? null,
                'open' => $one['open_state'] ?? null,
                'phone' => $one['phone'] ?? null,
                'website' => $one['website'] ?? null,
                'place_id' => $one['place_id'] ?? null,
                'thumbnail' => $one['thumbnail'] ?? null,
            ]),
            'id' => $answer['search_metadata']['id'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function youtube(StepContext $step): array
    {
        $answer = self::search($step, 'youtube', [
            'search_query' => SearchSteps::needs($step, 'q', 'nothing to search for'),
            ...self::spoken($step),
        ]);
        $results = SearchSteps::rows($answer['video_results'] ?? null, fn (array $one) => [
            'position' => $one['position_on_page'] ?? null,
            'title' => $one['title'] ?? null,
            'link' => $one['link'] ?? null,
            'id' => $one['video_id'] ?? null,
            'channel' => $one['channel']['name'] ?? null,
            'published' => $one['published_date'] ?? null,
            'views' => $one['views'] ?? null,
            'length' => $one['length'] ?? null,
            'description' => $one['description'] ?? null,
            'thumbnail' => $one['thumbnail']['static'] ?? null,
        ], SearchSteps::count($step, 'count', 20, 100));

        return [
            'results' => $results,
            'text' => SearchSteps::words($results, 'link', 'description'),
            'id' => $answer['search_metadata']['id'] ?? null,
        ];
    }

    /**
     * Any engine, asked as its own page says and given out whole.
     *
     * @return array<string, mixed>
     */
    private static function any(StepContext $step): array
    {
        $engine = strtolower(SearchSteps::needs($step, 'engine', 'no engine: its name, as SerpApi writes it, like google_shopping'));

        if (preg_match('/^[a-z0-9_]+$/', $engine) !== 1) {
            throw new StepFailed('That is not an engine\'s name, as SerpApi writes it: google_shopping, bing, google_scholar.');
        }

        $answer = self::search($step, $engine, [], 'params');
        // What was asked comes back with the answer; the key never should
        unset($answer['search_parameters']['api_key'], $answer['api_key']);

        return ['id' => $answer['search_metadata']['id'] ?? null, 'body' => $answer];
    }

    /**
     * The places a search can be made from, by part of a name. SerpApi
     * answers this for anyone, so it takes no key.
     *
     * @return array<string, mixed>
     */
    private static function locations(StepContext $step): array
    {
        $places = SearchSteps::rows(self::ask('/locations.json', [
            'q' => SearchSteps::needs($step, 'q', 'no place to look for: part of its name, like Chiang Mai'),
            'limit' => SearchSteps::count($step, 'count', 5, 10),
        ]), fn (array $one) => [
            'name' => $one['canonical_name'] ?? $one['name'] ?? null,
            'short' => $one['name'] ?? null,
            'country' => $one['country_code'] ?? null,
            'kind' => $one['target_type'] ?? null,
            // SerpApi writes a place east first, then north
            'lat' => $one['gps'][1] ?? null,
            'lng' => $one['gps'][0] ?? null,
            'reach' => $one['reach'] ?? null,
        ]);

        return ['name' => $places[0]['name'] ?? null, 'places' => $places];
    }

    /**
     * @return array<string, mixed>
     */
    private static function account(StepContext $step): array
    {
        $account = self::ask('/account.json', ['api_key' => SearchSteps::key($step, 'serpapi')]);

        return [
            'plan' => $account['plan_name'] ?? null,
            'left' => $account['total_searches_left'] ?? null,
            'used' => $account['this_month_usage'] ?? null,
            'per_month' => $account['searches_per_month'] ?? null,
            'this_hour' => $account['this_hour_searches'] ?? null,
            'per_hour' => $account['account_rate_limit_per_hour'] ?? null,
            'renews' => $account['plan_renewal_date'] ?? null,
        ];
    }

    /**
     * The country and the language a search is made in, where it says.
     *
     * @return array{gl: string, hl: string}
     */
    private static function spoken(StepContext $step): array
    {
        return ['gl' => strtolower(trim($step->text('gl'))), 'hl' => trim($step->text('hl'))];
    }

    /**
     * Where a later page starts, for an engine that counts in results.
     */
    private static function from(StepContext $step, int $each): ?int
    {
        $page = max(1, (int) ($step->setting('page') ?: 1));

        return $page > 1 ? ($page - 1) * $each : null;
    }

    /**
     * The place a search of the map is made around, as Google Maps writes
     * it: what a message that sent a place gives, or two numbers.
     *
     * @throws StepFailed
     */
    private static function around(StepContext $step): ?string
    {
        $around = $step->template('around');

        if (is_string($around) && trim($around) !== '') {
            $parts = preg_split('/[\s,]+/', trim($around), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $around = ['lat' => $parts[0] ?? null, 'lng' => $parts[1] ?? null, 'more' => count($parts) > 2];
        }

        if (! is_array($around)) {
            return null;
        }

        $lat = $around['lat'] ?? $around['latitude'] ?? null;
        $lng = $around['lng'] ?? $around['longitude'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lng) || ($around['more'] ?? false) === true) {
            throw new StepFailed('A place to search around is two numbers, its latitude and its longitude: 13.7563, 100.5018.');
        }

        $zoom = max(3, min(21, (int) ($step->setting('zoom') ?: 14)));

        return '@'.(float) $lat.','.(float) $lng.",{$zoom}z";
    }

    /**
     * A search of one engine: what the step's own settings come to, over
     * anything else it was told to ask.
     *
     * @param  array<string, mixed>  $asks
     * @return array<string, mixed>
     *
     * @throws StepFailed
     */
    private static function search(StepContext $step, string $engine, array $asks, string $more = 'more'): array
    {
        $key = SearchSteps::key($step, 'serpapi');
        $told = array_map(fn ($value) => Template::text($value), $step->fields($more));
        // An answer is waited for, and read as JSON, whatever it is told
        unset($told['async'], $told['output'], $told['api_key'], $told['engine']);

        return self::ask('/search.json', [
            ...$told,
            ...array_filter($asks, fn ($value) => $value !== null && $value !== ''),
            'engine' => $engine,
            'api_key' => $key,
        ]);
    }

    /**
     * SerpApi's answer, or why there is none worth having. A search that
     * finds nothing is an answer: it comes with SerpApi saying so, and is
     * given out as nothing found.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws StepFailed
     */
    private static function ask(string $path, array $query): array
    {
        try {
            $response = Http::timeout(60)->acceptJson()->get(self::AT.$path, $query);
        } catch (ConnectionException) {
            throw new StepFailed('Could not reach SerpApi.', retryable: true);
        }

        $answer = $response->json();
        $said = is_array($answer) && is_string($answer['error'] ?? null) ? mb_strimwidth($answer['error'], 0, 300, '…') : null;

        if ($response->successful() && is_array($answer)) {
            // Only a search that broke off before it was done is a failure
            return ($answer['search_metadata']['status'] ?? null) === 'Error'
                ? throw new StepFailed('SerpApi could not finish the search'.($said === null ? '.' : ": {$said}"), retryable: true)
                : $answer;
        }

        throw match (true) {
            $response->status() === 401 => new StepFailed('SerpApi refused the key: check it on the connection.'),
            // Out of searches until the month turns, which waiting a minute does not mend
            $response->status() === 429 && preg_match('/run out|ran out/i', (string) $said) === 1 => new StepFailed("SerpApi said: {$said}"),
            $response->status() === 429 => new StepFailed('SerpApi asked to slow down: the key\'s searches for this hour are used.', retryable: true, retryAfter: 60),
            default => new StepFailed('SerpApi said: '.($said ?? "nothing it could be read by ({$response->status()})."), retryable: $response->serverError()),
        };
    }
}
