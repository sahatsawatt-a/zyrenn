<?php

namespace Tests\Feature;

use App\Models\Note\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotePdfTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.7\n%fake\n";

    private function fakePrinter(array $headers = [], string $body = self::PDF, int $status = 200): void
    {
        Http::fake([
            'chrome:3000/pdf' => Http::response($body, $status, [
                'Content-Type' => 'application/pdf',
                'X-Pdf-Final-Url' => 'http://web:8080/notes/x',
                'X-Pdf-Painted' => '1',
                'X-Pdf-Broken' => '0',
                ...$headers,
            ]),
        ]);
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $note = Note::factory()->create();

        $this->get(route('notes.pdf', $note))->assertRedirect(route('login'));
    }

    public function test_the_owner_gets_the_note_as_a_pdf_download()
    {
        $this->fakePrinter();
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create(['title' => 'Quarterly plan']);

        $response = $this->actingAs($user)->get(route('notes.pdf', $note));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeaderMissing('X-Pdf-Warning');
        $this->assertSame(self::PDF, $response->getContent());
        $this->assertStringContainsString('attachment; filename=quarterly-plan.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_a_title_with_slashes_still_makes_a_filename()
    {
        $this->fakePrinter();
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create(['title' => 'Q3/Q4 plan\\draft']);

        $response = $this->actingAs($user)->get(route('notes.pdf', $note));

        $response->assertOk();
        $this->assertStringContainsString("filename*=utf-8''Q3-Q4%20plan-draft.pdf", $response->headers->get('Content-Disposition'));
    }

    public function test_the_printer_opens_the_note_page_on_web_with_the_callers_session()
    {
        $this->fakePrinter();
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();
        $cookie = config('session.cookie');

        // Passed on byte for byte, as the raw header carried it
        $this->actingAs($user)
            ->withHeader('Cookie', "{$cookie}=still-encrypted+value=")
            ->get(route('notes.pdf', $note))
            ->assertOk();

        Http::assertSent(fn (Request $request) => $request['url'] === 'http://web:8080/notes/'.$note->ref_id.'?print=simple'
            && $request['cookies'] === [[
                'name' => $cookie,
                'value' => 'still-encrypted+value=',
                'domain' => 'web',
                'path' => '/',
            ]]);
    }

    public function test_the_report_style_is_passed_to_the_page()
    {
        $this->fakePrinter();
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('notes.pdf', ['note' => $note, 'style' => 'report']))
            ->assertOk();

        Http::assertSent(fn (Request $request) => str_ends_with($request['url'], '?print=report'));
    }

    public function test_an_unknown_style_is_refused()
    {
        $this->fakePrinter();
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();

        $this->actingAs($user)
            ->getJson(route('notes.pdf', ['note' => $note, 'style' => 'fancy']))
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_the_note_page_carries_the_print_style_it_was_opened_with()
    {
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('notes.show', ['note' => $note, 'print' => 'report']))
            ->assertSee('data-print-style="report"', false);

        $this->actingAs($user)
            ->get(route('notes.show', ['note' => $note, 'print' => '"><script>']))
            ->assertDontSee('data-print-style', false);
    }

    public function test_someone_elses_note_cannot_be_printed()
    {
        $this->fakePrinter();
        $note = Note::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('notes.pdf', $note))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_pdf_with_a_missing_picture_says_so()
    {
        $this->fakePrinter(['X-Pdf-Broken' => '2']);
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('notes.pdf', $note))
            ->assertOk()
            ->assertHeader('X-Pdf-Warning', '2 pictures did not load and are blank in the PDF.');
    }

    public function test_a_printer_sent_to_the_login_page_is_an_error_not_a_pdf()
    {
        $this->fakePrinter(['X-Pdf-Final-Url' => 'http://web:8080/login']);
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();

        $this->actingAs($user)
            ->getJson(route('notes.pdf', $note))
            ->assertStatus(502)
            ->assertJsonStructure(['message']);
    }

    public function test_a_failing_printer_is_an_error()
    {
        $this->fakePrinter(body: '{"error":"chromium unavailable"}', status: 503);
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();

        $this->actingAs($user)
            ->getJson(route('notes.pdf', $note))
            ->assertStatus(502);
    }

    public function test_an_answer_that_is_not_a_pdf_is_an_error()
    {
        $this->fakePrinter(body: '<html>oops</html>');
        $user = User::factory()->create();
        $note = Note::factory()->for($user)->create();

        $this->actingAs($user)
            ->getJson(route('notes.pdf', $note))
            ->assertStatus(502);
    }
}
