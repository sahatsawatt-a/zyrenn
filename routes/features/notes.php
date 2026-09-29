<?php

use App\Http\Controllers\Note\NoteController;
use App\Http\Controllers\Note\NoteFolderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('notes', NoteController::class)->except(['create', 'edit']);
    Route::resource('note-folders', NoteFolderController::class)
        ->only(['store', 'update', 'destroy'])
        ->parameters(['note-folders' => 'folder']);
});
