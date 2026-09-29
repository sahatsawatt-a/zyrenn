<?php

namespace Tests\Feature;

use App\Mcp\Servers\GlobalServer;
use App\Mcp\Servers\UserServer;
use App\Mcp\Tools\Drive\DeleteFile;
use App\Mcp\Tools\Drive\GetFile;
use App\Mcp\Tools\Drive\ListDrive;
use App\Mcp\Tools\Drive\UpdateFile;
use App\Mcp\Tools\Drive\UploadFile;
use App\Mcp\Tools\Notes\CreateNote;
use App\Models\Drive\DriveFile;
use App\Models\Drive\DriveFolder;
use App\Models\User;
use App\Support\TiptapMarkdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class McpDriveTest extends TestCase
{
    use RefreshDatabase;

    /** A 1x1 transparent PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    // ---------------------------------------------------------------- Upload

    public function test_it_uploads_an_image_and_returns_markdown_for_a_note()
    {
        $user = User::factory()->create();

        $response = UserServer::actingAs($user)
            ->tool(UploadFile::class, ['name' => 'chart.png', 'content_base64' => self::PNG])
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

        UserServer::actingAs($user)
            ->tool(UploadFile::class, ['name' => 'chart.png', 'content_base64' => self::PNG])
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
            $note->content['content'][1],
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

        UserServer::actingAs($user)->tool(UploadFile::class, ['name' => 'x.png', 'content_base64' => 'not base64!!'])
            ->assertHasErrors(['content_base64 is not valid base64.']);

        UserServer::actingAs($user)->tool(UploadFile::class, ['name' => 'x.png'])
            ->assertHasErrors(['Pass either content_base64 or text, not both and not neither.']);

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

        GlobalServer::tool(UploadFile::class, ['user_id' => $user->id, 'name' => 'admin.png', 'content_base64' => self::PNG])
            ->assertOk();

        $this->assertSame('admin.png', $user->driveFiles()->sole()->name);

        GlobalServer::tool(UploadFile::class, ['name' => 'nobody.png', 'content_base64' => self::PNG])
            ->assertHasErrors();
    }
}
