<?php

use App\Http\Controllers\Settings\AiConnectionController;
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

    // Where the chat rooms reach their models: the user's own hosts and keys
    Route::resource('settings/ai-connections', AiConnectionController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['ai-connections' => 'connection']);
    // Tries what the form holds before it is saved; it reaches out for the user, so not without limit
    Route::post('settings/ai-connections/check', [AiConnectionController::class, 'check'])
        ->middleware('throttle:30,1')->name('ai-connections.check');
    Route::get('settings/ai-connections/{connection}/models', [AiConnectionController::class, 'models'])
        ->name('ai-connections.models');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
