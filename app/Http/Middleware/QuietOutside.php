<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asked for at the app's public address (WORKFLOWS_PUBLIC_URL), an error
 * never shows its workings. A debug page -- the stack, the request, values
 * from the config -- is for whoever sits at the machine; a tunnel that
 * carries the whole app would hand it to anyone.
 */
class QuietOutside
{
    public function handle(Request $request, Closure $next): Response
    {
        $public = parse_url((string) config('services.workflows.public_url'), PHP_URL_HOST);

        if (is_string($public) && strcasecmp($request->getHost(), $public) === 0) {
            config(['app.debug' => false]);
        }

        return $next($request);
    }
}
