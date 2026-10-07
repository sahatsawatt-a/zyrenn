<?php

namespace Tests\Unit;

use App\Support\Links\MapLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MapLinkTest extends TestCase
{
    /**
     * @return array<string, array{string, array{float, float}, string, float|null}>
     */
    public static function links(): array
    {
        return [
            'a google place, by its own pin, not the view' => [
                'https://www.google.com/maps/place/Siam+Paragon/@13.7468,100.5326,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d13.7462!4d100.5347',
                [13.7462, 100.5347], 'Siam Paragon', 17.0,
            ],
            'a google view' => ['https://www.google.co.th/maps/@13.75,100.5,12z', [13.75, 100.5], '', 12.0],
            'a google search for a point' => ['https://maps.google.com/?q=13.7563,100.5018', [13.7563, 100.5018], '', null],
            'google directions to a point' => ['https://www.google.com/maps/dir/?api=1&destination=48.8584,2.2945', [48.8584, 2.2945], '', null],
            'an openstreetmap pin' => ['https://www.openstreetmap.org/?mlat=51.5007&mlon=-0.1246#map=17/51.5007/-0.1246', [51.5007, -0.1246], '', null],
            'an openstreetmap view' => ['https://www.openstreetmap.org/#map=15/35.6586/139.7454', [35.6586, 139.7454], '', 15.0],
            'an apple maps place' => ['https://maps.apple.com/?ll=40.6892,-74.0445&q=Statue%20of%20Liberty', [40.6892, -74.0445], 'Statue of Liberty', null],
            'a geo uri' => ['geo:-33.8568,151.2153?q=Opera%20House', [-33.8568, 151.2153], 'Opera House', null],
        ];
    }

    /**
     * @param  array{float, float}  $at
     */
    #[DataProvider('links')]
    public function test_a_map_link_says_where_it_points(string $url, array $at, string $name, ?float $zoom)
    {
        $place = MapLink::place($url);

        $this->assertNotNull($place);
        $this->assertSame($at, [$place['lat'], $place['lng']]);
        $this->assertSame($name, $place['name']);
        $this->assertSame($zoom, $place['zoom']);
    }

    public function test_anything_else_is_not_a_place()
    {
        $this->assertNull(MapLink::place('https://example.com/@13.7,100.5,12z'));
        $this->assertNull(MapLink::place('https://www.google.com/search?q=13.7,100.5'));
        $this->assertNull(MapLink::place('https://www.google.com/maps/search/coffee'));
        $this->assertNull(MapLink::place('https://maps.google.com/?q=95,100'));
    }

    public function test_a_short_link_is_known_as_one()
    {
        $this->assertTrue(MapLink::isShort('https://maps.app.goo.gl/AbC123'));
        $this->assertTrue(MapLink::isShort('https://goo.gl/maps/AbC123'));
        $this->assertFalse(MapLink::isShort('https://goo.gl/AbC123'));
        $this->assertFalse(MapLink::isShort('https://www.google.com/maps/@13.7,100.5,12z'));
    }
}
