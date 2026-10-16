<?php

use App\Http\Controllers\ConnectionSignInController;
use App\Http\Controllers\ConnectionWithdrawalController;
use App\Http\Controllers\Drive\DriveFileController;
use App\Http\Controllers\Workflow\HookController;
use App\Support\Connections\SignIns;
use Illuminate\Support\Facades\Route;

// What outside services send to start workflows: no session, no CSRF token
// (see bootstrap/app.php) -- each sender is checked by its own signature.
// The one thing here that is not a sender's is a member's way back from
// signing in elsewhere, which takes nothing in
Route::prefix('hooks')->name('hooks.')->middleware('throttle:hooks')->group(function () {
    Route::post('telegram/{connection}', [HookController::class, 'telegram'])->name('telegram');
    Route::post('line/{connection}', [HookController::class, 'line'])->name('line');
    // Any platform at all, sending in the app's own shape (a "Custom channel" connection)
    Route::post('channel/{connection}', [HookController::class, 'channel'])->name('channel');
    // What happens in a GitHub repository, signed with the connection's webhook secret
    Route::post('github/{connection}', [HookController::class, 'github'])->name('github');
    // What happens on a Stripe account, signed with the endpoint's signing secret
    Route::post('stripe/{connection}', [HookController::class, 'stripe'])->name('stripe');
    // Meta asks first, to be shown the address is the app's, then sends
    Route::match(['GET', 'POST'], 'whatsapp/{connection}', [HookController::class, 'whatsapp'])->name('whatsapp');
    // A connection's ref, and never "signed-in": that is the way back from signing in, below
    Route::match(['GET', 'POST'], 'instagram/{connection}', [HookController::class, 'instagram'])->where('connection', '[A-Za-z0-9]+')->name('instagram');
    Route::match(['GET', 'POST'], 'threads/{connection}', [HookController::class, 'threads'])->where('connection', '[A-Za-z0-9]+')->name('threads');
    Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'w/{token}', [HookController::class, 'webhook'])
        ->where('token', '[A-Za-z0-9]{20,64}')
        ->name('webhook');

    // Meta, when someone takes the app off their account, or asks for what it
    // holds of them to be deleted: one address each for Facebook, Instagram and Threads
    Route::post('meta/deauthorize', [ConnectionWithdrawalController::class, 'deauthorized'])->name('meta.deauthorize');
    Route::post('meta/data-deletion', [ConnectionWithdrawalController::class, 'deletion'])->name('meta.data-deletion');

    // Back from signing a connection in on its service's own pages (SignIns),
    // at the one address the service is told of: passed on to where it set out
    Route::get('{service}/signed-in', [ConnectionSignInController::class, 'returned'])
        ->whereIn('service', SignIns::services())->name('signed-in');

    // One Drive file, for a service a step sends it through (LINE fetches a
    // picture rather than being sent it): only by a signed link that runs out
    Route::get('files/{file}', [DriveFileController::class, 'signed'])
        ->middleware('signed:relative')
        ->name('file');
});
