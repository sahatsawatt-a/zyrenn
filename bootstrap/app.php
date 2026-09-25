<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // A proxy terminates TLS and forwards plain http to nginx, so without
        // reading X-Forwarded-Proto the app builds http:// URLs on an https page
        // and the browser blocks them as mixed content. `app` publishes no port,
        // so these headers can only come from `web` on the compose network.
        $middleware->trustProxies(at: '*');

        // Note autosave sends the editor's JSON, where spaces at the edges of text
        // nodes are content (e.g. "Energy is " before a formula or bold word).
        // Runs before routing, so match on method + path rather than route name.
        $middleware->trimStrings(except: [
            fn (Request $request) => $request->isMethod('PATCH') && $request->is('notes/*'),
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
