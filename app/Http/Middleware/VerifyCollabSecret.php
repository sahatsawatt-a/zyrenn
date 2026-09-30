<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only the collaboration server, which knows COLLAB_SECRET, may load and
 * store shared documents. Off (404) until a secret is set.
 */
class VerifyCollabSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.collab.secret');

        abort_if(blank($secret), 404);

        $given = $request->header('X-Collab-Secret');

        abort_unless(is_string($given) && hash_equals($secret, $given), 403);

        return $next($request);
    }
}
