<?php

namespace App\Http\Controllers;

use App\Models\LoginAttempt;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Signing in is what turns editing on.
 *
 * There used to be a shared key, typed into a panel behind an "Edit" button in
 * the corner of every page. That button is gone: an admin who is signed in
 * lands on an editable page, and everyone else sees a read-only preview with
 * no editing chrome at all.
 *
 * The session cookie itself stays httpOnly, so the runtime cannot read it to
 * find out whether editing should be on. A second cookie carries that one bit
 * — see EDITOR_COOKIE. It is a hint for the interface, never a credential:
 * forging it produces an editor whose every save is refused.
 *
 * Where an account has two-factor enabled, the password is only half the
 * exchange: the session is not created until the code has been checked, and
 * the half-finished login lives in the session under `2fa` in the meantime.
 */
class AuthController extends Controller
{
    /**
     * Read by image-slot.js to decide, before anything has loaded, whether to
     * build the editing chrome. Deliberately not httpOnly and deliberately
     * exempt from Laravel's cookie encryption — the browser has to be able to
     * read the value, and the value is worthless on its own.
     */
    public const EDITOR_COOKIE = 'kz_editor';

    public function show(Request $request)
    {
        if (Auth::check()) {
            return redirect($this->safeNext($request->query('next')));
        }

        /* A password already accepted, waiting on the code. */
        if ($request->session()->has('2fa.user')) {
            return view('auth.two-factor', [
                'next' => $this->safeNext($request->session()->get('2fa.next')),
            ]);
        }

        $needsAdmin = RegisterController::needsAdmin();

        return view('auth.login', [
            'next' => $this->safeNext($request->query('next')),
            /* Whether this browser has a passkey to offer. Decided on the
               server so the button is simply absent everywhere else, rather
               than appearing and then failing. */
            'passkeyOffered' => PasskeyController::offeredTo($request),
            /* A site with no administrator has to say so here, or the only
               way back into it is a URL nobody has been told about. */
            'needsAdmin'       => $needsAdmin,
            'registrationOpen' => $needsAdmin || RegisterController::guestsWelcome(),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        /*
         * One field, either kind of identifier. Asking "username or email?"
         * would only give somebody a way to answer wrongly — the server can
         * tell which it has been handed, so it does.
         *
         * `email` stays the field name and the error key: it is what the
         * throttle, the attempt log and the existing error handling already
         * key on, and renaming it would touch all three for no gain.
         */
        $data = $request->validate([
            'email'    => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Bitte Benutzername oder E-Mail eingeben. / Enter your username or email.',
        ]);

        /* Throttled by identifier and IP together, so one account being
           guessed at does not lock out everyone else behind the same office
           address. */
        $throttle = 'login:' . mb_strtolower($data['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            $seconds = RateLimiter::availableIn($throttle);
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in ' . ceil($seconds / 60) . ' minute(s).',
            ]);
        }

        $user = User::findForLogin($data['email']);

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttle, 900);
            LoginAttempt::record($data['email'], false, $user ? 'bad-password' : 'no-user');

            /* One message for both cases. Saying "no such user" would turn
               this form into a way of finding out who has an account. */
            throw ValidationException::withMessages([
                'email' => 'Diese Angaben stimmen nicht. / Those details were not recognised.',
            ]);
        }

        if (! $user->is_active) {
            RateLimiter::hit($throttle, 900);
            LoginAttempt::record($user->email, false, 'inactive');

            throw ValidationException::withMessages([
                'email' => 'Dieses Konto ist deaktiviert. / That account has been deactivated.',
            ]);
        }

        RateLimiter::clear($throttle);

        /* Second factor: hold the login in the session rather than creating
           it, so a stolen password on its own gets no further than here. */
        if ($user->hasTwoFactor()) {
            $request->session()->put('2fa.user', $user->id);
            $request->session()->put('2fa.next', $this->safeNext($request->input('next')));

            return redirect('/login');
        }

        return $this->completeLogin($request, $user, $this->safeNext($request->input('next')));
    }

    /** The code from the authenticator app, or one of the recovery codes. */
    public function twoFactor(Request $request): RedirectResponse
    {
        $id = $request->session()->get('2fa.user');
        $user = $id ? User::find($id) : null;

        if (! $user) {
            return redirect('/login');
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $code = trim($data['code']);

        $throttle = '2fa:' . $user->id . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Try again in '
                    . ceil(RateLimiter::availableIn($throttle) / 60) . ' minute(s).',
            ]);
        }

        $accepted = TwoFactor::verify((string) $user->two_factor_secret, $code)
            || $user->useRecoveryCode($code);

        if (! $accepted) {
            RateLimiter::hit($throttle, 900);
            LoginAttempt::record($user->email, false, 'bad-2fa');

            throw ValidationException::withMessages(['code' => 'That code was not right.']);
        }

        RateLimiter::clear($throttle);
        $next = $this->safeNext($request->session()->pull('2fa.next'));
        $request->session()->forget('2fa.user');

        return $this->completeLogin($request, $user, $next);
    }

    /** Abandons a sign-in that is waiting on a code. */
    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(['2fa.user', '2fa.next']);

        return redirect('/login');
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($user = Auth::user()) {
            ActivityLogger::log('logout', $user, $user->name . ' signed out');
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->withCookie($this->editorCookie(false));
    }

    /* -------------------------------------------------------------- pieces */

    private function completeLogin(Request $request, User $user, string $next): RedirectResponse
    {
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at'      => now(),
            'last_login_ip_hash' => hash('sha256', $request->ip() . config('app.key')),
        ])->save();

        LoginAttempt::record($user->email, true);
        ActivityLogger::log('login', $user, $user->name . ' signed in');

        /* Staff land in the panel; a guest goes back to where they were. */
        if ($next === '/' && $user->isStaff()) {
            $next = '/admin';
        }

        return redirect($next)->withCookie($this->editorCookie($user->isStaff()));
    }

    private function editorCookie(bool $on): \Symfony\Component\HttpFoundation\Cookie
    {
        return $on
            ? cookie(self::EDITOR_COOKIE, '1', config('session.lifetime'), '/', null, null, false)
            : Cookie::forget(self::EDITOR_COOKIE, '/');
    }

    /**
     * Only ever send someone back to a path on this site. A full URL from the
     * query string would make the login form an open redirect.
     */
    private function safeNext(mixed $next): string
    {
        $next = is_string($next) ? $next : '';

        /* A full URL of our own is fine; anything else is not. */
        if (str_starts_with($next, config('app.url'))) {
            $next = (string) parse_url($next, PHP_URL_PATH);
        }

        if ($next === '' || ! str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return '/';
        }

        return $next;
    }
}
