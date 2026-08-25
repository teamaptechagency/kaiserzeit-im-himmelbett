<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\LoginAttempt;
use App\Models\Setting;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Making an account.
 *
 * The form does two different jobs depending on the state of the site, and
 * says which it is doing:
 *
 *   no administrator exists  the first account created becomes one, and owns
 *                            the site
 *   otherwise                a guest account, for booking and for seeing your
 *                            own reservations
 *
 * The first case is a bootstrap and a way back in. The installer normally
 * makes the administrator, but a database restored from a dump that excluded
 * the users table arrives with none — which is exactly the state wpnazmul.com
 * is in — and without this there is no way to get back into a site that is
 * otherwise perfectly healthy.
 *
 * It is only ever open while there is genuinely no administrator. The moment
 * one exists, this becomes ordinary guest registration.
 */
class RegisterController extends Controller
{
    public function show(Request $request)
    {
        $needsAdmin = $this->needsAdmin();

        if (! $needsAdmin && ! $this->guestsWelcome()) {
            abort(404);
        }

        if (Auth::check()) {
            return redirect(Auth::user()->isStaff() ? admin_url() : '/');
        }

        return view('auth.register', [
            'needsAdmin' => $needsAdmin,
            'next'       => '/',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /* Registration is cheap for us and cheap for a script, so it is
           throttled per address. Five accounts an hour from one place is
           already generous for a four-flat guest house. */
        $throttle = 'register:' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many accounts from here. Try again in '
                    . ceil(RateLimiter::availableIn($throttle) / 60) . ' minute(s).',
            ]);
        }

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            /*
             * Lower case on the way in, so "Nazmul" and "nazmul" cannot
             * become two accounts. The dot, underscore and hyphen are allowed
             * because people expect them; nothing else is, because a username
             * ends up in URLs and in the greeting of every email the site
             * sends.
             */
            'username' => [
                'required', 'string', 'min:3', 'max:30',
                'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,29}$/',
                Rule::notIn(User::RESERVED_USERNAMES),
                Rule::unique('users'),
            ],
            'email'    => ['required', 'email', 'max:190', Rule::unique('users')],
            'phone'    => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\/\s.-]{5,40}$/'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ], [
            'username.regex'    => 'Benutzername: Buchstaben, Zahlen, . _ - — mindestens 3 Zeichen. / Username: letters, numbers, . _ - and at least 3 characters.',
            'username.not_in'   => 'Dieser Benutzername ist reserviert. / That username is reserved.',
            'username.unique'   => 'Dieser Benutzername ist vergeben. / That username is taken.',
            'phone.regex'       => 'Bitte eine gültige Telefonnummer. / That does not look like a phone number.',
            'password.min'      => 'Mindestens 10 Zeichen. Länger schlägt raffiniert. / At least 10 characters. Longer beats clever.',
            'password.confirmed' => 'Die Passwörter stimmen nicht überein. / The two passwords do not match.',
            'email.unique'      => 'Zu dieser Adresse gibt es schon ein Konto. / There is already an account with that address.',
        ]);

        /*
         * Whether this account becomes the administrator is decided inside a
         * transaction that locks the users table, not before it.
         *
         * Two people submitting this form in the same second on a site with
         * no administrator would otherwise both read "none" and both be made
         * one. Rare, and catastrophic in the one case it matters — the case
         * where somebody is racing you to it on purpose.
         */
        $user = DB::transaction(function () use ($data) {
            $adminExists = User::where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->lockForUpdate()
                ->exists();

            $user = new User();
            $user->fill([
                'name'      => $data['name'],
                'username'  => $data['username'],
                'email'     => $data['email'],
                'role'      => $adminExists ? User::ROLE_GUEST : User::ROLE_ADMIN,
                'phone'     => $data['phone'] ?? null,
                /* The booking flow asks for WhatsApp separately. For most
                   people it is the same number, so it starts there and can be
                   corrected — better than an empty field nobody fills in. */
                'whatsapp'  => $data['phone'] ?? null,
                'locale'    => app()->getLocale(),
                'is_active' => true,
            ]);
            $user->password = Hash::make($data['password']);
            $user->save();

            return $user;
        });

        /* Guest registration can be switched off; the administrator bootstrap
           cannot, or a site with no way in would have no way in. Checked after
           the lock so the answer matches what was actually created. */
        if ($user->role === User::ROLE_GUEST && ! $this->guestsWelcome()) {
            $user->delete();
            abort(404);
        }

        RateLimiter::hit($throttle, 3600);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at'      => now(),
            'last_login_ip_hash' => hash('sha256', $request->ip() . config('app.key')),
        ])->save();

        LoginAttempt::record($user->email, true, 'registered');
        ActivityLogger::log(
            'registered',
            $user,
            $user->isAdmin()
                ? $user->name . ' created the first administrator account'
                : $user->name . ' created a guest account',
        );

        if ($user->isStaff()) {
            return redirect(admin_url())
                ->with('status', 'This is the site\'s administrator account. Set up a passkey or an authenticator app on the security screen.')
                ->withCookie(cookie(AuthController::EDITOR_COOKIE, '1', config('session.lifetime'), '/', null, null, false));
        }

        return redirect('/')->withCookie(Cookie::forget(AuthController::EDITOR_COOKIE, '/'));
    }

    /* -------------------------------------------------------------- pieces */

    /**
     * Is the site without an administrator?
     *
     * Deactivated ones do not count: an account nobody can sign in to is not
     * a way back in.
     */
    public static function needsAdmin(): bool
    {
        try {
            return User::where('role', User::ROLE_ADMIN)->where('is_active', true)->doesntExist();
        } catch (\Throwable $error) {
            /* No database yet — the installer has not run. */
            return false;
        }
    }

    public static function guestsWelcome(): bool
    {
        try {
            return (bool) Setting::get('allow_guest_registration', true);
        } catch (\Throwable $error) {
            return false;
        }
    }
}
