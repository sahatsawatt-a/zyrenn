<?php

use App\Http\Controllers\Board\BoardController;
use App\Http\Controllers\Board\BoardFolderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // Before the resource routes, so "pick" is not read as a board's ref_id
    Route::get('boards/pick', [BoardController::class, 'pick'])->name('boards.pick');
    Route::get('boards/{board}/content', [BoardController::class, 'content'])
        ->name('boards.content');

    Route::resource('boards', BoardController::class)->except(['create', 'edit']);
    Route::resource('board-folders', BoardFolderController::class)
        ->only(['store', 'update', 'destroy'])
        ->parameters(['board-folders' => 'folder']);
});
