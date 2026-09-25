<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::inertia('template', 'template/TemplateSample')->name('template_sample');
    Route::inertia('demo/tiptap', 'demo/TiptapDemo')->name('tiptap_demo');
});

// ---------------------------------
//      Feature
// ---------------------------------
require __DIR__.'/features/notes.php';
require __DIR__.'/features/drive.php';

// ---------------------------------
//      Setting
// ---------------------------------
require __DIR__.'/settings.php';
