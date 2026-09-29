<?php

use App\Http\Controllers\Note\NoteController;
use App\Http\Controllers\Note\NoteFolderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::owned(function () {
        Route::resource('notes', NoteController::class)->only(['index', 'store']);
        Route::resource('note-folders', NoteFolderController::class)->only(['store']);
    });

    Route::resource('notes', NoteController::class)->only(['show', 'update', 'destroy']);
    Route::resource('note-folders', NoteFolderController::class)
        ->only(['update', 'destroy'])
        ->parameters(['note-folders' => 'folder']);
});
