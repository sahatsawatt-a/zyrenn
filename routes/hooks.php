<?php

use App\Http\Controllers\Drive\DriveFileController;
use App\Http\Controllers\Workflow\HookController;
use Illuminate\Support\Facades\Route;

// What outside services send to start workflows: no session, no CSRF token
// (see bootstrap/app.php) -- each sender is checked by its own signature
Route::prefix('hooks')->name('hooks.')->middleware('throttle:hooks')->group(function () {
    Route::post('telegram/{connection}', [HookController::class, 'telegram'])->name('telegram');
    Route::post('line/{connection}', [HookController::class, 'line'])->name('line');
    Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'w/{token}', [HookController::class, 'webhook'])
        ->where('token', '[A-Za-z0-9]{20,64}')
        ->name('webhook');

    // One Drive file, for a service a step sends it through (LINE fetches a
    // picture rather than being sent it): only by a signed link that runs out
    Route::get('files/{file}', [DriveFileController::class, 'signed'])
        ->middleware('signed:relative')
        ->name('file');
});
