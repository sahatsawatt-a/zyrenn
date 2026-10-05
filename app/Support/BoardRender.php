<?php

namespace App\Support;

use App\Models\Board\Board;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A board as a picture or a PDF, drawn by a real browser.
 *
 * As with a note's PDF (NotePdf), nothing draws the board a second time: the
 * `chrome` service (docker/chrome) opens a page that shows the board with the
 * canvas's own components, and screenshots or prints it. That page needs no
 * session -- an MCP client has none to lend -- so it is reached by a signed
 * link that lasts a few minutes, and so is every Drive picture on it.
 */
class BoardRender
{
    /** How wide a picture of a board comes out, in pixels. */
    public const PICTURE_WIDTH = 1600;

    /** How wide a PDF page is: 13.33 inches, a widescreen slide. */
    public const PAGE_WIDTH = 1280;

    /** How long the signed links the browser follows stay good. */
    private const LINK_MINUTES = 5;

    /**
     * A PNG of one frame, or of the whole board.
     *
     * @param  array<string, mixed>|null  $frame  the frame item, or null for everything
     *
     * @throws RuntimeException when what came back is not a picture
     */
    public static function png(Board $board, ?array $frame = null): string
    {
        [$width, $height] = self::size($board, $frame, self::PICTURE_WIDTH);

        $response = self::chrome('/png', [
            'url' => self::url($board, 'picture', $frame),
            'width' => $width,
            'height' => $height,
        ]);

        $body = $response->body();

        if (! str_starts_with($body, "\x89PNG")) {
            throw new RuntimeException('The renderer answered '.strlen($body).' bytes that are not a PNG');
        }

        return $body;
    }

    /**
     * A PDF of the board: a page for each frame, in the order they are
     * presented, leaving out any frame kept out of the PDF -- or, with no
     * frames, one page of everything.
     *
     * @throws HttpException when every frame is kept out of the PDF
     * @throws RuntimeException when what came back is not a PDF
     */
    public static function pdf(Board $board): string
    {
        $items = self::items($board);
        $pages = self::pages($items);

        abort_if(
            $pages === [] && BoardParts::frames($items) !== [],
            422,
            __('Every frame on this board is left out of the PDF.'),
        );

        $frame = $pages ? BoardParts::frame($items, $pages[0]) : null;
        [$width, $height] = self::size($board, $frame, self::PAGE_WIDTH);

        $response = self::chrome('/pdf', [
            'url' => self::url($board, 'pages', null),
            'width' => $width,
            'height' => $height,
            'pageSize' => ['width' => "{$width}px", 'height' => "{$height}px"],
        ]);

        $body = $response->body();

        if (! str_starts_with($body, '%PDF-')) {
            throw new RuntimeException('The PDF printer answered '.strlen($body).' bytes that are not a PDF');
        }

        return $body;
    }

    /**
     * The frames the PDF has a page for, by id: every one not kept out of it.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    public static function pages(array $items): array
    {
        $printed = array_filter($items, fn (array $item) => ($item['kind'] ?? '') === 'frame' && ($item['pdfHidden'] ?? false) !== true);

        return array_values(array_map(fn (array $frame) => (string) $frame['id'], $printed));
    }

    /**
     * The board's items as the render page draws them, each Drive file
     * behind a picture or a video given a signed link of its own: the browser
     * that draws them is signed in as nobody.
     *
     * @return list<array<string, mixed>>
     */
    public static function itemsForPage(Board $board): array
    {
        return array_map(function (array $item) {
            if (preg_match('#^/drive/files/([A-Za-z0-9]+)$#', (string) ($item['src'] ?? ''), $file)) {
                $item['src'] = URL::temporarySignedRoute(
                    'drive.files.signed',
                    now()->addMinutes(self::LINK_MINUTES),
                    ['file' => $file[1]],
                    absolute: false,
                );
            }

            return $item;
        }, self::items($board));
    }

    /**
     * How big the picture or page is: the given width, and the height that
     * keeps the frame's shape -- or the whole board's, held to something a
     * page or a screen can show.
     *
     * @param  array<string, mixed>|null  $frame
     * @return array{int, int}
     */
    public static function size(Board $board, ?array $frame, int $width): array
    {
        $box = $frame ?? self::bounds(self::items($board));
        $shape = $box ? max(0.25, min(2.0, (float) $box['height'] / max(1.0, (float) $box['width']))) : 9 / 16;

        return [$width, (int) round($width * $shape)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function items(Board $board): array
    {
        return array_values(array_filter($board->content['items'] ?? [], 'is_array'));
    }

    /**
     * What everything but the connectors covers.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{width: float, height: float}|null
     */
    private static function bounds(array $items): ?array
    {
        $boxes = array_filter($items, fn (array $item) => ($item['kind'] ?? '') !== BoardItems::CONNECTOR && ! ($item['hidden'] ?? false));

        if ($boxes === []) {
            return null;
        }

        $left = min(array_map(fn (array $item) => (float) $item['x'], $boxes));
        $top = min(array_map(fn (array $item) => (float) $item['y'], $boxes));
        $right = max(array_map(fn (array $item) => (float) $item['x'] + (float) $item['width'], $boxes));
        $bottom = max(array_map(fn (array $item) => (float) $item['y'] + (float) $item['height'], $boxes));

        // Room for the titles written above frames, as the page leaves it (BoardView)
        $titles = array_filter($items, fn (array $item) => ($item['kind'] ?? '') === 'frame') !== [] ? 34 : 0;

        return ['width' => $right - $left, 'height' => $bottom - $top + $titles];
    }

    /**
     * The render page, as the browser on the compose network reaches it.
     *
     * @param  array<string, mixed>|null  $frame
     */
    private static function url(Board $board, string $mode, ?array $frame): string
    {
        $origin = rtrim((string) config('services.chrome.origin'), '/');

        return $origin.URL::temporarySignedRoute(
            'boards.render',
            now()->addMinutes(self::LINK_MINUTES),
            array_filter(['board' => $board, 'mode' => $mode, 'frame' => $frame['id'] ?? null]),
            absolute: false,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function chrome(string $path, array $body): Response
    {
        $response = Http::timeout(120)->connectTimeout(10)
            ->post(rtrim((string) config('services.chrome.url'), '/').$path, $body);

        if (! $response->successful()) {
            throw new RuntimeException('The renderer answered '.$response->status().': '.mb_substr(trim($response->body()), 0, 500));
        }

        return $response;
    }
}
