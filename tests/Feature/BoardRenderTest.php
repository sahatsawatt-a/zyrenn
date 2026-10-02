<?php

namespace Tests\Feature;

use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Boards\GetBoard;
use App\Models\Board\Board;
use App\Models\Drive\DriveFile;
use App\Models\User;
use App\Support\BoardItems;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A board drawn by the renderer: a PDF with a page for each frame, a PNG of
 * one frame or all of it -- through the app and over MCP -- and the signed
 * page and pictures the renderer, signed in as nobody, draws them from.
 */
class BoardRenderTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.7\n%fake\n";

    private const PNG = "\x89PNG\r\n\x1a\nfake";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(DriveFile::DISK);
    }

    private function fakeRenderer(): void
    {
        Http::fake([
            'chrome:3000/pdf' => Http::response(self::PDF, 200, ['Content-Type' => 'application/pdf']),
            'chrome:3000/png' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png']),
        ]);
    }

    private function board(User $user, ?DriveFile $picture = null): Board
    {
        return Board::factory()->for($user)->create([
            'title' => 'Q3/Q4 deck',
            'content' => ['items' => BoardItems::fromSpec([
                ['id' => 'one', 'kind' => 'frame', 'text' => 'One', 'x' => 0, 'y' => 0, 'width' => 960, 'height' => 540],
                ['id' => 'two', 'kind' => 'frame', 'text' => 'Two', 'x' => 1100, 'y' => 0, 'width' => 960, 'height' => 540],
                ['id' => 'pic', 'kind' => 'image', 'src' => $picture?->url() ?? 'https://example.com/a.png', 'x' => 40, 'y' => 40],
            ])],
        ]);
    }

    /**
     * The render page's path and query, as the app sent it to the renderer.
     */
    private function sentPage(string $endpoint): string
    {
        $url = '';

        Http::assertSent(function (Request $request) use ($endpoint, &$url) {
            if (str_ends_with($request->url(), $endpoint)) {
                $url = $request['url'];

                return true;
            }

            return false;
        });

        return (string) preg_replace('#^https?://[^/]+#', '', $url);
    }

    public function test_guests_are_sent_to_sign_in_and_others_cannot_have_it()
    {
        $board = $this->board(User::factory()->create());

        $this->get(route('boards.pdf', $board))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('boards.pdf', $board))
            ->assertForbidden();
    }

    public function test_the_board_comes_as_a_pdf_with_a_page_the_shape_of_its_frames()
    {
        $this->fakeRenderer();
        $user = User::factory()->create();
        $board = $this->board($user);

        $response = $this->actingAs($user)->get(route('boards.pdf', $board));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(self::PDF, $response->getContent());
        $this->assertStringContainsString("filename*=utf-8''Q3-Q4%20deck.pdf", $response->headers->get('Content-Disposition'));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pdf')
            && $request['pageSize'] === ['width' => '1280px', 'height' => '720px']
            && str_starts_with($request['url'], 'http://web:8080/boards/'.$board->ref_id.'/render?'));

        $this->actingAs($user)
            ->get(route('boards.png', $board))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_a_picture_can_be_had_a_frame_at_a_time()
    {
        $this->fakeRenderer();
        $user = User::factory()->create();
        $board = $this->board($user);

        $response = $this->actingAs($user)->get(route('boards.png', [$board, 'frame' => 'two']));

        $response->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString("filename*=utf-8''Q3-Q4%20deck%20-%20Two.png", $response->headers->get('Content-Disposition'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/png')
            && str_contains($request['url'], 'frame=two')
            && $request['height'] === 900);

        // Named by its title as well as its id, as get-board takes it
        $byTitle = $this->actingAs($user)->get(route('boards.png', [$board, 'frame' => 'ONE']));
        $byTitle->assertOk();
        $this->assertStringContainsString('%20-%20One.png', $byTitle->headers->get('Content-Disposition'));

        $this->actingAs($user)
            ->get(route('boards.png', [$board, 'frame' => 'three']))
            ->assertNotFound();
    }

    public function test_the_render_page_opens_only_by_its_signed_link_and_signs_its_pictures_too()
    {
        $this->fakeRenderer();
        $user = User::factory()->create();
        $picture = DriveFile::store(UploadedFile::fake()->createWithContent(
            'cat.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='),
        ), $user);
        $board = $this->board($user, $picture);

        $this->actingAs($user)->get(route('boards.pdf', $board))->assertOk();
        $page = $this->sentPage('/pdf');

        // Signed in as nobody, as the renderer is
        auth()->logout();

        $this->get(route('boards.render', ['board' => $board, 'mode' => 'pages']))->assertForbidden();

        $src = null;
        $this->get($page)
            ->assertOk()
            ->assertInertia(function (Assert $inertia) use (&$src, $picture) {
                $inertia->component('boards/Render')
                    ->where('mode', 'pages')
                    ->where('frames', ['one', 'two'])
                    ->where('items.2.src', function (string $signed) use (&$src, $picture) {
                        $src = $signed;

                        return str_starts_with($signed, "/drive/files/{$picture->ref_id}/signed?");
                    });
            });

        $this->get($src)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get("/drive/files/{$picture->ref_id}/signed")->assertForbidden();
        // A link is for its one file: it opens no other
        $other = DriveFile::factory()->for($user)->create();
        $this->get(str_replace($picture->ref_id, $other->ref_id, $src))->assertForbidden();
    }

    public function test_get_board_sends_a_picture_of_the_frame_asked_for()
    {
        $this->fakeRenderer();
        $user = User::factory()->create();
        $board = $this->board($user);

        UserServer::actingAs($user)
            ->tool(GetBoard::class, ['ref_id' => $board->ref_id, 'frame' => 'Two', 'image' => true])
            ->assertOk()
            ->assertSee(['Frame "Two" of "Q3/Q4 deck", as the app draws it.', base64_encode(self::PNG)])
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('frame', 'two')->etc());

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/png')
            && $request['width'] === 1600 && $request['height'] === 900
            && str_contains($request['url'], 'frame=two'));
    }

    public function test_a_picture_that_cannot_be_drawn_still_answers_with_the_board()
    {
        Http::fake(['chrome:3000/png' => Http::response(['error' => 'down'], 500)]);

        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(GetBoard::class, ['ref_id' => $this->board($user)->ref_id, 'image' => true])
            ->assertOk()
            ->assertSee('The picture of the board could not be drawn just now');
    }
}
