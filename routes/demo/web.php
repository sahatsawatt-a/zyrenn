<?php

use App\Http\Controllers\Demo\LabsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('demo/tiptap', 'demo/TiptapDemo')->name('tiptap_demo');
    Route::inertia('demo/konva', 'demo/KonvaDemo')->name('konva_demo');
    Route::inertia('demo/table', 'demo/TableDemo')->name('table_demo');
    Route::inertia('demo/konvamermaid', 'demo/KonvaMermaidDemo')->name('konvamermaid_demo');

    // What was added last, set up to try, each with something to click
    Route::inertia('demo/labs', 'demo/Labs')->name('labs');
    Route::inertia('demo/labs/board', 'demo/LabsBoard')->name('labs.board');
    Route::post('demo/labs/circuit-board', [LabsController::class, 'board'])->name('labs.circuit-board');
});
