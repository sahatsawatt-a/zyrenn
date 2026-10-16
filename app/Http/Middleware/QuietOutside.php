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
 * And it is the built app there, as everywhere but the dev addresses: through
 * the tunnel, hundreds of modules from the dev server load slowly enough to
 * time a page out. `npx vp build` makes the build (`npm run build` would also
 * take the dev server away from the dev addresses).
 */
class QuietOutside
{
    public function handle(Request $request, Closure $next): Response
    {
        $public = parse_url((string) config('services.workflows.public_url'), PHP_URL_HOST);
        $host = strtolower($request->getHost());

        if (is_string($public) && strcasecmp($host, $public) === 0) {
            config(['app.debug' => false]);
        }

        // The built app at the public address, and -- once SERVE_BUILT is on,
        // with a build made -- everywhere but a dev address (DEV_HOSTS): the
        // person using the app is not reloaded each time a file is saved by
        // whoever is working on it, while they -- and the browser checks,
        // and the PDF printer -- see their changes as they make them
        $built = is_string($public) && strcasecmp($host, $public) === 0
            || (config('app.serve_built') && ! in_array($host, (array) config('app.dev_hosts'), true));

        if ($built) {
            // A hot file that is never there: the built assets in public/build
            Vite::useHotFile(storage_path('framework/no-dev-server-here'));
        }

        return $next($request);
    }
}
