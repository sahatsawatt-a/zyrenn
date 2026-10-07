<?php

namespace App\Http\Controllers;

use App\Support\Links\LinkPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Previews of links put in a note: what a page is called and shows, or the
 * place a map link names (LinkPreview), and the pictures for them, served
 * from here rather than from wherever they live.
 */
class LinkPreviewController extends Controller
{
    /** The longest URL looked at; longer ones are not links anyone types. */
    private const URL = ['required', 'string', 'max:2048', 'regex:#^https?://#i'];

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate(['url' => self::URL]);

        return response()->json(LinkPreview::of($validated['url']));
    }

    /**
     * A picture a preview found -- its og:image, or a site's icon.
     */
    public function image(Request $request): Response
    {
        $validated = $request->validate(['url' => self::URL]);

        return $this->picture(LinkPreview::image($validated['url']));
    }

    /**
     * A site's icon, by its host name: shown beside a link to it.
     */
    public function icon(Request $request): Response
    {
        $validated = $request->validate(['host' => ['required', 'string', 'max:253']]);
        $icon = LinkPreview::iconOf(strtolower($validated['host']));

        return $this->picture($icon === null ? null : LinkPreview::image($icon));
    }

    /**
     * @param  array{bytes: string, type: string}|null  $picture
     */
    private function picture(?array $picture): Response
    {
        // Nothing to show is no error -- most sites have no picture for a card,
        // some no icon -- so it says so without a 404 in every reader's console
        if ($picture === null) {
            return response('', 204, ['Cache-Control' => 'private, max-age=86400']);
        }

        return response($picture['bytes'], 200, [
            'Content-Type' => $picture['type'],
            'Cache-Control' => 'private, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
