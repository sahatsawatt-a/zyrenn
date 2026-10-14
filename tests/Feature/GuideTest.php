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

    public function test_every_topic_and_part_has_a_title_and_a_body(): void
    {
        foreach (Guide::topics() as $topic) {
            foreach ([$topic, ...$topic['parts']] as $each) {
                $read = Guide::topic($each['slug']);

                $this->assertNotNull($read);
                $this->assertNotSame(basename($each['slug']), $each['title'], "{$each['slug']} has no # title");
                $this->assertNotEmpty($read['doc']['content']);
            }
        }
    }

    public function test_a_part_is_read_under_its_topic(): void
    {
        $notes = collect(Guide::topics())->firstWhere('slug', 'notes');

        $this->assertContains(['slug' => 'notes/math', 'title' => 'Math'], $notes['parts']);

        $this->actingAs(User::factory()->create())
            ->get('/guide/notes/math')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('topic.slug', 'notes/math')
                ->where('topic.title', 'Math')
                ->where('topic.in', ['slug' => 'notes', 'title' => 'Notes']));

        $this->actingAs(User::factory()->create())->get('/guide/notes/nothing-here')->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/guide/notes/math/deeper')->assertNotFound();
    }

    public function test_every_link_from_one_page_of_the_guide_to_another_is_there(): void
    {
        foreach (File::allFiles(resource_path('guide')) as $file) {
            preg_match_all('#\]\(/guide/([^)\s]+)\)#', $file->getContents(), $found);

            foreach ($found[1] as $slug) {
                $this->assertNotNull(Guide::topic($slug), "{$file->getRelativePathname()} links to /guide/{$slug}");
            }
        }
    }

    public function test_every_picture_and_video_a_topic_shows_is_there(): void
    {
        foreach (File::allFiles(resource_path('guide')) as $file) {
            preg_match_all('/!\[[^\]]*\]\(([^)\s]+)\)|<video src="([^"]+)"/', $file->getContents(), $found);

            foreach (array_filter([...$found[1], ...$found[2]]) as $src) {
                $this->assertFileExists(public_path($src), $file->getRelativePathname()." shows {$src}");
            }
        }
    }

    public function test_a_video_in_a_topic_is_a_video_block(): void
    {
        $doc = Guide::topic('boards')['doc'];

        $this->assertContains(
            ['src' => '/images/guide/tour-shapes.webm', 'title' => 'Show me: Join two shapes'],
            collect($doc['content'])->where('type', 'video')->map(fn (array $block) => array_intersect_key($block['attrs'], ['src' => 0, 'title' => 0]))->all(),
        );
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
