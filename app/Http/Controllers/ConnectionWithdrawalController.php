<?php

namespace App\Http\Controllers;

use App\Support\Connections\Withdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What Meta sends when someone takes back what they gave the app
 * (Withdrawal): no session, no CSRF token -- the request is checked by the
 * signature it carries. One address each for Facebook, Instagram and
 * Threads alike; the secret that signed it says which app it came through.
 */
class ConnectionWithdrawalController extends Controller
{
    /**
     * Someone took the app off their account: their sign-ins end here too.
     */
    public function deauthorized(Request $request): JsonResponse
    {
        $taken = Withdrawal::of((string) $request->input('signed_request'));

        if ($taken === null) {
            return response()->json(['ok' => false], 403);
        }

        $taken['connections']->each(fn ($connection) => Withdrawal::signOut($connection, 'deauthorized'));

        return response()->json(['ok' => true]);
    }

    /**
     * Someone asked for what the app holds of them to be deleted: it is,
     * at once, and Meta is given where they can see that it was.
     */
    public function deletion(Request $request): JsonResponse
    {
        $taken = Withdrawal::of((string) $request->input('signed_request'));

        if ($taken === null) {
            return response()->json(['ok' => false], 403);
        }

        $taken['connections']->each(fn ($connection) => Withdrawal::signOut($connection, 'deletion', forget: true));
        $receipt = Withdrawal::receipt($taken['connections']->count());

        return response()->json(['url' => Withdrawal::site("data-deletion/{$receipt}"), 'confirmation_code' => $receipt]);
    }
}
