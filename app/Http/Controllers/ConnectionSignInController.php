<?php

namespace App\Http\Controllers;

use App\Models\Connection;
use App\Support\Audit\Audit;
use App\Support\Connections\SignIns;
use App\Support\Connections\SignsIn;
use App\Support\OwnerUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Signing a connection in where the service asks its member itself rather
 * than handing out a token to paste (SignIns lists them): there, and back with what
 * it gave -- by way of the app's public address, when it has one. Only who
 * may change the connection may sign it in, and only the browser that set
 * out is taken back.
 */
class ConnectionSignInController extends Controller
{
    private const KEPT = 'connection_sign_in';

    /** How long a sign-in set out on may take to come back. */
    private const MINUTES = 15;

    /**
     * Send the member to the service to say yes.
     */
    public function start(Request $request, Connection $connection): RedirectResponse
    {
        Gate::authorize('update', $connection);
        abort_if(($signIn = SignIns::for($connection->service)) === null, 404);

        $state = Str::random(40);
        $request->session()->put(self::KEPT, ['state' => $state, 'connection' => $connection->ref_id]);
        // Where they set out from, for the public address to pass them back to
        Cache::put(self::KEPT.":{$state}", self::from($request), now()->addMinutes(self::MINUTES));

        return redirect()->away($signIn::address($connection, $state));
    }

    /**
     * Back from the service at the app's public address, where nobody is
     * signed in: pass them on, with what the service gave, to where they set
     * out -- only ever an address a sign-in was started from, or the app's
     * own. Nothing is taken here; it is all checked there.
     */
    public function returned(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state');
        $from = preg_match('/^[A-Za-z0-9]{40}$/', $state) === 1 ? Cache::pull(self::KEPT.":{$state}") : null;

        return redirect()->away(
            rtrim(is_string($from) ? $from : (string) config('app.url'), '/')
            .route('connections.signed-in', $request->only(['code', 'state', 'error', 'error_description', 'error_reason', 'error_message']), false),
        );
    }

    /**
     * Back from the service: keep what it gave, or say why there is nothing to keep.
     */
    public function finish(Request $request): RedirectResponse
    {
        $asked = $request->session()->pull(self::KEPT);
        $connection = is_array($asked) && hash_equals((string) ($asked['state'] ?? ''), (string) $request->query('state'))
            ? Connection::where('ref_id', $asked['connection'] ?? '')->whereIn('service', SignIns::services())->first()
            : null;

        if ($connection === null) {
            return self::back(route('connections.index'), 'That sign-in was not started here, or was finished already. Start it again from the connection.');
        }

        Gate::authorize('update', $connection);

        /** @var class-string<SignsIn> $signIn */
        $signIn = SignIns::for($connection->service);
        $service = $connection->label();
        $to = OwnerUrl::to($connection->owner(), 'connections.index');
        // Instagram ends the code it sends with a mark of its own
        $code = preg_replace('/#_$/', '', (string) $request->query('code'));

        if (! is_string($code) || $code === '') {
            $said = (string) ($request->query('error_description') ?: $request->query('error_reason') ?: $request->query('error_message'));

            return self::back($to, $signIn::refused((string) $request->query('error'), $said) ?? ($said === ''
                ? "{$service} did not sign it in."
                : "{$service} did not sign it in: ".rtrim(mb_strimwidth($said, 0, 200, '…'), '.').'.'));
        }

        if (($failed = $signIn::grant($connection, $code)) !== null) {
            return self::back($to, $failed);
        }

        $connection->save();
        Audit::record($connection, 'connection.changed', $request->user(), ['fields' => ['access_token']]);

        $name = $signIn::signedIn($connection)['name'] ?? '';

        return self::back($to, $name !== '' ? "Signed in to {$service} as {$name}." : "Signed in to {$service}.", 'success');
    }

    /**
     * Where a sign-in set out from, as far as it is somewhere the app is
     * known to be opened: its own address, its public one, or this machine.
     * A Host header can say anything, and nobody is passed on to anything.
     */
    private static function from(Request $request): string
    {
        $known = array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            parse_url((string) config('services.workflows.public_url'), PHP_URL_HOST),
            'localhost',
            '127.0.0.1',
        ]);

        return in_array($request->getHost(), $known, true) ? $request->root() : rtrim((string) config('app.url'), '/');
    }

    private static function back(string $to, string $message, string $type = 'error'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return redirect($to);
    }
}
