<?php

use App\Http\Controllers\Note\NoteController;
use App\Http\Controllers\Note\NoteFolderController;
use App\Http\Controllers\Note\NotePdfController;
use App\Http\Controllers\Note\NoteValueController;
use App\Http\Controllers\Note\NoteVersionController;
use App\Http\Controllers\Note\PageLinkController;
use App\Http\Controllers\Note\PlotController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::owned(function () {
        Route::resource('notes', NoteController::class)->only(['index', 'store']);
        Route::resource('note-folders', NoteFolderController::class)->only(['store']);
    });

    Route::resource('notes', NoteController::class)->only(['show', 'update', 'destroy']);
    // What its live values, {{ … }}, come to now
    Route::post('notes/{note}/values', NoteValueController::class)->name('notes.values');
    // Its links to other pages, [[ … ]]: what there is to link to, what each
    // points at now, and the notes that link here
    Route::get('notes/{note}/page-links/pick', [PageLinkController::class, 'pick'])->name('notes.page-links.pick');
    Route::post('notes/{note}/page-links', [PageLinkController::class, 'show'])->name('notes.page-links.show');
    Route::get('notes/{note}/linked-from', [PageLinkController::class, 'linkedFrom'])->name('notes.linked-from');
    // The same of a board, a table or a trip: the notes that link to it
    Route::get('pages/{kind}/{ref}/linked-from', [PageLinkController::class, 'linkedFromPage'])->name('pages.linked-from');
    // The columns of a table that a graph in the note draws
    Route::get('notes/{note}/plot-table', [PlotController::class, 'table'])->name('notes.plot-table');
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
        ->only(['update', 'destroy'])
        ->parameters(['note-folders' => 'folder']);
});
