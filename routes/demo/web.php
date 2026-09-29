<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('demo/tiptap', 'demo/TiptapDemo')->name('tiptap_demo');
    Route::inertia('demo/konva', 'demo/KonvaDemo')->name('konva_demo');
    Route::inertia('demo/table', 'demo/TableDemo')->name('table_demo');
    Route::inertia('demo/konvamermaid', 'demo/KonvaMermaidDemo')->name('konvamermaid_demo');
});
