<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response()
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        // Whose the app is, as the LICENSE says, in the head of the page and for its foot
        $response->assertSee('<meta name="author" content="Sahatsawatt Anankatham">', false);
        $response->assertSee('<meta name="copyright" content="© '.now()->year.' Sahatsawatt Anankatham. All rights reserved.">', false);
        $response->assertInertia(fn (Assert $page) => $page->where('copyright', '© '.now()->year.' Sahatsawatt Anankatham. All rights reserved.'));
    }
}
