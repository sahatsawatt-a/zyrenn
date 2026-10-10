<?php

use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\Settings\McpController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('settings/mcp', [McpController::class, 'edit'])->name('mcp.edit');
    Route::post('settings/mcp/tokens', [McpController::class, 'store'])->name('mcp.tokens.store');
    Route::delete('settings/mcp/tokens/{token}', [McpController::class, 'destroy'])->name('mcp.tokens.destroy');

    // Where chats and workflows reach outside -- hosts, bots, keys: one's own
    // at /settings/connections, a project's at /p/{project}/settings/connections
    Route::owned(function () {
        Route::get('settings/connections', [ConnectionController::class, 'index'])->name('connections.index');
        Route::post('settings/connections', [ConnectionController::class, 'store'])->name('connections.store');
        // Tries what the form holds before it is saved; it reaches out for the user, so not without limit
        Route::post('settings/connections/check', [ConnectionController::class, 'check'])
            ->middleware('throttle:30,1')->name('connections.check');
    });
    Route::patch('settings/connections/{connection}', [ConnectionController::class, 'update'])->name('connections.update');
    Route::delete('settings/connections/{connection}', [ConnectionController::class, 'destroy'])->name('connections.destroy');
    Route::get('settings/connections/{connection}/test', [ConnectionController::class, 'test'])
        ->middleware('throttle:30,1')->name('connections.test');
    Route::get('settings/connections/{connection}/models', [ConnectionController::class, 'models'])
        ->name('connections.models');
    Route::redirect('settings/ai-connections', '/settings/connections');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
