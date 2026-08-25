<?php

use App\Http\Controllers\AuthController;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\RejectMalformedInput;
use App\Http\Middleware\RequireEditor;
use App\Http\Middleware\RequireSecondFactor;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackPageView;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'editor' => RequireEditor::class,
            'staff'  => EnsureStaff::class,
            'second-factor' => RequireSecondFactor::class,
        ]);

        /*
         * Appended, not prepended: SetLocale reads the kz_lang cookie, and
         * the cookie is still encrypted until EncryptCookies has run. Put
         * first, it read ciphertext, matched no language, and fell through to
         * the browser's Accept-Language every time — so the switch worked and
         * then forgot itself on the next page.
         *
         * TrackPageView counts visitors after the response has gone out, so a
         * slow write never shows up as a slow page. It ignores assets, the
         * API, the admin panel and bots.
         */
        $middleware->web(append: [
            SetLocale::class,
            TrackPageView::class,
        ]);

        /* A form submitted in the wrong character encoding used to reach a
           JSON column and die there as a 500. It comes back as a field error
           now. See RejectMalformedInput. */
        $middleware->web(prepend: [
            RejectMalformedInput::class,
        ]);

        /*
         * Signing in is what unlocks editing, so /api/* has to be able to see
         * the session — the stateless api group cannot. These three, in this
         * order, are what the web group uses to read it.
         *
         * The CSRF token is not among them, and cannot be: the editor posts
         * from pages served byte for byte from the design handoff, which have
         * nowhere to carry a token. RequireEditor makes the cross-site check
         * instead, and explains why that holds.
         */
        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
        ]);

        /*
         * The session cookie stays encrypted and httpOnly. This one carries a
         * single bit — "build the editing chrome" — and the runtime has to be
         * able to read it, so it is exempt. Forging it produces an editor
         * whose every save is refused.
         */
        $middleware->encryptCookies(except: [
            AuthController::EDITOR_COOKIE,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
