<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PasskeyController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\SeoController;
use Illuminate\Support\Facades\Route;

/*
 * Every page sits one segment deep. The runtime loads responsive.css,
 * editor.js, notes.js and assets/* by relative path, so a nested URL would
 * break all four. See PageController.
 */

Route::get('/install', [InstallController::class, 'show']);
Route::post('/install', [InstallController::class, 'submit']);

/* Signing in is what turns editing on. There is no key panel on the pages any
   more, so this is the only way in. */
Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
/* The second half of a sign-in, where the account has two-factor on. */
Route::post('/login/two-factor', [AuthController::class, 'twoFactor']);
Route::get('/login/cancel', [AuthController::class, 'cancel']);

/*
 * Fingerprint, Windows Hello and Face ID.
 *
 * The sign-in pair is public because it has to be — nobody is signed in yet —
 * and safe because the signature is what proves anything. The options
 * endpoint answers 404 on a browser that has registered nothing, so it gives
 * away no list of credentials to a stranger.
 */
Route::post('/login/passkey/options', [PasskeyController::class, 'loginOptions']);
Route::post('/login/passkey', [PasskeyController::class, 'login']);

Route::middleware('staff')->group(function () {
    Route::post('/passkey/register/options', [PasskeyController::class, 'registerOptions']);
    Route::post('/passkey/register', [PasskeyController::class, 'register']);
    Route::delete('/passkey/{passkey}', [PasskeyController::class, 'destroy']);
});
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

/*
 * Making an account.
 *
 * Two jobs behind one address: while the site has no administrator, the first
 * account created here becomes one — the way back into a database restored
 * without its users. After that it is ordinary guest registration, and can be
 * switched off from Configuration.
 */
Route::get('/register', [RegisterController::class, 'show'])->name('register');
Route::post('/register', [RegisterController::class, 'store']);

Route::get('/', fn () => app(PageController::class)->show('Home'));

/* The names the templates link to. Kept so the 714 template expressions and
   every href between pages stay untouched. */
Route::get('/{page}.dc.html', [PageController::class, 'show'])
    ->where('page', '[A-Za-z ]+');

/*
 * The admin panel.
 *
 * Its address is a setting, so the prefix is read rather than written. That is
 * not a lock — whoever finds it still meets the sign-in form — but it takes
 * the panel out of the way of the scanners that try /admin on every site they
 * come across, and those are most of what fills the failed sign-in log.
 *
 * Wrapped in a try/catch because routes are registered before the installer
 * has made a database to read the setting from.
 *
 * Everything inside routes/admin.php is gated by living here, so nothing in
 * that file has to remember to gate itself.
 */
try {
    $adminPath = trim((string) (App\Models\Setting::get('admin_path') ?: 'admin'), '/') ?: 'admin';
} catch (Throwable $error) {
    $adminPath = 'admin';
}

Route::prefix($adminPath)->middleware(['staff', 'second-factor'])->group(base_path('routes/admin.php'));

/* The design's own photo overview, which predates the panel. Kept because the
   editor's toolbar links to it, moved aside so /admin is the panel. */
Route::get('/photos', [PageController::class, 'admin'])->middleware('staff');

/* Tidier aliases for the same pages. */
Route::get('/{alias}', [PageController::class, 'alias'])
    ->where('alias', implode('|', array_keys(PageController::ALIASES)));

/*
 * Uploaded photos. When `storage:link` has worked, the web server answers
 * these before Laravel is reached and this route never runs; on a host that
 * forbids symlinks it is what serves them. Either way the URL is the same.
 */
Route::get('/storage/slots/{file}', [PageController::class, 'photo'])
    ->where('file', '[A-Za-z0-9][A-Za-z0-9._-]{0,90}');

/*
 * Generated from the same tables the pages are, so adding an apartment adds
 * it to the sitemap and unpublishing a page removes it.
 */
Route::get('/sitemap.xml', [SeoController::class, 'sitemap']);
Route::get('/robots.txt', [SeoController::class, 'robots']);
