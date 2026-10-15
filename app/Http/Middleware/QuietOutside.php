<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asked for at the app's public address (WORKFLOWS_PUBLIC_URL), an error
 * never shows its workings. A debug page -- the stack, the request, values
 * from the config -- is for whoever sits at the machine; a tunnel that
 * carries the whole app would hand it to anyone.
 *
 * And it is the built app there, not the dev server's: hundreds of modules,
 * each through the tunnel, load slowly enough to time a page out -- and a
 * browser check of the app goes by that address. The dev server goes on
 * serving everyone at the machine (`npx vp build` makes the build; `npm run
 * build` would also take the dev server away from them).
 */
class QuietOutside
{
    public function handle(Request $request, Closure $next): Response
    {
        $public = parse_url((string) config('services.workflows.public_url'), PHP_URL_HOST);

        if (is_string($public) && strcasecmp($request->getHost(), $public) === 0) {
            config(['app.debug' => false]);
            // A hot file that is never there: the built assets in public/build
            Vite::useHotFile(storage_path('framework/no-dev-server-outside'));
        }

        return $next($request);
    }
}
