<?php

namespace Tests\Feature;

use App\Models\Drive\DriveFile;
use App\Models\Drive\DriveFolder;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DriveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(DriveFile::DISK);
    }

    /**
     * A real 1x1 PNG, so it passes mime sniffing without the GD extension.
     */
    private static function png(string $name = 'cat.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='),
        );
    }

    /**
     * The start of an MP4 -- its "ftyp" box, which is what sniffing reads. A
     * fake file guesses its type from the name instead, and guesses
     * application/mp4, so it is told what sniffing would have said.
     */
    private static function mp4(string $name = 'clip.mp4'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 1000),
        )->mimeType('video/mp4');
    }

    private function upload(User $user, UploadedFile $file, ?DriveFolder $folder = null): DriveFile
    {
        $this->actingAs($user)
            ->postJson(route('drive.files.store'), ['files' => [$file], 'folder' => $folder?->ref_id])
            ->assertCreated();

        return DriveFile::query()->latest('id')->firstOrFail();
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('drive.index'))->assertRedirect(route('login'));
    }

    public function test_index_lists_only_the_users_own_items_in_the_open_folder()
    {
        $user = User::factory()->create();
        $folder = DriveFolder::factory()->for($user)->create(['name' => 'Work']);
        DriveFolder::factory()->for($user)->create(['parent_id' => $folder->id]);
        DriveFile::factory()->for($user)->create(['name' => 'top.png']);
        DriveFile::factory()->for($user)->create(['folder_id' => $folder->id]);
        DriveFile::factory()->create();

        $this->actingAs($user)
            ->get(route('drive.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('drive/Index')
                ->where('folder', null)
                ->has('folders', 1)
                ->where('folders.0.name', 'Work')
                ->has('files', 1)
                ->where('files.0.name', 'top.png')
                ->missing('files.0.id')
                ->has('allFolders', 2)
            );

        $this->get(route('drive.index', ['folder' => $folder->ref_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('folder.ref_id', $folder->ref_id)
                ->where('breadcrumbs.0.name', 'Work')
                ->has('folders', 1)
                ->has('files', 1)
            );
    }

    public function test_another_users_folder_cannot_be_opened()
    {
        $folder = DriveFolder::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('drive.index', ['folder' => $folder->ref_id]))
            ->assertNotFound();
    }

    public function test_uploads_are_stored_privately_and_described()
    {
        $user = User::factory()->create();
        $folder = DriveFolder::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->postJson(route('drive.files.store'), ['files' => [self::png()], 'folder' => $folder->ref_id])
            ->assertCreated()
            ->assertJsonPath('files.0.name', 'cat.png')
            ->assertJsonPath('files.0.kind', 'image')
            ->assertJsonPath('files.0.is_image', true);

        $file = DriveFile::query()->where('ref_id', $response->json('files.0.ref_id'))->firstOrFail();

        $this->assertSame($user->id, $file->user_id);
        $this->assertSame($folder->id, $file->folder_id);
        $this->assertSame('/drive/files/'.$file->ref_id, $response->json('files.0.url'));
        Storage::disk(DriveFile::DISK)->assertExists($file->path);
    }

    public function test_uploading_into_another_users_folder_is_rejected()
    {
        $folder = DriveFolder::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson(route('drive.files.store'), ['files' => [self::png()], 'folder' => $folder->ref_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('folder');
    }

    public function test_files_are_served_only_to_their_owner()
    {
        $user = User::factory()->create();
        $file = $this->upload($user, self::png());

        $this->get(route('drive.files.show', $file))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs(User::factory()->create())
            ->get(route('drive.files.show', $file))
            ->assertForbidden();
    }

    public function test_images_display_inline_and_other_files_download()
    {
        $user = User::factory()->create();
        $image = $this->upload($user, self::png());
        $page = $this->upload($user, UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'));

        $this->assertStringStartsWith('inline', $this->get(route('drive.files.show', $image))->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('attachment', $this->get(route('drive.files.show', $page))->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('attachment', $this->get(route('drive.files.show', [$image, 'download' => 1]))->headers->get('Content-Disposition'));
    }

    public function test_videos_play_in_place_and_can_be_fetched_a_range_at_a_time()
    {
        $user = User::factory()->create();
        $video = $this->upload($user, self::mp4());

        $this->assertSame('video', $video->kind);
        $this->assertTrue($video->isVideo());

        $this->actingAs($user)
            ->getJson(route('drive.pick', ['kind' => 'video']))
            ->assertJsonCount(1, 'files')
            ->assertJsonPath('files.0.is_video', true);

        $whole = $this->get(route('drive.files.show', $video));
        $whole->assertOk()->assertHeader('Content-Type', 'video/mp4')->assertHeader('Accept-Ranges', 'bytes');
        $this->assertStringStartsWith('inline', $whole->headers->get('Content-Disposition'));

        // A <video> seeks by asking for the bytes it jumps to
        $part = $this->get(route('drive.files.show', $video), ['Range' => 'bytes=4-11']);
        $part->assertStatus(206)->assertHeader('Content-Range', 'bytes 4-11/1024');
        $this->assertSame('ftypmp42', $part->streamedContent());
    }

    public function test_a_file_name_that_is_not_plain_ascii_still_downloads()
    {
        $user = User::factory()->create();
        $file = $this->upload($user, self::png('ภาพ 100%.png'));

        $disposition = $this->actingAs($user)
            ->get(route('drive.files.show', [$file, 'download' => 1]))
            ->assertOk()
            ->headers->get('Content-Disposition');

        $this->assertStringStartsWith('attachment', $disposition);
        $this->assertStringContainsString("filename*=utf-8''", $disposition);
    }

    public function test_a_file_can_be_renamed_and_moved()
    {
        $user = User::factory()->create();
        $file = DriveFile::factory()->for($user)->create();
        $folder = DriveFolder::factory()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('drive.files.update', $file), ['name' => 'renamed.png', 'folder' => $folder->ref_id])
            ->assertRedirect();

        $file->refresh();
        $this->assertSame('renamed.png', $file->name);
        $this->assertSame($folder->id, $file->folder_id);

        $this->patch(route('drive.files.update', $file), ['folder' => null]);
        $this->assertNull($file->refresh()->folder_id);
    }

    public function test_other_users_cannot_change_a_file()
    {
        $file = DriveFile::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patch(route('drive.files.update', $file), ['name' => 'mine.png'])
            ->assertForbidden();

        $this->delete(route('drive.files.destroy', $file))->assertForbidden();
    }

    public function test_deleting_a_file_removes_its_bytes()
    {
        $user = User::factory()->create();
        $file = $this->upload($user, self::png());

        $this->delete(route('drive.files.destroy', $file))->assertRedirect();

        $this->assertModelMissing($file);
        Storage::disk(DriveFile::DISK)->assertMissing($file->path);
    }

    public function test_folders_can_be_created_renamed_and_nested()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('drive.folders.store'), ['name' => 'Work'])->assertRedirect();
        $work = $user->driveFolders()->sole();

        $this->post(route('drive.folders.store'), ['name' => 'Diagrams', 'parent' => $work->ref_id]);
        $diagrams = $user->driveFolders()->where('name', 'Diagrams')->sole();
        $this->assertSame($work->id, $diagrams->parent_id);

        $this->patch(route('drive.folders.update', $work), ['name' => 'Projects']);
        $this->assertSame('Projects', $work->refresh()->name);
    }

    public function test_a_folder_cannot_be_moved_into_itself_or_a_descendant()
    {
        $user = User::factory()->create();
        $parent = DriveFolder::factory()->for($user)->create();
        $child = DriveFolder::factory()->for($user)->create(['parent_id' => $parent->id]);

        $this->actingAs($user)
            ->patch(route('drive.folders.update', $parent), ['parent' => $child->ref_id])
            ->assertSessionHasErrors('parent');

        $this->patch(route('drive.folders.update', $parent), ['parent' => $parent->ref_id])
            ->assertSessionHasErrors('parent');

        $this->assertNull($parent->refresh()->parent_id);
    }

    public function test_deleting_a_folder_removes_everything_inside_it()
    {
        $user = User::factory()->create();
        $parent = DriveFolder::factory()->for($user)->create();
        $child = DriveFolder::factory()->for($user)->create(['parent_id' => $parent->id]);
        $outer = $this->upload($user, self::png(), $parent);
        $inner = $this->upload($user, self::png(), $child);
        $elsewhere = $this->upload($user, self::png());

        $this->delete(route('drive.folders.destroy', $parent))->assertRedirect();

        $this->assertModelMissing($parent);
        $this->assertModelMissing($child);
        $this->assertModelMissing($outer);
        $this->assertModelMissing($inner);
        Storage::disk(DriveFile::DISK)->assertMissing([$outer->path, $inner->path]);
        $this->assertModelExists($elsewhere);
    }

    public function test_pick_lists_only_the_users_images()
    {
        $user = User::factory()->create();
        DriveFile::factory()->for($user)->create(['name' => 'diagram.png']);
        DriveFile::factory()->for($user)->create(['name' => 'photo.png']);
        DriveFile::factory()->for($user)->create(['name' => 'notes.pdf', 'mime' => 'application/pdf', 'kind' => 'pdf']);
        DriveFile::factory()->create(['name' => 'someone-elses.png']);

        $this->actingAs($user)
            ->getJson(route('drive.pick'))
            ->assertOk()
            ->assertJsonCount(2, 'files');

        $this->getJson(route('drive.pick', ['q' => 'diag']))
            ->assertJsonCount(1, 'files')
            ->assertJsonPath('files.0.name', 'diagram.png');

        // Videos are picked on their own, and never mixed in with the images
        DriveFile::factory()->for($user)->create(['name' => 'demo.mp4', 'mime' => 'video/mp4', 'kind' => 'video']);

        $this->getJson(route('drive.pick'))->assertJsonCount(2, 'files');
        $this->getJson(route('drive.pick', ['kind' => 'video']))
            ->assertJsonCount(1, 'files')
            ->assertJsonPath('files.0.name', 'demo.mp4');
    }

    public function test_deleting_a_user_removes_their_drive_files()
    {
        $user = User::factory()->create();
        $file = $this->upload($user, self::png());

        $user->delete();

        $this->assertModelMissing($file);
        Storage::disk(DriveFile::DISK)->assertMissing($file->path);
    }

    public function test_search_finds_files_and_folders_by_name_in_every_folder()
    {
        $user = User::factory()->create();
        $work = DriveFolder::factory()->for($user)->create(['name' => 'Work']);
        $diagrams = DriveFolder::factory()->for($user)->create(['name' => 'Diagrams', 'parent_id' => $work->id]);
        DriveFile::factory()->for($user)->create(['name' => 'network-diagram.png', 'folder_id' => $diagrams->id]);
        DriveFile::factory()->for($user)->create(['name' => 'photo.png']);
        DriveFile::factory()->create(['name' => 'someone-elses-diagram.png']);

        $this->actingAs($user)
            ->get(route('drive.index', ['q' => 'DIAGRAM']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('files', 1)
                ->where('files.0.name', 'network-diagram.png')
                ->where('files.0.path', 'Work / Diagrams')
                ->has('folders', 1)
                ->where('folders.0.path', 'Work / Diagrams')
            );
    }

    public function test_files_can_be_sorted_and_filtered_by_type()
    {
        $user = User::factory()->create();
        $small = DriveFile::factory()->for($user)->create(['name' => 'b.png', 'size' => 10, 'created_at' => now()->subDay()]);
        $big = DriveFile::factory()->for($user)->create(['name' => 'a.png', 'size' => 999, 'created_at' => now()->subDays(2)]);
        $pdf = DriveFile::factory()->for($user)->create(['name' => 'c.pdf', 'mime' => 'application/pdf', 'kind' => 'pdf', 'size' => 50]);

        $names = fn (array $query) => array_column(
            $this->actingAs($user)->get(route('drive.index', $query))->viewData('page')['props']['files'],
            'name',
        );

        $this->assertSame(['c.pdf', 'b.png', 'a.png'], $names([]));
        $this->assertSame(['a.png', 'b.png', 'c.pdf'], $names(['sort' => 'oldest']));
        $this->assertSame(['a.png', 'b.png', 'c.pdf'], $names(['sort' => 'name']));
        $this->assertSame(['a.png', 'c.pdf', 'b.png'], $names(['sort' => 'size']));
        $this->assertSame(['c.pdf'], $names(['type' => 'pdf']));
        $this->assertSame(['b.png', 'a.png'], $names(['type' => 'image']));
    }

    // ---- the photo editor ----

    /**
     * @return array<string, mixed>
     */
    private static function photoEdit(): array
    {
        return [
            'crop' => ['x' => '0.1', 'y' => '0', 'width' => '0.5', 'height' => '1'],
            'rotate' => '90',
            'flipX' => '1',
            'flipY' => '0',
            'brightness' => '120',
            'contrast' => '100',
            'saturation' => '80',
            'warmth' => '-20',
            'vignette' => '40',
            'hidden' => [
                ['x' => '0.1', 'y' => '0.2', 'width' => '0.3', 'height' => '0.1', 'style' => 'fill'],
            ],
        ];
    }

    public function test_an_edited_photo_is_kept_beside_its_original()
    {
        $user = User::factory()->create();
        $folder = DriveFolder::factory()->for($user)->create();
        $original = $this->upload($user, self::png('cat.png'), $folder);

        $made = $this->actingAs($user)->postJson(route('drive.photo-edits.store'), [
            'file' => self::png('photo.png'),
            'source' => $original->ref_id,
            'edit' => self::photoEdit(),
        ])->assertCreated()->assertJsonPath('file.name', 'cat (edited).png');

        $edited = DriveFile::query()->where('ref_id', $made->json('file.ref_id'))->sole();
        $this->assertSame($original->id, $edited->source_id);
        $this->assertSame($folder->id, $edited->folder_id);
        $this->assertSame(90, $edited->edit['rotate']);
        $this->assertTrue($edited->edit['flipX']);
        $this->assertSame(0.5, $edited->edit['crop']['width']);
        Storage::disk(DriveFile::DISK)->assertExists([$original->path, $edited->path]);

        // Opened again, it starts from the whole original and the edit made
        $this->actingAs($user)->getJson(route('drive.files.original', $edited))
            ->assertOk()
            ->assertJsonPath('source.ref_id', $original->ref_id)
            ->assertJsonPath('edit.saturation', 80)
            ->assertJsonPath('edit.warmth', -20)
            ->assertJsonPath('edit.hidden.0', ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.1, 'style' => 'fill']);

        // The Drive says which is a copy of which
        $this->actingAs($user)->get(route('drive.index', ['folder' => $folder->ref_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('files.0.ref_id', $edited->ref_id)
                ->where('files.0.edited_from', ['ref_id' => $original->ref_id, 'name' => 'cat.png'])
                ->where('files.1.edits_count', 1));
        $this->actingAs($user)->getJson(route('drive.files.original', $original))
            ->assertOk()
            ->assertJsonPath('source', null)
            ->assertJsonPath('edit', null);
    }

    public function test_someone_elses_picture_is_edited_into_ones_own_drive_without_a_link_to_it()
    {
        $user = User::factory()->create();
        $theirs = DriveFile::factory()->create();

        $this->actingAs($user)->getJson(route('drive.files.original', $theirs))->assertForbidden();

        $made = $this->actingAs($user)->postJson(route('drive.photo-edits.store'), [
            'file' => self::png(),
            'source' => $theirs->ref_id,
            'edit' => self::photoEdit(),
        ])->assertCreated()->assertJsonPath('file.name', 'Photo (edited).png');

        $edited = DriveFile::query()->where('ref_id', $made->json('file.ref_id'))->sole();
        $this->assertSame($user->id, $edited->user_id);
        $this->assertNull($edited->source_id);
        $this->assertNull($edited->edit);
    }

    public function test_an_edit_must_be_one_the_editor_can_make()
    {
        $user = User::factory()->create();
        $original = $this->upload($user, self::png());

        $this->actingAs($user)->postJson(route('drive.photo-edits.store'), [
            'file' => self::png(),
            'source' => $original->ref_id,
            'edit' => [
                ...self::photoEdit(),
                'rotate' => '45',
                'brightness' => '900',
                'warmth' => '-101',
                'hidden' => [['x' => '2', 'y' => '0', 'width' => '0.1', 'height' => '0.1', 'style' => 'erase']],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'edit.rotate', 'edit.brightness', 'edit.warmth', 'edit.hidden.0.x', 'edit.hidden.0.style',
        ]);

        $this->actingAs($user)->postJson(route('drive.photo-edits.store'), [
            'file' => UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_a_projects_viewer_cannot_keep_an_edited_photo_in_it()
    {
        $project = Project::factory()->create();
        $viewer = User::factory()->create();
        $project->members()->attach($viewer, ['role' => Project::VIEWER]);

        $this->actingAs($viewer)->postJson(route('projects.drive.photo-edits.store', $project), [
            'file' => self::png(),
        ])->assertForbidden();
    }
}
