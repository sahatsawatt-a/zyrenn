<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Guide\Guide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The guide at /guide: topics read from resources/guide and shown as notes.
 */
class GuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_guide_opens_on_its_first_topic(): void
    {
        $first = Guide::topics()[0];

        $this->actingAs(User::factory()->create())
            ->get('/guide')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('guide/Show')
                ->has('topics', count(Guide::topics()))
                ->where('topic.slug', $first['slug'])
                ->where('topic.title', $first['title'])
                ->where('topic.doc.type', 'doc'));
    }

    public function test_a_topic_is_named_by_its_file_without_the_order(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/guide/workflows')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('topic.title', 'Workflows')
                // The title is the page's heading, not the note's first block
                ->where('topic.doc.content.0.type', 'paragraph'));
    }

    public function test_every_topic_has_a_title_and_a_body(): void
    {
        foreach (Guide::topics() as $topic) {
            $read = Guide::topic($topic['slug']);

            $this->assertNotNull($read);
            $this->assertNotSame($topic['slug'], $topic['title'], "{$topic['slug']} has no # title");
            $this->assertNotEmpty($read['doc']['content']);
        }
    }

    public function test_every_picture_a_topic_shows_is_there(): void
    {
        foreach (File::glob(resource_path('guide/*.md')) as $path) {
            preg_match_all('/!\[[^\]]*\]\(([^)\s]+)\)/', File::get($path), $found);

            foreach ($found[1] as $src) {
                $this->assertFileExists(public_path($src), basename($path)." shows {$src}");
            }
        }
    }

    public function test_an_unknown_topic_is_not_found(): void
    {
        $this->actingAs(User::factory()->create())->get('/guide/nothing-here')->assertNotFound();
    }

    public function test_the_guide_is_for_those_signed_in(): void
    {
        $this->get('/guide')->assertRedirect('/login');
    }
}
