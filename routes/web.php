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
require __DIR__.'/features/drive.php';

// ---------------------------------
//      Setting
// ---------------------------------
require __DIR__.'/settings.php';
