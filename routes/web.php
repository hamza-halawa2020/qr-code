<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\QueueController;

Route::get('/', [QueueController::class, 'home'])->name('home');
Route::get('/branches/{branch}', [QueueController::class, 'branch'])->name('branch.show');
Route::post('/branches/{branch}/tickets', [QueueController::class, 'book'])->block(10, 10)->middleware('throttle:10,1')->name('branch.book');
Route::middleware('throttle:120,1')->group(function () {
    Route::get('/t/{secret}', [QueueController::class, 'show'])->name('ticket.show');
    Route::get('/t/{secret}/state', [QueueController::class, 'state'])->name('ticket.state');+    Route::post('/t/{secret}/cancel', [QueueController::class, 'cancel'])->name('ticket.cancel');
    Route::post('/t/{secret}/firebase', [QueueController::class, 'firebaseTicket'])->name('ticket.firebase');
    Route::post('/t/{secret}/subscribe', [QueueController::class, 'subscribe'])->name('ticket.subscribe');
    Route::get('/display/{counter}/{secret}', [QueueController::class, 'display'])->name('display.show');
    Route::get('/display/{counter}/{secret}/state', [QueueController::class, 'displayState'])->name('display.state');
    Route::post('/display/{counter}/{secret}/firebase', [QueueController::class, 'firebaseDisplay'])->name('display.firebase');
});
