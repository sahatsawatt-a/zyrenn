<?php

namespace App\Support\Note;

use App\Models\Drive\DriveFile;
use App\Models\Note\Note;
use App\Models\User;
use App\Support\Chrome\Chrome;
use App\Support\Formula\NoteValues;
use App\Support\Markdown\TiptapToMarkdown;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Notes as a folder of Markdown files, zipped: each note a `.md` file where
 * its folder is, and beside it an `assets` folder with what it shows -- its
 * pictures from the Drive, and a picture of each diagram that only this app
 * draws (a circuit, a graph, a timing diagram, a board, a trip, a map), so
 * the files read the same anywhere. A Mermaid diagram stays as its code,
 * which Markdown viewers draw themselves.
 *
 * A link to another note in the zip goes to its file, from where the
 * linking one is; a link to anything else in the app goes to it there, in
 * full. What only the app reads is written as what any viewer does: a
 * callout as a quote, a reveal as <details>, a live value as what it comes
 * to now (TiptapToMarkdown's portable form).
 */
class NoteExport
{
    /** Code blocks drawn by the app alone, which go out as pictures. */
    public const PICTURED = ['circuit', 'plot', 'timing', 'board', 'trip', 'map'];

    /** The width the note is laid out at for its pictures, and how sharp they are. */
    private const WIDTH = 900;

    private const SCALE = 2;

    /** A Drive file's address in a note: /drive/files/{ref}, on this app or not. */
    private const DRIVE = '#^(?:https?://[^/]+)?/drive/files/([A-Za-z0-9]+)(?:[?\#].*)?$#';

    private ZipArchive $zip;

    /**
     * What went in at each path, so a name is never used twice.
     *
     * @var array<string, true>
     */
    private array $taken = [];

    /**
     * Each Drive file packed, by folder and ref, at its path from that folder.
     *
     * @var array<string, string>
     */
    private array $packed = [];

    /**
     * The file each exported note went into, by ref_id, from the top of the zip.
     *
     * @var array<string, string>
     */
    private array $files = [];

    /** The file being written now, from the top of the zip. */
    private string $here = '';

    /**
     * What each live value in the note being written comes to now, by its formula.
     *
     * @var array<string, array{value?: mixed, text?: string, error?: string}>
     */
    private array $values = [];

    /** How many diagrams could not be pictured, and stayed as their code. */
    public int $unpictured = 0;

    private function __construct(private User $user, private ?string $sessionCookie) {}

    /**
     * Zip the notes, each into the folder given with it ("" for the top), and
     * answer with where the zip is: a temporary file the caller sends and
     * deletes.
     *
     * @param  list<array{note: Note, folder: string}>  $notes
     * @return array{path: string, unpictured: int}
     */
    public static function zip(array $notes, User $user, ?string $sessionCookie): array
    {
        $export = new self($user, $sessionCookie);
        $path = tempnam(sys_get_temp_dir(), 'notes-');

        if ($path === false) {
            throw new RuntimeException('No room for the zip');
        }

        $export->zip = new ZipArchive;

        if ($export->zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The zip could not be opened');
        }

        // Every note's file named before any is written, so each can link to the others
        $named = [];

        foreach ($notes as ['note' => $note, 'folder' => $folder]) {
            $folder = trim($folder, '/');
            $prefix = $folder === '' ? '' : $folder.'/';
            $file = $prefix.$export->unique($prefix, self::fileName($note->title), '.md').'.md';

            $export->files[$note->ref_id] = $file;
            $named[] = [$note, $prefix, $file];
        }

        foreach ($named as [$note, $prefix, $file]) {
            $export->add($note, $prefix, $file);
        }

        // An empty folder is still a zip, not an error
        if ($export->zip->numFiles === 0) {
            $export->zip->addEmptyDir('notes');
        }

        $export->zip->close();

        return ['path' => $path, 'unpictured' => $export->unpictured];
    }

    private function add(Note $note, string $prefix, string $path): void
    {
        $doc = NoteBlocks::withIds($note->content) ?? ['type' => 'doc', 'content' => []];
        $this->here = $path;
        $expressions = NoteValues::expressions($doc);
        $this->values = $expressions === [] ? [] : NoteValues::of($note, $expressions);

        $wanted = [];
        $this->diagrams($doc, $wanted);
        $shots = $wanted === [] ? [] : $this->shots($note, array_keys($wanted));

        $doc = $this->rewrite($doc, $note, $prefix, $shots);

        $this->zip->addFromString($path, (new TiptapToMarkdown(portable: true))->convert($doc));
    }

    /**
     * The ids of the blocks to picture, each with its kind.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, string>  $wanted
     */
    private function diagrams(array $node, array &$wanted): void
    {
        $language = $node['attrs']['language'] ?? null;

        if (($node['type'] ?? '') === 'codeBlock' && in_array($language, self::PICTURED, true) && is_string($node['attrs']['id'] ?? null)) {
            $wanted[$node['attrs']['id']] = $language;

            return;
        }

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $this->diagrams($child, $wanted);
            }
        }
    }

    /**
     * Pictures of the blocks, by id, taken of the real note in a real browser
     * (docker/chrome, /shots), signed in as the caller -- as NotePdf prints it.
     * None when the browser is not there: the blocks then stay as code.
     *
     * @param  list<string>  $ids
     * @return array<string, string> the PNG bytes of each block pictured
     */
    private function shots(Note $note, array $ids): array
    {
        try {
            $response = Chrome::post('/shots', [
                'url' => Chrome::at(route('notes.show', ['note' => $note, 'print' => 'simple'], absolute: false)),
                'cookies' => Chrome::signedInAs($this->sessionCookie),
                'ids' => $ids,
                'width' => self::WIDTH,
                'scale' => self::SCALE,
            ]);
        } catch (ConnectionException|RuntimeException $e) {
            report($e);

            return [];
        }

        if (Chrome::signedOut($response->json('finalUrl'))) {
            report(new RuntimeException('Pictures of a note\'s diagrams were sent to the sign-in page'));

            return [];
        }

        return array_filter(array_map(
            fn ($shot) => is_string($shot) ? (base64_decode($shot, true) ?: null) : null,
            (array) $response->json('shots', []),
        ));
    }

    /**
     * The note with what it shows pointed into its `assets` folder: a diagram
     * as its picture, a Drive picture or video as its file, a link to a Drive
     * file to that file -- so the export plays and shows for whoever gets it,
     * signed in to Zyrenn or not. A Drive address left in it (a file the
     * caller may not see) is made whole.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, string>  $shots
     * @return array<string, mixed>
     */
    private function rewrite(array $node, Note $note, string $prefix, array $shots): array
    {
        $type = $node['type'] ?? '';
        $attrs = $node['attrs'] ?? [];

        if ($type === 'codeBlock' && in_array($attrs['language'] ?? null, self::PICTURED, true)) {
            $shot = $shots[$attrs['id'] ?? ''] ?? null;

            if ($shot === null) {
                $this->unpictured++;

                return $node;
            }

            $path = $this->put($prefix, 'assets/'.$note->ref_id.'-'.$attrs['language'], '.png', $shot);

            return ['type' => 'image', 'attrs' => ['src' => $path, 'alt' => $this->caption((string) $attrs['language'])]];
        }

        if ($type === PageLinks::NODE) {
            return $this->pageLink($attrs, $note);
        }

        // As the chip shows it now; a formula that fails stays as written
        if ($type === 'formula') {
            $expression = (string) ($attrs['expression'] ?? '');
            $answer = $this->values[$expression] ?? ['error' => ''];

            return isset($answer['error'])
                ? ['type' => 'text', 'text' => '{{ '.$expression.' }}', 'marks' => [['type' => 'code']]]
                : ['type' => 'text', 'text' => (string) ($answer['text'] ?? '')];
        }

        if (in_array($type, ['image', 'video'], true) && is_string($attrs['src'] ?? null)) {
            $node['attrs']['src'] = $this->drive($attrs['src'], $prefix, true);
        }

        foreach ($node['marks'] ?? [] as $at => $mark) {
            if (($mark['type'] ?? '') === 'link' && is_string($mark['attrs']['href'] ?? null)) {
                $node['marks'][$at]['attrs']['href'] = $this->address($this->drive($mark['attrs']['href'], $prefix, true));
            }
        }

        foreach ($node['content'] ?? [] as $at => $child) {
            if (is_array($child)) {
                $node['content'][$at] = $this->rewrite($child, $note, $prefix, $shots);
            }
        }

        return $node;
    }

    /**
     * Where a Drive address points from the exported file: its copy in
     * `assets` when it is to be packed and the caller may see it, otherwise
     * the whole address. Anything else stays as it is.
     */
    private function drive(string $src, string $prefix, bool $pack): string
    {
        if (! preg_match(self::DRIVE, $src, $match)) {
            return $src;
        }

        $file = DriveFile::query()->where('ref_id', $match[1])->first();
        $whole = str_starts_with($src, '/') ? url($src) : $src;

        if (! $pack || ! $file || ! Gate::forUser($this->user)->allows('view', $file)) {
            return $whole;
        }

        $disk = Storage::disk(DriveFile::DISK);

        if (! $disk->exists($file->path)) {
            return $whole;
        }

        // Each Drive file packed once beside the notes in a folder
        $key = $prefix.'#'.$file->ref_id;

        if (! isset($this->packed[$key])) {
            $extension = pathinfo($file->name, PATHINFO_EXTENSION);
            $extension = $extension === '' ? '' : '.'.strtolower($extension);
            $name = $this->unique($prefix, 'assets/'.(Str::slug(pathinfo($file->name, PATHINFO_FILENAME)) ?: 'file'), $extension);

            $this->zip->addFile($disk->path($file->path), $prefix.$name.$extension);
            $this->packed[$key] = $name.$extension;
        }

        return self::link($this->packed[$key]);
    }

    /**
     * A link to a page by its name, as an ordinary link: to the note's file
     * when it is in the zip, to the page in the app when the caller may see
     * it, and only its words when there is no such page for them.
     *
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function pageLink(array $attrs, Note $note): array
    {
        $words = fn (mixed $text) => trim(str_replace(['[', ']', "\n"], ' ', (string) $text));
        [$page] = PageLinks::describe([$attrs], $note, $this->user);
        $text = $words($attrs['text'] ?? '') ?: $words($page['title']) ?: $words($attrs['title'] ?? '') ?: 'Untitled';

        $href = match (true) {
            $page['kind'] === 'note' && isset($this->files[$page['ref']]) => $this->from($this->files[$page['ref']]),
            $page['url'] !== null => url($page['url']),
            default => null,
        };

        return $href === null
            ? ['type' => 'text', 'text' => $text]
            : ['type' => 'text', 'text' => $text, 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]];
    }

    /**
     * Where an ordinary link goes from the exported file: a note of the zip
     * to its file, any other address in the app in full, so it opens from
     * elsewhere. Links out of the app, and those into the zip, stay.
     */
    private function address(string $href): string
    {
        $app = preg_quote((string) parse_url(url('/'), PHP_URL_HOST), '#');

        if (preg_match('#^(?:https?://'.$app.'(?::\d+)?)?/notes/([A-Za-z0-9]+)(?:[/?\#].*)?$#', $href, $match) && isset($this->files[$match[1]])) {
            return $this->from($this->files[$match[1]]);
        }

        return str_starts_with($href, '/') && ! str_starts_with($href, '//') ? url($href) : $href;
    }

    /** A file of the zip as a link from the file being written: "../Deep/Q3-Q4.md". */
    private function from(string $path): string
    {
        $here = explode('/', $this->here);
        $there = explode('/', $path);
        array_pop($here);

        // Down from the folder both are in
        while ($here !== [] && count($there) > 1 && $here[0] === $there[0]) {
            array_shift($here);
            array_shift($there);
        }

        return self::link(str_repeat('../', count($here)).implode('/', $there));
    }

    /** Put bytes into the zip under a name not taken, and answer with its link. */
    private function put(string $prefix, string $base, string $extension, string $bytes): string
    {
        $name = $this->unique($prefix, $base, $extension);
        $this->zip->addFromString($prefix.$name.$extension, $bytes);

        return self::link($name.$extension);
    }

    /** A name in the folder not used yet: the base, or the base with a number. */
    private function unique(string $prefix, string $base, string $extension): string
    {
        $name = $base;

        for ($n = 2; isset($this->taken[$prefix.$name.$extension]); $n++) {
            $name = $base.' ('.$n.')';
        }

        $this->taken[$prefix.$name.$extension] = true;

        return $name;
    }

    /** A relative link, each part of it escaped, as Markdown wants it. */
    private static function link(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function caption(string $language): string
    {
        return match ($language) {
            'circuit' => 'Circuit',
            'plot' => 'Graph',
            'timing' => 'Timing diagram',
            'board' => 'Board',
            'trip' => 'Trip',
            'map' => 'Map',
            default => 'Diagram',
        };
    }

    /** A title as a file or folder name: no slashes or marks a disk refuses. */
    public static function fileName(string $title): string
    {
        $name = trim((string) preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]+/u', '-', $title), ' .-');

        return Str::limit($name === '' ? 'Untitled' : $name, 120, '');
    }
}
