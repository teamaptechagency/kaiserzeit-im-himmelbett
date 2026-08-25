<?php

use App\Http\Controllers\ContentController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

/*
 * The three routes the Node build exposed, at the same paths and with the
 * same request and response shapes — so support.js, image-slot.js, editor.js
 * and notes.js run against this backend unchanged.
 *
 * Reading is public: the preview has to render for anyone holding the link.
 * Writing needs an admin session; see RequireEditor.
 */

Route::get('/state', StateController::class);

Route::middleware('editor')->group(function () {
    Route::post('/content', ContentController::class);
    Route::post('/upload', UploadController::class);
});
