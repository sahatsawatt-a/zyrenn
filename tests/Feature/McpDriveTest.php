<?php

namespace Tests\Feature;

use App\Mcp\Servers\GlobalServer;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Drive\DeleteFile;
use App\Mcp\Tools\Drive\GetFile;
use App\Mcp\Tools\Drive\ListDrive;
use App\Mcp\Tools\Drive\RequestUpload;
use App\Mcp\Tools\Drive\UpdateFile;
use App\Mcp\Tools\Drive\UploadFile;
use App\Mcp\Tools\Notes\CreateNote;
use App\Models\Drive\DriveFile;
use App\Models\Drive\DriveFolder;
use App\Models\Project;
use App\Models\User;
use App\Support\TiptapMarkdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class McpDriveTest extends TestCase
{
    use RefreshDatabase;

    /** A 1x1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** A public address: a host name would need DNS, which a test can't count on. */
    private const SITE = 'https://93.184.215.14';

    private function fakeSite(): void
    {
        Http::fake([
            '93.184.215.14/charts/chart.png' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
            '93.184.215.14/render?id=7' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
            '93.184.215.14/old.png' => Http::response('', 302, ['Location' => '/charts/chart.png']),
            '93.184.215.14/inside.png' => Http::response('', 302, ['Location' => 'http://10.0.0.5/secret.png']),
            '93.184.215.14/huge.mp4' => Http::response('', 200, ['Content-Type' => 'video/mp4', 'Content-Length' => (string) (600 * 1024 * 1024)]),
            '93.184.215.14/*' => Http::response('Not found', 404),
        ]);
    }

    // ---------------------------------------------------------------- Upload

    public function test_it_fetches_an_image_from_a_url_and_returns_markdown_for_a_note()
    {
        $this->fakeSite();
        $user = User::factory()->create();

        $response = UserServer::actingAs($user)
            ->tool(UploadFile::class, ['source_url' => self::SITE.'/charts/chart.png'])
            ->assertOk();

        $file = $user->driveFiles()->sole();

        $this->assertSame('chart.png', $file->name);
        $this->assertSame('image/png', $file->mime);
        $this->assertSame('image', $file->kind);
        $this->assertTrue(Storage::disk(DriveFile::DISK)->exists($file->path));

        $response->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('ref_id', $file->ref_id)
            ->where('is_image', true)
            ->where('markdown', "![chart.png](/drive/files/{$file->ref_id})")
            ->etc());
    }

    public function test_an_uploaded_image_can_be_embedded_in_a_note()
    {
        $user = User::factory()->create();

        $this->fakeSite();

        UserServer::actingAs($user)
            ->tool(UploadFile::class, ['source_url' => self::SITE.'/charts/chart.png'])
            ->assertOk();

        $file = $user->driveFiles()->sole();
        $markdown = "Here is the chart:\n\n![chart.png](/drive/files/{$file->ref_id})";

        UserServer::actingAs($user)
            ->tool(CreateNote::class, ['title' => 'Report', 'markdown' => $markdown])
            ->assertOk();

        $note = $user->notes()->sole();

        // The image survives as an image node, not as a line of text
        $this->assertSame(
            ['type' => 'image', 'attrs' => ['src' => "/drive/files/{$file->ref_id}", 'alt' => 'chart.png']],
            $this->withoutBlockIds($note->content)['content'][1],
        );

        // ...and comes back out of the note as the same Markdown line
        $this->assertStringContainsString("![chart.png](/drive/files/{$file->ref_id})", TiptapMarkdown::toMarkdown($note->content));
    }

    public function test_it_uploads_text_and_creates_missing_folders()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)
            ->tool(UploadFile::class, ['name' => 'notes.md', 'text' => '# hello', 'folder' => 'Docs/2026'])
            ->assertOk();

        $file = $user->driveFiles()->sole();

        $this->assertSame('# hello', Storage::disk(DriveFile::DISK)->get($file->path));
        $this->assertSame('Docs/2026', implode('/', array_map(fn (DriveFolder $f) => $f->name, $file->folder->ancestry())));
    }

    public function test_a_folder_path_never_resolves_to_another_users_folder()
    {
        $user = User::factory()->create();
        $theirs = DriveFolder::factory()->create(['name' => 'Docs']);

        UserServer::actingAs($user)
            ->tool(UploadFile::class, ['name' => 'mine.txt', 'text' => 'x', 'folder' => 'Docs'])
            ->assertOk();

        $folder = $user->driveFiles()->sole()->folder;

        $this->assertNotSame($theirs->id, $folder->id);
        $this->assertSame($user->id, $folder->user_id);
    }

    public function test_it_rejects_a_bad_upload()
    {
        $user = User::factory()->create();

        // Bytes never come through a tool call: base64 is not taken at all
        UserServer::actingAs($user)->tool(UploadFile::class, ['name' => 'x.png', 'content_base64' => self::PNG])
            ->assertHasErrors(['Pass either source_url or text, not both and not neither. For a file on your own disk, use request-upload.']);

        UserServer::actingAs($user)->tool(UploadFile::class, ['text' => 'x'])
            ->assertHasErrors(['Pass a "name" for the file, e.g. "notes.md".']);

        // A name that tries to climb out of the user's own directory keeps only its last part
        UserServer::actingAs($user)
            ->tool(UploadFile::class, ['name' => '../../escape.txt', 'text' => 'x'])
            ->assertOk();

        $file = $user->driveFiles()->sole();
        $this->assertSame('escape.txt', $file->name);
        $this->assertStringStartsWith('drive/'.$user->id.'/', $file->path);
    }

    // ------------------------------------------------------------- Read side

    public function test_it_lists_only_the_users_own_files()
    {
        $user = User::factory()->create();
        DriveFile::factory()->for($user)->create(['name' => 'Budget.pdf', 'kind' => 'pdf']);
        DriveFile::factory()->for($user)->create(['name' => 'Photo.png', 'kind' => 'image']);
        DriveFile::factory()->create(['name' => 'Someone else.pdf']);

        UserServer::actingAs($user)->tool(ListDrive::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json->has('files', 2)->etc());

        UserServer::actingAs($user)->tool(ListDrive::class, ['search' => 'bUdG'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('files', 1)
                ->where('files.0.name', 'Budget.pdf')
                ->etc());

        UserServer::actingAs($user)->tool(ListDrive::class, ['kind' => 'image'])
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->has('files', 1)
                ->where('files.0.name', 'Photo.png')
                ->etc());
    }

    public function test_get_file_returns_text_contents()
    {
        $user = User::factory()->create();

        UserServer::actingAs($user)->tool(UploadFile::class, ['name' => 'readme.md', 'text' => '# Title'])->assertOk();

        UserServer::actingAs($user)
            ->tool(GetFile::class, ['ref_id' => $user->driveFiles()->sole()->ref_id])
            ->assertOk()
            ->assertSee('# Title')
            ->assertStructuredContent(fn (AssertableJson $json) => $json->where('text', '# Title')->etc());
    }

    public function test_get_file_reports_missing_bytes_rather_than_failing()
    {
        $user = User::factory()->create();
        $file = DriveFile::factory()->for($user)->create(['path' => 'drive/'.$user->id.'/gone.png']);

        UserServer::actingAs($user)->tool(GetFile::class, ['ref_id' => $file->ref_id])
            ->assertHasErrors();
    }

    // ------------------------------------------------------------ Write side

    public function test_it_renames_and_moves_a_file()
    {
        $user = User::factory()->create();
        $file = DriveFile::factory()->for($user)->create(['name' => 'old.png']);

        UserServer::actingAs($user)
            ->tool(UpdateFile::class, ['ref_id' => $file->ref_id, 'name' => 'new.png', 'folder' => 'Archive'])
            ->assertOk();

        $file->refresh();

        $this->assertSame('new.png', $file->name);
        $this->assertSame('Archive', $file->folder->name);
    }

    public function test_it_deletes_a_file_and_its_bytes()
    {
        $user = User::factory()->create();
        UserServer::actingAs($user)->tool(UploadFile::class, ['name' => 'bye.txt', 'text' => 'x'])->assertOk();

        $file = $user->driveFiles()->sole();
        $path = $file->path;

        UserServer::actingAs($user)->tool(DeleteFile::class, ['ref_id' => $file->ref_id])->assertOk();

        $this->assertNull($user->driveFiles()->first());
        $this->assertFalse(Storage::disk(DriveFile::DISK)->exists($path));
    }

    // ---------------------------------------------------------- Other users

    public function test_a_user_cannot_touch_another_users_file()
    {
        $user = User::factory()->create();
        $theirs = DriveFile::factory()->create(['name' => 'Private.pdf']);

        UserServer::actingAs($user)->tool(GetFile::class, ['ref_id' => $theirs->ref_id])
            ->assertHasErrors(["File {$theirs->ref_id} was not found."]);
        UserServer::actingAs($user)->tool(UpdateFile::class, ['ref_id' => $theirs->ref_id, 'name' => 'Mine.pdf'])
            ->assertHasErrors();
        UserServer::actingAs($user)->tool(DeleteFile::class, ['ref_id' => $theirs->ref_id])
            ->assertHasErrors();

        $this->assertSame('Private.pdf', $theirs->refresh()->name);
    }

    public function test_the_admin_server_acts_on_the_chosen_user()
    {
        $user = User::factory()->create();

        GlobalServer::tool(UploadFile::class, ['user_id' => $user->id, 'name' => 'admin.md', 'text' => 'x'])
            ->assertOk();

        $this->assertSame('admin.md', $user->driveFiles()->sole()->name);

        GlobalServer::tool(UploadFile::class, ['name' => 'nobody.md', 'text' => 'x'])
            ->assertHasErrors();

        $link = GlobalServer::tool(RequestUpload::class, ['user_id' => $user->id])->assertOk();

        $this->post($this->uploadUrl($link), ['file' => $this->png('admin.png')])->assertCreated();
        $this->assertSame(2, $user->driveFiles()->count());
    }

    // ------------------------------------------------------------- From a URL

    public function test_a_fetched_file_is_named_from_its_url_or_as_asked()
    {
        $this->fakeSite();
        $user = User::factory()->create();

        // No extension in the URL: one comes from the Content-Type
        UserServer::actingAs($user)->tool(UploadFile::class, ['source_url' => self::SITE.'/render?id=7'])->assertOk();
        // A redirect is followed to the file
        UserServer::actingAs($user)->tool(UploadFile::class, ['source_url' => self::SITE.'/old.png', 'name' => 'Q3 chart.png', 'folder' => 'Charts'])->assertOk();

        $this->assertSame(['render.png', 'Q3 chart.png'], $user->driveFiles()->orderBy('id')->pluck('name')->all());
        $this->assertSame('image', $user->driveFiles()->latest('id')->first()->kind);
        $this->assertSame('Charts', $user->driveFiles()->latest('id')->first()->folder->name);
    }

    public function test_it_will_not_fetch_from_its_own_network()
    {
        $this->fakeSite();
        $user = User::factory()->create();

        foreach (['http://localhost/x.png', 'http://127.0.0.1:8000/x.png', 'http://2130706433/x.png', 'http://10.0.0.5/x.png',
            'http://169.254.169.254/latest/meta-data', 'http://[::1]/x.png', 'http://[fd00::1]/x.png'] as $url) {
            UserServer::actingAs($user)->tool(UploadFile::class, ['source_url' => $url])
                ->assertSee("is not on the public internet, so it can't be fetched from here.");
        }

        // ...not even by way of a redirect from a public one
        UserServer::actingAs($user)->tool(UploadFile::class, ['source_url' => self::SITE.'/inside.png'])
            ->assertHasErrors(["10.0.0.5 is not on the public internet, so it can't be fetched from here."]);

        UserServer::actingAs($user)->tool(UploadFile::class, ['source_url' => 'file:///etc/passwd'])
            ->assertHasErrors(['Only an http:// or https:// URL can be fetched.']);

        Http::assertNotSent(fn (HttpRequest $request) => ! str_starts_with($request->url(), self::SITE));
        $this->assertSame(0, $user->driveFiles()->count());
    }

    public function test_it_reports_a_fetch_that_fails()
    {
        $this->fakeSite();
        $user = User::factory()->create();

        UserServer::actingAs($user)->tool(UploadFile::class, ['source_url' => self::SITE.'/gone.png'])
            ->assertHasErrors(['93.184.215.14 answered 404 for that URL.']);

        UserServer::actingAs($user)->tool(UploadFile::class, ['source_url' => self::SITE.'/huge.mp4'])
            ->assertHasErrors(['That file is larger than the 500 MB limit.']);

        $this->assertSame(0, $user->driveFiles()->count());
    }

    // ----------------------------------------------------------- From a disk

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    /** The upload_url from a request-upload response, as a path the test client can post to. */
    private function uploadUrl($response): string
    {
        $url = null;
        $response->assertStructuredContent(function (AssertableJson $json) use (&$url) {
            $url = $json->toArray()['upload_url'];
            $json->etc();
        });

        return $url;
    }

    public function test_a_link_from_request_upload_takes_one_file_from_disk()
    {
        $user = User::factory()->create();

        $link = UserServer::actingAs($user)->tool(RequestUpload::class, ['folder' => 'Clips/2026'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('curl', fn (string $curl) => str_contains($curl, "-F 'file=@"))
                ->etc());

        $url = $this->uploadUrl($link);
        $video = UploadedFile::fake()->createWithContent('demo.mp4', str_repeat("\0", 64));

        $this->post($url, ['file' => $video], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('name', 'demo.mp4')
            ->assertJsonPath('folder', 'Clips/2026');

        $file = $user->driveFiles()->sole();
        $this->assertSame($user->id, $file->created_by);
        $this->assertTrue(Storage::disk(DriveFile::DISK)->exists($file->path));

        // Once only
        $this->post($url, ['file' => $this->png('again.png')])
            ->assertStatus(410)
            ->assertJsonPath('error', 'This upload link has been used; get a new one with request-upload.');

        $this->assertSame(1, $user->driveFiles()->count());
    }

    public function test_an_upload_link_answers_in_short_json_when_it_cannot_be_used()
    {
        $user = User::factory()->create();
        $url = $this->uploadUrl(UserServer::actingAs($user)->tool(RequestUpload::class, [])->assertOk());

        // Without a file, the link isn't spent
        $this->post($url, [])->assertStatus(422)->assertJsonStructure(['error']);

        $this->post(str_replace('user='.$user->id, 'user='.($user->id + 1), $url), ['file' => $this->png('a.png')])
            ->assertForbidden()
            ->assertJsonPath('error', 'This upload link is not valid or has run out; get a new one with request-upload.');

        $this->travel(RequestUpload::EXPIRES_MINUTES + 1)->minutes();
        $this->post($url, ['file' => $this->png('a.png')])->assertForbidden();

        $this->assertSame(0, $user->driveFiles()->count());
    }

    public function test_an_upload_link_goes_to_a_project_only_while_the_user_may_add_to_it()
    {
        $project = Project::factory()->create(['name' => 'Lakeshore']);
        $editor = User::factory()->create();
        $viewer = User::factory()->create();
        $project->members()->attach($editor, ['role' => Project::EDITOR]);
        $project->members()->attach($viewer, ['role' => Project::VIEWER]);

        UserServer::actingAs($viewer)->tool(RequestUpload::class, ['project' => 'Lakeshore'])->assertHasErrors();

        $url = $this->uploadUrl(UserServer::actingAs($editor)->tool(RequestUpload::class, ['project' => 'Lakeshore'])->assertOk());
        $this->post($url, ['file' => $this->png('site.png')])->assertCreated()->assertJsonPath('project', $project->ref_id);
        $this->assertSame('site.png', $project->driveFiles()->sole()->name);
        $this->assertSame(0, $editor->driveFiles()->count());

        // Made a viewer after asking for the link
        $later = $this->uploadUrl(UserServer::actingAs($editor)->tool(RequestUpload::class, ['project' => 'Lakeshore'])->assertOk());
        $project->members()->updateExistingPivot($editor, ['role' => Project::VIEWER]);

        $this->post($later, ['file' => $this->png('late.png')])->assertForbidden();
        $this->assertSame(1, $project->driveFiles()->count());
    }
}
