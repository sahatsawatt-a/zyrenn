<?php

use App\Http\Controllers\Product\ProductController;
use Illuminate\Support\Facades\Route;

// A project's product, tracked: its tree, what was asked of it, what it must do
Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware('can:view,project')->prefix('p/{project}')->name('projects.product.')->group(function () {
        Route::get('product', [ProductController::class, 'index'])->name('index');
        Route::post('product/elements', [ProductController::class, 'storeElement'])->name('elements.store');
        Route::post('product/requests', [ProductController::class, 'storeRequest'])->name('requests.store');
        Route::post('product/requirements', [ProductController::class, 'storeRequirement'])->name('requirements.store');
        Route::post('tickets/{ticket}/request', [ProductController::class, 'requestFromTicket'])->name('requests.from-ticket');
    });

    Route::get('product-elements/{element}', [ProductController::class, 'showElement'])->name('product.elements.show');
    Route::patch('product-elements/{element}', [ProductController::class, 'updateElement'])->name('product.elements.update');
    Route::delete('product-elements/{element}', [ProductController::class, 'destroyElement'])->name('product.elements.destroy');
    Route::post('product-elements/{element}/links', [ProductController::class, 'link'])->name('product.elements.link');
    Route::post('product-elements/{element}/tickets', [ProductController::class, 'elementTicket'])->name('product.elements.ticket');
    Route::delete('product-links/{link}', [ProductController::class, 'unlink'])->name('product.links.destroy');
    Route::patch('product-requests/{productRequest}', [ProductController::class, 'updateRequest'])->name('product.requests.update');
    Route::patch('requirements/{requirement}', [ProductController::class, 'updateRequirement'])->name('product.requirements.update');
    Route::post('chats/{room}/requests', [ProductController::class, 'requestFromChat'])->name('chats.requests.store');
});
