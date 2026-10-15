<?php

namespace Tests\Feature;

use App\Models\Drive\DriveFile;
use App\Models\Note\Note;
use App\Models\Note\NoteFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

class NoteExportTest extends TestCase
{
    use RefreshDatabase;

    private const SHOT = "\x89PNG\r\n\x1a\nfake";

    /**
     * What the zip holds: each path with its contents.
     *
     * @return array<string, string>
     */
    private function unzip(TestResponse $response): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $files = [];

        for ($at = 0; $at < $zip->numFiles; $at++) {
            $name = (string) $zip->getNameIndex($at);
            $files[$name] = (string) $zip->getFromIndex($at);
        }

        $zip->close();
        ksort($files);

        return $files;
    }

    /** A note's document: a paragraph, then the blocks given. */
    private function doc(array ...$blocks): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => 'p0000001'], 'content' => [['type' => 'text', 'text' => 'Hello']]],
            ...$blocks,
        ]];
    }

    private function code(string $id, string $language, string $code): array
    {
        return ['type' => 'codeBlock', 'attrs' => ['id' => $id, 'language' => $language], 'content' => [['type' => 'text', 'text' => $code]]];
    }

    public function test_a_folder_goes_out_as_markdown_with_its_pictures_and_its_folders()
    {
        Storage::fake('local');
        Http::fake(['chrome:3000/shots' => Http::response(['shots' => ['circ0001' => base64_encode(self::SHOT)], 'finalUrl' => 'http://web:8080/notes/x'])]);
        $user = User::factory()->create();
        $kt = NoteFolder::factory()->for($user)->create(['name' => 'KT']);
        $deep = NoteFolder::factory()->for($user)->create(['name' => 'Deep', 'parent_id' => $kt->id]);
        $elsewhere = NoteFolder::factory()->for($user)->create(['name' => 'Elsewhere']);
        $picture = DriveFile::factory()->for($user)->create(['name' => 'Wiring photo.png']);
        Storage::disk('local')->put($picture->path, 'png bytes');

        $note = Note::factory()->for($user)->create(['title' => 'Setup', 'folder_id' => $kt->id, 'content' => $this->doc(
            ['type' => 'image', 'attrs' => ['id' => 'img00001', 'src' => '/drive/files/'.$picture->ref_id, 'alt' => 'wiring']],
            $this->code('circ0001', 'circuit', 'R1 1 2 10k'),
            $this->code('merm0001', 'mermaid', "graph LR\n  A --> B"),
        )]);
        Note::factory()->for($user)->create(['title' => 'Q3/Q4', 'folder_id' => $deep->id, 'content' => $this->doc(
            ['type' => 'image', 'attrs' => ['id' => 'img00002', 'src' => '/drive/files/'.$picture->ref_id, 'alt' => '']],
        )]);
        Note::factory()->for($user)->create(['title' => 'Not this one', 'folder_id' => $elsewhere->id]);

        $response = $this->actingAs($user)->get(route('notes.export', ['folder' => $kt->ref_id]));

        $response->assertOk()->assertHeader('Content-Type', 'application/zip')->assertHeaderMissing('X-Export-Warning');
        $this->assertStringContainsString('filename=KT.zip', $response->headers->get('Content-Disposition'));
        $files = $this->unzip($response);

        $this->assertEqualsCanonicalizing([
            'KT/Deep/Q3-Q4.md',
            'KT/Deep/assets/wiring-photo.png',
            'KT/Setup.md',
            'KT/assets/'.$note->ref_id.'-circuit.png',
            'KT/assets/wiring-photo.png',
        ], array_keys($files));
        $setup = $files['KT/Setup.md'];
        // A Drive picture is its file beside the note; the circuit its picture
        $this->assertStringContainsString('![wiring](assets/wiring-photo.png)', $setup);
        $this->assertStringContainsString('![Circuit](assets/'.$note->ref_id.'-circuit.png)', $setup);
        $this->assertStringNotContainsString('R1 1 2 10k', $setup);
        // Mermaid stays as its code, which Markdown viewers draw
        $this->assertStringContainsString("```mermaid\ngraph LR", $setup);
        $this->assertSame('png bytes', $files['KT/assets/wiring-photo.png']);
        $this->assertSame(self::SHOT, $files['KT/assets/'.$note->ref_id.'-circuit.png']);

        // Only the diagrams are asked for, of the note as it prints
        Http::assertSent(fn (Request $request) => $request->url() === 'http://chrome:3000/shots'
            && $request['ids'] === ['circ0001']
            && str_contains($request['url'], '/notes/'.$note->ref_id.'?print=simple'));
        Http::assertSentCount(1);
    }

    public function test_a_diagram_the_browser_could_not_picture_stays_as_its_code()
    {
        Storage::fake('local');
        Http::fake(['chrome:3000/shots' => Http::response('down', 500)]);
        $user = User::factory()->create();
        Note::factory()->for($user)->create(['title' => 'Plan', 'content' => $this->doc($this->code('plot0001', 'plot', 'y = x^2'))]);

        $response = $this->actingAs($user)->get(route('notes.export'));

        $response->assertOk()->assertHeader('X-Export-Warning', 'One diagram could not be pictured and is left as its code.');
        $this->assertStringContainsString("```plot\ny = x^2\n```", $this->unzip($response)['Notes/Plan.md']);
    }

    public function test_someone_elses_drive_file_is_linked_not_packed()
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $theirs = DriveFile::factory()->create();
        Storage::disk('local')->put($theirs->path, 'secret');
        Note::factory()->for($user)->create(['title' => 'Mine', 'content' => $this->doc(
            ['type' => 'image', 'attrs' => ['id' => 'img00001', 'src' => '/drive/files/'.$theirs->ref_id]],
        )]);

        $files = $this->unzip($this->actingAs($user)->get(route('notes.export'))->assertOk());

        $this->assertSame(['Notes/Mine.md'], array_keys($files));
        $this->assertStringContainsString(url('/drive/files/'.$theirs->ref_id), $files['Notes/Mine.md']);
        Http::assertNothingSent();
    }

    public function test_only_a_folder_of_the_owner_can_be_exported()
    {
        $user = User::factory()->create();
        $theirs = NoteFolder::factory()->create();

        $this->actingAs($user)->get(route('notes.export', ['folder' => $theirs->ref_id]))->assertSessionHasErrors('folder');
        $this->get(route('notes.export'))->assertOk();
        auth()->logout();
        $this->get(route('notes.export'))->assertRedirect(route('login'));
    }
}
