<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::inertia('template', 'template/TemplateSample')->name('template_sample');
});

// ---------------------------------
//      Feature
// ---------------------------------
require __DIR__.'/features/notes.php';
require __DIR__.'/features/drive.php';
require __DIR__.'/features/boards.php';
require __DIR__.'/features/tables.php';
require __DIR__.'/features/projects.php';

// ---------------------------------
//      Demo
// ---------------------------------
require __DIR__.'/demo/web.php';

// ---------------------------------
//      Setting
// ---------------------------------
require __DIR__.'/settings.php';
