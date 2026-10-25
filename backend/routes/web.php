<?php

use App\Http\Controllers\PostPreviewController;
use Illuminate\Support\Facades\Route;

// The back end serves no public pages: "/" and every other path not claimed by the panel or the API is a 404.

// A secret, expiring link to a draft (decision 21.8). Signed by the application; nothing is stored.
Route::get('/preview/{post}', [PostPreviewController::class, 'show'])
    ->middleware(['signed', 'throttle:per-visitor'])
    ->whereNumber('post')
    ->name('post.preview');

// Filament registers download routes for its exports and imports outside the panel. The platform uses neither (there are
// no such tables), and a visitor reaching them made the database fail (500); they are a plain 404 now.
Route::get('filament/exports/{export}/download', fn () => abort(404))->name('filament.exports.download');
Route::get('filament/imports/{import}/failed-rows/download', fn () => abort(404))->name('filament.imports.failed-rows.download');
