<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PasskeyController;
use App\Models\LoginAttempt;
use App\Models\Setting;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\TwoFactor;
use App\Support\WebAuthn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The account's own security: password, two-factor, sessions, and who has
 * been trying to get in.
 *
 * Two-factor is enrolled in two steps on purpose. Turning it on at the moment
 * the secret is generated would lock out anyone whose authenticator app failed
 * to scan it — the secret must be proved to work before it is allowed to
 * matter.
 */
class SecurityController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $device = $request->cookie(PasskeyController::DEVICE_COOKIE);

        return view('admin.security.index', [
            'user'          => $user,

            'passkeys'        => $user->passkeys()->get(),
            'passkeysAllowed' => (bool) Setting::get('admin_allow_passkeys', true),
            /* Named rather than left to the browser to fail obscurely: an IP
               address in APP_URL is the usual cause and says nothing for
               itself. */
            'passkeyProblem'  => WebAuthn::unavailableReason(),
            'thisDevice'      => $device,
            /* Already registered here means the button is offered on the
               sign-in screen for this browser, and there is nothing to add. */
            'registeredHere'  => is_string($device) && $device !== ''
                                  && $user->passkeys()->where('device_token', $device)->exists(),
            'pendingSecret' => $request->session()->get('2fa.enrol.secret'),
            'pendingUri'    => $request->session()->get('2fa.enrol.uri'),
            'recoveryCodes' => $request->session()->get('2fa.enrol.codes'),

            'attempts'      => LoginAttempt::latest('created_at')->limit(25)->get(),
            'failedToday'   => LoginAttempt::failed()->recent(24)->count(),
            /* Grouped by address rather than by account: five failures spread
               across five accounts from one source is the shape of a list
               being worked through, and counting per account hides it. */
            'suspicious'    => LoginAttempt::failed()->recent(24)
                ->selectRaw('ip_hash, COUNT(*) attempts, COUNT(DISTINCT email) accounts')
                ->groupBy('ip_hash')
                ->having('attempts', '>=', 5)
                ->orderByDesc('attempts')
                ->get(),

            'sessions'      => $this->otherSessions($request),
        ]);
    }

    /* ----------------------------------------------------------- password */

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current'  => ['required', 'string'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ], [
            'password.min' => 'Use at least 10 characters. Longer beats clever.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current'], $user->password)) {
            throw ValidationException::withMessages(['current' => 'That is not the current password.']);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        /* Everything else signed in as this account is now signed in with a
           password that no longer exists. */
        Auth::logoutOtherDevices($data['password']);

        ActivityLogger::log('password-changed', $user, $user->name . ' changed their password');

        return back()->with('status', 'Password changed. Other devices have been signed out.');
    }

    /* --------------------------------------------------------- two-factor */

    /** Step one: make a secret and show it. Nothing is enabled yet. */
    public function startTwoFactor(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactor()) {
            return back()->withErrors(['code' => 'Two-factor is already on for this account.']);
        }

        $secret = TwoFactor::secret();

        $request->session()->put('2fa.enrol.secret', $secret);
        $request->session()->put('2fa.enrol.uri', TwoFactor::uri(
            $secret,
            $user->email,
            (string) (\App\Models\Setting::get('site_name') ?: config('app.name')),
        ));
        $request->session()->put('2fa.enrol.codes', TwoFactor::recoveryCodes());

        return back();
    }

    /** Step two: prove the app is producing the right codes, then turn it on. */
    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        $secret = $request->session()->get('2fa.enrol.secret');
        $codes  = $request->session()->get('2fa.enrol.codes', []);

        if (! $secret) {
            return back()->withErrors(['code' => 'Start again — that enrolment has expired.']);
        }

        if (! TwoFactor::verify($secret, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => 'That code was not right. Check the time on the phone and try the next one.',
            ]);
        }

        $user = $request->user();
        $user->forceFill([
            'two_factor_secret'         => $secret,
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at'   => now(),
        ])->save();

        $request->session()->forget(['2fa.enrol.secret', '2fa.enrol.uri']);

        ActivityLogger::log('2fa-enabled', $user, $user->name . ' turned two-factor on');

        return back()->with('status', 'Two-factor is on. Keep the recovery codes somewhere safe.');
    }

    /**
     * Turning it off asks for the password.
     *
     * Otherwise anyone who found an unlocked screen could remove the second
     * factor with one click, which is exactly the situation it exists for.
     */
    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $data = $request->validate(['current' => ['required', 'string']]);
        $user = $request->user();

        if (! Hash::check($data['current'], $user->password)) {
            throw ValidationException::withMessages(['current' => 'That is not the current password.']);
        }

        $user->forceFill([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
        ])->save();

        ActivityLogger::log('2fa-disabled', $user, $user->name . ' turned two-factor off');

        return back()->with('status', 'Two-factor is off.');
    }

    public function regenerateCodes(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactor()) {
            return back();
        }

        $codes = TwoFactor::recoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();
        $request->session()->put('2fa.enrol.codes', $codes);

        ActivityLogger::log('2fa-codes-regenerated', $user, 'New recovery codes issued');

        return back()->with('status', 'New recovery codes. The old ones no longer work.');
    }

    /* ------------------------------------------------------------ sessions */

    /** Signs out everywhere else, this browser included where asked. */
    public function signOutOthers(Request $request): RedirectResponse
    {
        $data = $request->validate(['current' => ['required', 'string']]);

        if (! Hash::check($data['current'], $request->user()->password)) {
            throw ValidationException::withMessages(['current' => 'That is not the current password.']);
        }

        Auth::logoutOtherDevices($data['current']);

        if (config('session.driver') === 'database') {
            DB::table('sessions')
                ->where('user_id', $request->user()->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        ActivityLogger::log('sessions-cleared', $request->user(), 'Signed out other devices');

        return back()->with('status', 'Signed out everywhere else.');
    }

    /**
     * Other sessions for this account.
     *
     * Only answerable with the database session driver. This site runs on
     * file sessions so it usually returns nothing, and the screen says so
     * rather than implying there are none.
     */
    private function otherSessions(Request $request): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->limit(10)
            ->get()
            ->map(fn ($session) => [
                'current'    => $session->id === $request->session()->getId(),
                'ip'         => $session->ip_address,
                'agent'      => $session->user_agent,
                'last'       => \Carbon\Carbon::createFromTimestamp($session->last_activity),
            ])
            ->all();
    }
}
