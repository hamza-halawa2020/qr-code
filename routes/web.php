<?php

use App\Http\Controllers\QueueController;
use App\Http\Controllers\ExperienceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [QueueController::class, 'home'])->name('home');
Route::get('/branches/{branch}/appointments', [ExperienceController::class, 'appointments'])->name('appointments.index');
Route::post('/branches/{branch}/appointments', [ExperienceController::class, 'bookAppointment'])->block(10, 10)->middleware('throttle:appointment-booking')->name('appointments.book');
Route::middleware('throttle:queue-state')->group(function () {
    Route::get('/board/{branch}/{secret}', [ExperienceController::class, 'board'])->name('board.show');
    Route::get('/board/{branch}/{secret}/state', [ExperienceController::class, 'boardState'])->name('board.state');
    Route::post('/board/{branch}/{secret}/firebase', [ExperienceController::class, 'boardFirebase'])->name('board.firebase');
    Route::post('/t/{secret}/rating', [ExperienceController::class, 'rate'])->name('ticket.rate');
    Route::get('/appointments/{secret}', [ExperienceController::class, 'appointment'])->name('appointment.show');
    Route::post('/appointments/{secret}/check-in', [ExperienceController::class, 'checkIn'])->block(10, 10)->name('appointment.check-in');
    Route::post('/appointments/{secret}/cancel', [ExperienceController::class, 'cancelAppointment'])->name('appointment.cancel');
});
Route::get('/firebase-config.js', function () {
    return response('self.firebaseConfig = '.json_encode(config('firebase.web'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';')
        ->header('Content-Type', 'application/javascript')->header('Cache-Control', 'no-store');
});
Route::get('/branches/{branch}/qr', [QueueController::class, 'qr'])->middleware('auth')->name('branch.qr');
Route::get('/branches/{branch}', [QueueController::class, 'branch'])->name('branch.show');
Route::post('/branches/{branch}/tickets', [QueueController::class, 'book'])->block(10, 10)->middleware('throttle:queue-booking')->name('branch.book');
Route::middleware('throttle:queue-state')->group(function () {
    Route::get('/t/{secret}', [QueueController::class, 'show'])->name('ticket.show');
    Route::get('/t/{secret}/state', [QueueController::class, 'state'])->name('ticket.state');
    Route::post('/t/{secret}/cancel', [QueueController::class, 'cancel'])->name('ticket.cancel');
    Route::post('/t/{secret}/firebase', [QueueController::class, 'firebaseTicket'])->name('ticket.firebase');
    Route::post('/t/{secret}/subscribe', [QueueController::class, 'subscribe'])->name('ticket.subscribe');
    Route::post('/t/{secret}/unsubscribe', [QueueController::class, 'unsubscribe'])->name('ticket.unsubscribe');
    Route::get('/display/{counter}/{secret}', [QueueController::class, 'display'])->name('display.show');
    Route::get('/display/{counter}/{secret}/state', [QueueController::class, 'displayState'])->name('display.state');
    Route::post('/display/{counter}/{secret}/firebase', [QueueController::class, 'firebaseDisplay'])->name('display.firebase');
});
