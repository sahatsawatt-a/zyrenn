<?php

use App\Http\Controllers\Table\TableColumnController;
use App\Http\Controllers\Table\TableController;
use App\Http\Controllers\Table\TableFolderController;
use App\Http\Controllers\Table\TableRowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::owned(function () {
        Route::resource('tables', TableController::class)->only(['index', 'store']);
        Route::resource('table-folders', TableFolderController::class)->only(['store']);
    });

    Route::resource('tables', TableController::class)->only(['show', 'update', 'destroy']);
    Route::resource('table-folders', TableFolderController::class)
        ->only(['update', 'destroy'])
        ->parameters(['table-folders' => 'folder']);

    // The grid saves as it goes; each of these answers with JSON
    Route::prefix('tables/{table}')->name('tables.')->group(function () {
        Route::post('rows', [TableRowController::class, 'store'])->name('rows.store');
        Route::patch('rows/{row}', [TableRowController::class, 'update'])
            ->whereNumber('row')->name('rows.update');
        Route::post('rows/{row}/duplicate', [TableRowController::class, 'duplicate'])
            ->whereNumber('row')->name('rows.duplicate');
        Route::delete('rows', [TableRowController::class, 'destroy'])->name('rows.destroy');

        // A column is named within its table, so it is found there and nowhere else
        Route::post('columns', [TableColumnController::class, 'store'])->name('columns.store');
        Route::patch('columns/{column:name}', [TableColumnController::class, 'update'])
            ->scopeBindings()->name('columns.update');
        Route::delete('columns/{column:name}', [TableColumnController::class, 'destroy'])
            ->scopeBindings()->name('columns.destroy');
    });
});
