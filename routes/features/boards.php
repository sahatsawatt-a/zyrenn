<?php

use App\Http\Controllers\BoardController;
use App\Http\Controllers\BoardFolderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('boards', BoardController::class)->except(['create', 'edit']);
    Route::resource('board-folders', BoardFolderController::class)
        ->only(['store', 'update', 'destroy'])
        ->parameters(['board-folders' => 'folder']);
});
