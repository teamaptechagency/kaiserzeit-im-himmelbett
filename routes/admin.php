<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AmenityController;
use App\Http\Controllers\Admin\ApartmentController;
use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\MessageController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TranslationController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
 * The admin panel.
 *
 * Loaded inside the web group with the `staff` middleware already applied, so
 * nothing in this file has to remember to gate itself — see routes/web.php.
 * A route added here is protected by existing here.
 */

Route::get('/', DashboardController::class);

/* ---------------------------------------------------------------- site -- */

Route::get('/bookings', [BookingController::class, 'index']);
Route::get('/bookings/{booking}', [BookingController::class, 'show']);
Route::put('/bookings/{booking}', [BookingController::class, 'update']);

Route::get('/messages', [MessageController::class, 'index']);
Route::get('/messages/{message}', [MessageController::class, 'show']);
Route::put('/messages/{message}', [MessageController::class, 'update']);
Route::delete('/messages/{message}', [MessageController::class, 'destroy']);

Route::get('/reviews', [ReviewController::class, 'index']);
Route::get('/reviews/create', [ReviewController::class, 'create']);
Route::post('/reviews', [ReviewController::class, 'store']);
Route::get('/reviews/{review}/edit', [ReviewController::class, 'edit']);
Route::put('/reviews/{review}', [ReviewController::class, 'update']);
Route::post('/reviews/{review}/moderate', [ReviewController::class, 'moderate']);
Route::post('/reviews/{review}/reply', [ReviewController::class, 'reply']);
Route::delete('/reviews/{review}', [ReviewController::class, 'destroy']);

/* ------------------------------------------------------------- content -- */

Route::get('/apartments', [ApartmentController::class, 'index']);
Route::get('/apartments/create', [ApartmentController::class, 'create']);
Route::post('/apartments', [ApartmentController::class, 'store']);
Route::post('/apartments/reorder', [ApartmentController::class, 'reorder']);
Route::get('/apartments/{apartment}/edit', [ApartmentController::class, 'edit']);
Route::put('/apartments/{apartment}', [ApartmentController::class, 'update']);
Route::delete('/apartments/{apartment}', [ApartmentController::class, 'destroy']);

Route::get('/locations', [LocationController::class, 'index']);
Route::get('/locations/create', [LocationController::class, 'create']);
Route::post('/locations', [LocationController::class, 'store']);
Route::get('/locations/{location}/edit', [LocationController::class, 'edit']);
Route::put('/locations/{location}', [LocationController::class, 'update']);
Route::delete('/locations/{location}', [LocationController::class, 'destroy']);

Route::get('/amenities', [AmenityController::class, 'index']);
Route::get('/amenities/create', [AmenityController::class, 'create']);
Route::post('/amenities', [AmenityController::class, 'store']);
Route::get('/amenities/{amenity}/edit', [AmenityController::class, 'edit']);
Route::put('/amenities/{amenity}', [AmenityController::class, 'update']);
Route::delete('/amenities/{amenity}', [AmenityController::class, 'destroy']);

Route::get('/pages', [PageController::class, 'index']);
Route::get('/pages/{page}/edit', [PageController::class, 'edit']);
Route::put('/pages/{page}', [PageController::class, 'update']);

Route::get('/translations', [TranslationController::class, 'index']);
Route::put('/translations', [TranslationController::class, 'update']);

/* ------------------------------------------------------ administration -- */

Route::get('/users', [UserController::class, 'index']);
Route::get('/users/create', [UserController::class, 'create']);
Route::post('/users', [UserController::class, 'store']);
Route::get('/users/{user}/edit', [UserController::class, 'edit']);
Route::put('/users/{user}', [UserController::class, 'update']);
Route::delete('/users/{user}', [UserController::class, 'destroy']);

Route::get('/activity', [ActivityController::class, 'index']);

Route::get('/security', [SecurityController::class, 'index']);
Route::post('/security/password', [SecurityController::class, 'password']);
Route::post('/security/2fa/start', [SecurityController::class, 'startTwoFactor']);
Route::post('/security/2fa/confirm', [SecurityController::class, 'confirmTwoFactor']);
Route::post('/security/2fa/disable', [SecurityController::class, 'disableTwoFactor']);
Route::post('/security/2fa/codes', [SecurityController::class, 'regenerateCodes']);
Route::post('/security/sessions', [SecurityController::class, 'signOutOthers']);

Route::get('/settings', [SettingController::class, 'index']);
Route::put('/settings', [SettingController::class, 'update']);
