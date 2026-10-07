<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LinkPreviewTest extends TestCase
{
    use RefreshDatabase;

    /** A public address, so no name has to be looked up. */
    private const SITE = 'https://93.184.215.14';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::fake([
            '93.184.215.14/article' => Http::response(<<<'HTML'
                <html><head>
                <title>Ignored when og:title is there</title>
                <meta property="og:title" content="A &amp; B, explained">
                <meta name="description" content="  What it is,
                   in short. ">
                <meta property="og:site_name" content="The Paper">
                <meta property="og:image" content="/pictures/cover.png">
                <link rel="icon" href="/favicon.png">
                </head><body><meta property="og:title" content="not this"></body></html>
                HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']),
            '93.184.215.14/pictures/cover.png' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
            '93.184.215.14/drawing.svg' => Http::response('<svg onload="alert(1)"/>', 200, ['Content-Type' => 'image/svg+xml']),
            '93.184.215.14/clip.mp4' => Http::response('....', 200, ['Content-Type' => 'video/mp4']),
            '93.184.215.14/inside' => Http::response('', 302, ['Location' => 'http://10.0.0.5/admin']),
            'maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://www.google.com/maps/place/Wat+Pho/@13.74,100.49,16z/data=!3d13.7465!4d100.4927']),
        ]);
    }

    private function preview(string $url)
    {
        return $this->actingAs(User::factory()->create())
            ->getJson(route('link-preview.show', ['url' => $url]));
    }

    public function test_a_page_is_read_from_its_head()
    {
        $this->preview(self::SITE.'/article')
            ->assertOk()
            ->assertJson([
                'kind' => 'page',
                'title' => 'A & B, explained',
                'description' => 'What it is, in short.',
                'site' => 'The Paper',
                'image' => self::SITE.'/pictures/cover.png',
                'icon' => self::SITE.'/favicon.png',
            ]);
    }

    public function test_a_map_link_is_a_place_even_a_short_one()
    {
        // Read from the link alone; nothing is fetched
        $this->preview('https://www.openstreetmap.org/?mlat=51.5007&mlon=-0.1246')
            ->assertJson(['kind' => 'map', 'place' => ['lat' => 51.5007, 'lng' => -0.1246]]);

        $this->preview('https://maps.app.goo.gl/AbC123')
            ->assertJson(['kind' => 'map', 'title' => 'Wat Pho', 'place' => ['name' => 'Wat Pho', 'lat' => 13.7465, 'lng' => 100.4927]]);
    }

    public function test_a_file_says_what_it_is()
    {
        $this->preview(self::SITE.'/pictures/cover.png')->assertJson(['kind' => 'image']);
        $this->preview(self::SITE.'/clip.mp4')->assertJson(['kind' => 'video']);
    }

    public function test_nothing_on_the_servers_own_network_is_fetched()
    {
        $this->preview('http://127.0.0.1/')->assertOk()->assertJson(['kind' => 'page', 'title' => null]);
        $this->preview(self::SITE.'/inside')->assertOk()->assertJson(['title' => null]);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '10.0.0.5') || str_contains($request->url(), '127.0.0.1'));

        $this->actingAs(User::factory()->create())
            ->getJson(route('link-preview.show', ['url' => 'javascript:alert(1)']))
            ->assertUnprocessable();
    }

    public function test_a_picture_is_served_from_here_and_only_a_raster_one()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('link-preview.image', ['url' => self::SITE.'/pictures/cover.png']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($user)->get(route('link-preview.image', ['url' => self::SITE.'/drawing.svg']))->assertNoContent();
    }

    public function test_guests_get_nothing()
    {
        $this->getJson(route('link-preview.show', ['url' => self::SITE.'/article']))->assertUnauthorized();
    }
}
