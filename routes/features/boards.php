<?php

use App\Http\Controllers\Board\BoardController;
use App\Http\Controllers\Board\BoardFolderController;
use App\Http\Controllers\Board\BoardRenderController;
use App\Http\Controllers\Board\BoardShapeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::owned(function () {
        // Before the resource routes, so "pick" is not read as a board's ref_id
        Route::get('boards/pick', [BoardController::class, 'pick'])->name('boards.pick');

        Route::resource('boards', BoardController::class)->only(['index', 'store']);
        Route::resource('board-folders', BoardFolderController::class)->only(['store']);
    });

    Route::get('boards/{board}/content', [BoardController::class, 'content'])
        ->name('boards.content');

    Route::resource('boards', BoardController::class)->only(['show', 'update', 'destroy']);

    // The presenter's own window while a board is shown as slides: the notes,
    // the next slide and the time, kept in step with the show in the browser
    Route::get('boards/{board}/presenter', [BoardController::class, 'presenter'])
        ->name('boards.presenter');

    // Each one holds a browser on the server for a few seconds. A board's
    // pictures come a frame at a time, so they are allowed more of them
    Route::get('boards/{board}/pdf', [BoardRenderController::class, 'pdf'])
        ->middleware('throttle:10,1')
        ->name('boards.pdf');
    Route::get('boards/{board}/png', [BoardRenderController::class, 'png'])
        ->middleware('throttle:40,1')
        ->name('boards.png');
    Route::resource('board-folders', BoardFolderController::class)
        ->only(['update', 'destroy'])
        ->parameters(['board-folders' => 'folder']);

    // Shapes of one's own, made on a board, for the shape library of any board
    Route::get('boards/{board}/shapes', [BoardShapeController::class, 'index'])->name('boards.shapes.index');
    Route::post('boards/{board}/shapes', [BoardShapeController::class, 'store'])->middleware('throttle:30,1')->name('boards.shapes.store');
    Route::patch('board-shapes/{boardShape}', [BoardShapeController::class, 'update'])->name('board-shapes.update');
    Route::delete('board-shapes/{boardShape}', [BoardShapeController::class, 'destroy'])->name('board-shapes.destroy');
});

// The board drawn for the renderer (App\Support\Board\BoardRender), which is signed
// in as nobody: the signed link is all the leave it has, and it runs out
Route::get('boards/{board}/render', [BoardRenderController::class, 'page'])
    ->middleware('signed:relative')
    ->name('boards.render');
