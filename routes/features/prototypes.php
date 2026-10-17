<?php

use App\Http\Controllers\Board\PrototypeCommentController;
use App\Http\Controllers\Board\PrototypeController;
use App\Http\Controllers\Board\PrototypePlayController;
use App\Http\Controllers\Board\PrototypeRowController;
use Illuminate\Support\Facades\Route;

// Clickable screens to show someone before anything is built: a project's
// alone for now. Each is kept as a board and drawn on with the board's page,
// but lives here: its list, making one, drawing it, its settings, and
// clicking through it. /boards/{board} sends a prototype here.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('p/{project}')->name('projects.')->group(function () {
        Route::get('prototypes', [PrototypeController::class, 'index'])->name('prototypes.index');
        Route::post('prototypes', [PrototypeController::class, 'store'])->middleware('throttle:30,1')->name('prototypes.store');
    });

    Route::get('prototypes/{board}', [PrototypeController::class, 'show'])->name('prototypes.show');
    Route::patch('prototypes/{board}', [PrototypeController::class, 'update'])->name('prototypes.update');
    Route::get('prototypes/{board}/play', [PrototypeController::class, 'play'])->name('prototypes.play');

    // What people make of one: comments pinned to its screens, and each time
    // it was played -- the screens gone through and the taps that missed
    Route::get('prototypes/{board}/comments', [PrototypeCommentController::class, 'index'])->name('prototypes.comments.index');
    Route::post('prototypes/{board}/comments', [PrototypeCommentController::class, 'store'])->middleware('throttle:60,1')->name('prototypes.comments.store');
    Route::patch('prototype-comments/{comment}', [PrototypeCommentController::class, 'update'])->name('prototype-comments.update');
    Route::post('prototype-comments/{comment}/ticket', [PrototypeCommentController::class, 'ticket'])->middleware('throttle:30,1')->name('prototype-comments.ticket');
    Route::get('prototypes/{board}/plays', [PrototypePlayController::class, 'index'])->name('prototypes.plays.index');
    Route::post('prototypes/{board}/plays', [PrototypePlayController::class, 'store'])->middleware('throttle:60,1')->name('prototypes.plays.store');
    Route::post('prototype-plays/{play}', [PrototypePlayController::class, 'append'])->middleware('throttle:240,1')->name('prototype-plays.append');

    // A project table as its database: Play saving rows to it and deleting them
    Route::post('prototypes/{board}/rows', [PrototypeRowController::class, 'store'])->middleware('throttle:120,1')->name('prototypes.rows.store');
    Route::delete('prototypes/{board}/rows', [PrototypeRowController::class, 'destroy'])->middleware('throttle:120,1')->name('prototypes.rows.destroy');
});
