<?php

use App\Http\Controllers\Knowledge\KnowledgeController;
use Illuminate\Support\Facades\Route;

// What an AI may answer from: one's own at /knowledge, a project's at /p/{project}/knowledge
Route::middleware(['auth', 'verified'])->group(function () {
    Route::owned(function () {
        Route::get('knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
        Route::post('knowledge', [KnowledgeController::class, 'store'])->name('knowledge.store');
    });

    Route::patch('knowledge/{knowledge}', [KnowledgeController::class, 'update'])->name('knowledge.update');
    Route::delete('knowledge/{knowledge}', [KnowledgeController::class, 'destroy'])->name('knowledge.destroy');
    Route::post('knowledge/{knowledge}/read', [KnowledgeController::class, 'read'])->name('knowledge.read');
    Route::post('knowledge/{knowledge}/ask', [KnowledgeController::class, 'ask'])->middleware('throttle:60,1')->name('knowledge.ask');
});
