<?php

namespace App\Http\Controllers;

use App\Support\Guide\Guide;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The guide at /guide: its topics, and the one being read -- the first when
 * none is named.
 */
class GuideController extends Controller
{
    public function __invoke(?string $topic = null): Response
    {
        $topics = Guide::topics();
        $reading = Guide::topic($topic ?? $topics[0]['slug'] ?? '');

        abort_if($reading === null, 404);

        return Inertia::render('guide/Show', [
            'topics' => $topics,
            'topic' => $reading,
        ]);
    }
}
