<?php

use App\Http\Controllers\Board\BoardController;
use App\Http\Controllers\Board\BoardFolderController;
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
    Route::resource('board-folders', BoardFolderController::class)
        ->only(['update', 'destroy'])
        ->parameters(['board-folders' => 'folder']);
});
