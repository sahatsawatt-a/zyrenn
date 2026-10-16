<?php

namespace Tests\Feature;

use App\Models\Drive\DriveFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A WebM video put in the Drive is made MP4 once it is in, in place, by the
 * browser's ffmpeg -- and stays WebM if that cannot be done.
 */
class DriveVideoMp4Test extends TestCase
{
    use RefreshDatabase;

    // The start of an MP4: a box of its size, then "ftyp"
    private const MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

    public function test_a_webm_video_is_made_mp4_once_it_is_in()
    {
        Storage::fake(DriveFile::DISK);
        Http::fake(['chrome:3000/mp4' => Http::response(self::MP4)]);
        $user = User::factory()->create();
        Storage::disk(DriveFile::DISK)->put('drive/1/walk.webm', 'webm bytes');
        $file = DriveFile::factory()->for($user)->create(['name' => 'walk.webm', 'kind' => 'video', 'mime' => 'video/webm', 'ext' => 'webm', 'path' => 'drive/1/walk.webm']);

        $this->work();

        $file->refresh();
        $this->assertSame(['walk.mp4', 'video/mp4', 'mp4'], [$file->name, $file->mime, $file->ext]);
        $this->assertSame(self::MP4, Storage::disk(DriveFile::DISK)->get($file->path));
        Storage::disk(DriveFile::DISK)->assertMissing('drive/1/walk.webm');
        // The browser fetched it by a signed link of the file's own
        Http::assertSent(fn (Request $request) => str_contains((string) $request['url'], $file->ref_id) && str_contains((string) $request['url'], 'signature='));
    }

    public function test_one_that_cannot_be_made_mp4_stays_webm_and_others_are_left_alone()
    {
        Storage::fake(DriveFile::DISK);
        Http::fake(['chrome:3000/mp4' => Http::response('', 500)]);
        $user = User::factory()->create();
        Storage::disk(DriveFile::DISK)->put('drive/1/walk.webm', 'webm bytes');
        $webm = DriveFile::factory()->for($user)->create(['name' => 'walk.webm', 'kind' => 'video', 'mime' => 'video/webm', 'ext' => 'webm', 'path' => 'drive/1/walk.webm']);
        DriveFile::factory()->for($user)->create(['name' => 'clip.mp4', 'kind' => 'video', 'mime' => 'video/mp4']);

        $this->work();

        $this->assertSame(['walk.webm', 'video/webm'], [$webm->fresh()->name, $webm->fresh()->mime]);
        Storage::disk(DriveFile::DISK)->assertExists('drive/1/walk.webm');
        // Only the WebM was sent
        Http::assertSentCount(1);
    }
}
