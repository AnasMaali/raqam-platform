<?php

use App\Http\Controllers\PostPreviewController;
use Illuminate\Support\Facades\Route;

// The back end serves no public pages: "/" and every other path not claimed by the panel or the API is a 404.

// A secret, expiring link to a draft (decision 21.8). Signed by the application; nothing is stored.
Route::get('/preview/{post}', [PostPreviewController::class, 'show'])
    ->middleware(['signed', 'throttle:per-visitor'])
    ->whereNumber('post')
    ->name('post.preview');
