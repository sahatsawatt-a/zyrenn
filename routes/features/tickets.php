<?php

use App\Http\Controllers\Ticket\TicketController;
use Illuminate\Support\Facades\Route;

// What a project has been asked to fix or to add. A project's alone: your own
// space has nobody asking.
Route::middleware(['auth', 'verified'])->prefix('p/{project}')->name('projects.')->group(function () {
    Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::post('tickets', [TicketController::class, 'store'])->name('tickets.store');

    Route::scopeBindings()->group(function () {
        Route::patch('tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');
        Route::delete('tickets/{ticket}', [TicketController::class, 'destroy'])->name('tickets.destroy');
        Route::post('tickets/{ticket}/chat', [TicketController::class, 'discuss'])->name('tickets.chat');
    });
});
