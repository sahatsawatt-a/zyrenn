<?php

use App\Http\Controllers\Note\NoteController;
use App\Http\Controllers\Note\NoteFolderController;
use App\Http\Controllers\Note\NotePdfController;
use App\Http\Controllers\Note\NoteVersionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('notes', NoteController::class)->except(['create', 'edit']);
    // Each one holds a browser on the server for a few seconds
    Route::get('notes/{note}/pdf', [NotePdfController::class, 'show'])
        ->middleware('throttle:10,1')
        ->name('notes.pdf');
    Route::scopeBindings()->group(function () {
        Route::resource('notes.versions', NoteVersionController::class)
            ->only(['index', 'store', 'update', 'destroy']);
        Route::post('notes/{note}/versions/{version}/restore', [NoteVersionController::class, 'restore'])
            ->name('notes.versions.restore');
    });
    Route::resource('note-folders', NoteFolderController::class)
        ->only(['store', 'update', 'destroy'])
        ->parameters(['note-folders' => 'folder']);
});
