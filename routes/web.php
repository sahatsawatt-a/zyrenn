<?php

use App\Http\Controllers\CollabController;
use App\Http\Controllers\DashboardController;
use App\Http\Middleware\VerifyCollabSecret;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Your own at /dashboard, a project's at /p/{project}/dashboard
    Route::owned(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
    });

    // The collaboration server asks, with the browser's own cookie, who may open a shared note or board
    Route::get('collab/auth', [CollabController::class, 'auth'])->name('collab.auth');
    Route::inertia('template', 'template/TemplateSample')->name('template_sample');
});

// Loading and keeping shared documents, for the collaboration server alone
// (no CSRF token: see bootstrap/app.php)
Route::prefix('internal/collab')
    ->middleware(VerifyCollabSecret::class)
    ->group(function () {
        Route::get('{document}', [CollabController::class, 'show'])->name('collab.show');
        Route::put('{document}', [CollabController::class, 'update'])->name('collab.update');
    });

// ---------------------------------
//      Feature
// ---------------------------------
require __DIR__.'/features/notes.php';
require __DIR__.'/features/drive.php';
require __DIR__.'/features/boards.php';
require __DIR__.'/features/tables.php';
require __DIR__.'/features/chats.php';
require __DIR__.'/features/projects.php';

// ---------------------------------
//      Demo
// ---------------------------------
require __DIR__.'/demo/web.php';

// ---------------------------------
//      Setting
// ---------------------------------
require __DIR__.'/settings.php';
