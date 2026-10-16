<?php

namespace Tests\Feature;

use App\Models\Drive\DriveFile;
use App\Models\Drive\DriveFolder;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

class DriveZipTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Each file the zip holds, with how it was put in and what it says.
     *
     * @return array<string, array{stored: bool, data: string}>
     */
    private function unzip(TestResponse $response): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $files = [];

        for ($at = 0; $at < $zip->numFiles; $at++) {
            $stat = (array) $zip->statIndex($at);
            $files[(string) $stat['name']] = ['stored' => $stat['comp_method'] === ZipArchive::CM_STORE, 'data' => (string) $zip->getFromIndex($at)];
        }

        $zip->close();

        return $files;
    }

    private function file(User|Project $owner, ?DriveFolder $folder, string $name, string $kind, string $data): DriveFile
    {
        $file = DriveFile::factory()->create([
            $owner instanceof Project ? 'project_id' : 'user_id' => $owner->id,
            ...$owner instanceof Project ? ['user_id' => null] : [],
            'folder_id' => $folder?->id,
            'name' => $name,
            'kind' => $kind,
            'mime' => $kind === 'video' ? 'video/mp4' : 'text/plain',
        ]);
        Storage::disk(DriveFile::DISK)->put($file->path, $data);

        return $file;
    }

    public function test_a_folder_downloads_as_a_zip_with_its_folders_in_it()
    {
        Storage::fake(DriveFile::DISK);
        $user = User::factory()->create();
        $trip = DriveFolder::factory()->for($user)->create(['name' => 'Trip']);
        $days = DriveFolder::factory()->for($user)->create(['name' => 'Day 1', 'parent_id' => $trip->id]);
        $elsewhere = DriveFolder::factory()->for($user)->create(['name' => 'Elsewhere']);
        $this->file($user, $trip, 'plan.txt', 'doc', 'the plan');
        $this->file($user, $trip, 'plan.txt', 'doc', 'another plan');
        $this->file($user, $days, 'walk.mp4', 'video', 'film');
        $this->file($user, $elsewhere, 'not this.txt', 'doc', 'no');

        $response = $this->actingAs($user)->get(route('drive.zip', ['folder' => $trip->ref_id]));

        $response->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('filename=Trip.zip', $response->headers->get('Content-Disposition'));
        $files = $this->unzip($response);
        $this->assertEqualsCanonicalizing(['Trip/plan.txt', 'Trip/plan (2).txt', 'Trip/Day 1/walk.mp4'], array_keys($files));
        $this->assertSame('film', $files['Trip/Day 1/walk.mp4']['data']);
        // A film is stored as it is; words are compressed
        $this->assertTrue($files['Trip/Day 1/walk.mp4']['stored']);
        $this->assertFalse($files['Trip/plan.txt']['stored']);
    }

    public function test_the_whole_drive_downloads_and_a_projects_drive_too()
    {
        Storage::fake(DriveFile::DISK);
        $user = User::factory()->create();
        $folder = DriveFolder::factory()->for($user)->create(['name' => 'Docs']);
        $this->file($user, null, 'top.txt', 'doc', 'top');
        $this->file($user, $folder, 'inside.txt', 'doc', 'inside');

        $this->assertEqualsCanonicalizing(
            ['Drive/top.txt', 'Drive/Docs/inside.txt'],
            array_keys($this->unzip($this->actingAs($user)->get(route('drive.zip'))->assertOk())),
        );

        // A project's comes with its name: on the zip, and at its top
        $project = Project::start($user, 'Team');
        $days = DriveFolder::factory()->create(['user_id' => null, 'project_id' => $project->id, 'name' => 'Days']);
        $this->file($project, null, 'shared.txt', 'doc', 'shared');
        $this->file($project, $days, 'one.txt', 'doc', 'one');

        $whole = $this->get(route('projects.drive.zip', ['project' => $project]))->assertOk();
        $this->assertStringContainsString('filename="Team - Drive.zip"', $whole->headers->get('Content-Disposition'));
        $this->assertEqualsCanonicalizing(['Team/shared.txt', 'Team/Days/one.txt'], array_keys($this->unzip($whole)));

        $folder = $this->get(route('projects.drive.zip', ['project' => $project, 'folder' => $days->ref_id]))->assertOk();
        $this->assertStringContainsString('filename="Team - Days.zip"', $folder->headers->get('Content-Disposition'));
        $this->assertSame(['Team/Days/one.txt'], array_keys($this->unzip($folder)));
    }

    public function test_only_your_own_folder_and_only_when_signed_in()
    {
        $user = User::factory()->create();
        $theirs = DriveFolder::factory()->create();

        $this->actingAs($user)->get(route('drive.zip', ['folder' => $theirs->ref_id]))->assertSessionHasErrors('folder');
        auth()->logout();
        $this->get(route('drive.zip'))->assertRedirect(route('login'));
    }
}
