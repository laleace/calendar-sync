<?php

use App\Http\Controllers\GoogleOAuthController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Public page linked from the Google OAuth consent screen.
Route::view('/privacy', 'privacy')->name('privacy');

// The callback path must match GOOGLE_REDIRECT_URI.
Route::middleware(['auth', 'throttle:20,1'])->prefix('oauth/google')->name('oauth.google.')->group(function () {
    Route::get('connect/{account}', [GoogleOAuthController::class, 'redirect'])->name('redirect');
    Route::get('callback', [GoogleOAuthController::class, 'callback'])->name('callback');
});
