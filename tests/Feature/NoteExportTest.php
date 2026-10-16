<?php

namespace Tests\Feature;

use App\Models\Board\Board;
use App\Models\Drive\DriveFile;
use App\Models\Note\Note;
use App\Models\Note\NoteFolder;
use App\Models\Project;
use App\Models\User;
use App\Support\Markdown\TiptapMarkdown;
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

    /** A paragraph of the inline nodes given. */
    private function line(string $id, array ...$inline): array
    {
        return ['type' => 'paragraph', 'attrs' => ['id' => $id], 'content' => $inline];
    }

    /** A link to a page by its name, as the editor keeps it. */
    private function to(string $kind, string $ref, string $title, string $text = ''): array
    {
        return ['type' => 'pageLink', 'attrs' => ['kind' => $kind, 'ref' => $ref, 'title' => $title, 'text' => $text]];
    }

    private function words(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
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

    public function test_a_projects_notes_go_out_with_its_name()
    {
        $user = User::factory()->create();
        $project = Project::start($user, 'Lakeshore');
        $kt = NoteFolder::factory()->create(['user_id' => null, 'project_id' => $project->id, 'name' => 'KT']);
        Note::factory()->create(['user_id' => null, 'project_id' => $project->id, 'title' => 'Plan', 'folder_id' => $kt->id, 'content' => $this->doc()]);
        Note::factory()->create(['user_id' => null, 'project_id' => $project->id, 'title' => 'Top', 'content' => $this->doc()]);

        $folder = $this->actingAs($user)->get(route('projects.notes.export', ['project' => $project, 'folder' => $kt->ref_id]))->assertOk();
        $this->assertStringContainsString('filename="Lakeshore - KT.zip"', $folder->headers->get('Content-Disposition'));
        $this->assertSame(['Lakeshore/KT/Plan.md'], array_keys($this->unzip($folder)));

        $all = $this->get(route('projects.notes.export', ['project' => $project]))->assertOk();
        $this->assertStringContainsString('filename="Lakeshore - Notes.zip"', $all->headers->get('Content-Disposition'));
        $this->assertSame(['Lakeshore/KT/Plan.md', 'Lakeshore/Top.md'], array_keys($this->unzip($all)));
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

    public function test_a_video_goes_with_the_notes_and_plays_or_opens_from_its_file()
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $film = DriveFile::factory()->for($user)->create(['name' => 'Network home.webm', 'mime' => 'video/webm', 'ext' => 'webm', 'kind' => 'video']);
        Storage::disk('local')->put($film->path, 'film bytes');
        Note::factory()->for($user)->create(['title' => 'Tour', 'content' => $this->doc(
            ['type' => 'video', 'attrs' => ['id' => 'vid00001', 'src' => '/drive/files/'.$film->ref_id, 'title' => 'Network home at a glance']],
        )]);

        $files = $this->unzip($this->actingAs($user)->get(route('notes.export'))->assertOk());

        $this->assertSame('film bytes', $files['Notes/assets/network-home.webm']);
        // A player where HTML video is drawn, and a link to the file where it is not
        $this->assertStringContainsString('<video src="assets/network-home.webm" controls title="Network home at a glance"></video>', $files['Notes/Tour.md']);
        $this->assertStringContainsString('[▶ Network home at a glance](assets/network-home.webm)', $files['Notes/Tour.md']);
        $this->assertStringNotContainsString('/drive/files/', $files['Notes/Tour.md']);
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

    public function test_links_to_notes_in_the_zip_go_to_their_files_and_others_to_the_app()
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $kt = NoteFolder::factory()->for($user)->create(['name' => 'KT']);
        $deep = NoteFolder::factory()->for($user)->create(['name' => 'Deep', 'parent_id' => $kt->id]);
        $elsewhere = NoteFolder::factory()->for($user)->create(['name' => 'Elsewhere']);
        $board = Board::factory()->for($user)->create(['title' => 'Wiring']);
        $outside = Note::factory()->for($user)->create(['title' => 'Outside', 'folder_id' => $elsewhere->id]);
        $theirs = Note::factory()->create(['title' => 'Private']);

        $setup = Note::factory()->for($user)->create(['title' => 'Setup', 'folder_id' => $kt->id]);
        $again = Note::factory()->for($user)->create(['title' => 'Setup', 'folder_id' => $kt->id]);
        $deeper = Note::factory()->for($user)->create(['title' => 'Q3/Q4', 'folder_id' => $deep->id]);

        $setup->update(['content' => ['type' => 'doc', 'content' => [
            $this->line('p0000001', $this->words('See '), $this->to('note', $deeper->ref_id, 'Q3/Q4', 'the plan'), $this->words('.')),
            $this->line('p0000002', $this->to('note', $again->ref_id, 'Setup')),
            $this->line('p0000003', $this->to('board', $board->ref_id, 'Wiring')),
            $this->line('p0000004', $this->to('note', $outside->ref_id, 'Outside')),
            $this->line('p0000005', $this->to('note', $theirs->ref_id, 'Private')),
            $this->line('p0000006', $this->to('note', '', 'Gone')),
            $this->line('p0000007', ['type' => 'text', 'text' => 'by address', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/notes/'.$deeper->ref_id]]]]),
        ]]]);
        $deeper->update(['content' => ['type' => 'doc', 'content' => [
            $this->line('p0000008', $this->words('Back to '), $this->to('note', $setup->ref_id, 'Setup')),
        ]]]);

        $files = $this->unzip($this->actingAs($user)->get(route('notes.export', ['folder' => $kt->ref_id]))->assertOk());
        $first = $files['KT/Setup.md'];

        // To the other note's file, from where this one is, in its words
        $this->assertStringContainsString('See [the plan](Deep/Q3-Q4.md).', $first);
        // An ordinary link to the note's address, too
        $this->assertStringContainsString('[by address](Deep/Q3-Q4.md)', $first);
        // The second of two names is numbered, and its link says so
        $this->assertArrayHasKey('KT/Setup (2).md', $files);
        $this->assertStringContainsString('[Setup](Setup%20%282%29.md)', $first);
        // And back up from the folder below
        $this->assertStringContainsString('Back to [Setup](../Setup.md)', $files['KT/Deep/Q3-Q4.md']);
        // Anything not in the zip opens in the app
        $this->assertStringContainsString('[Wiring]('.url(route('boards.show', $board, false)).')', $first);
        $this->assertStringContainsString('[Outside]('.url(route('notes.show', $outside, false)).')', $first);
        // What the reader can't open, or what isn't there, is only its words
        $this->assertStringNotContainsString($theirs->ref_id, $first);
        $this->assertStringContainsString("\n\nPrivate\n\nGone\n\n", $first);
        $this->assertStringNotContainsString('[[', $first);
    }

    public function test_blocks_only_the_app_reads_go_out_as_any_markdown_viewer_reads_them()
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $doc = $this->doc(
            ['type' => 'callout', 'attrs' => ['id' => 'call0001', 'icon' => '⚠️'], 'content' => [
                $this->line('p0000002', $this->words('Careful')),
                $this->line('p0000003', $this->words('Second line')),
            ]],
            ['type' => 'pageBreak', 'attrs' => ['id' => 'brk00001']],
            ['type' => 'reveal', 'attrs' => ['id' => 'rev00001', 'title' => 'Why <this>?'], 'content' => [
                $this->line('p0000004', $this->words('Because')),
            ]],
            $this->line('p0000005', $this->words('Total: '), ['type' => 'formula', 'attrs' => ['expression' => '1 + 2']], $this->words(', broken: '), ['type' => 'formula', 'attrs' => ['expression' => '1 +']]),
            ['type' => 'blockMath', 'attrs' => ['id' => 'math0001', 'latex' => 'E = mc^2']],
        );
        Note::factory()->for($user)->create(['title' => 'Plain', 'content' => $doc]);

        $plain = $this->unzip($this->actingAs($user)->get(route('notes.export'))->assertOk())['Notes/Plain.md'];

        $this->assertSame(
            "Hello\n\n> ⚠️ Careful\n>\n> Second line\n\n<details><summary>Why &lt;this&gt;?</summary>\n\nBecause\n\n</details>\n\n"
            ."Total: 3, broken: `{{ 1 + }}`\n\n$$\nE = mc^2\n$$\n",
            $plain,
        );

        // Read through MCP, a note keeps the syntax that comes back as the same blocks
        $kept = TiptapMarkdown::toMarkdown($doc);
        $this->assertStringContainsString(":::callout ⚠️\nCareful", $kept);
        $this->assertStringContainsString(':::reveal Why <this>?', $kept);
        $this->assertStringContainsString('<!--', $kept);
        $this->assertStringContainsString('{{ 1 + 2 }}', $kept);
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
