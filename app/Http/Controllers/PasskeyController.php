<?php

namespace App\Http\Controllers;

use App\Models\LoginAttempt;
use App\Models\Passkey;
use App\Models\Setting;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\WebAuthn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Signing in with a fingerprint, Windows Hello or Face ID.
 *
 * All three are the same protocol underneath; the browser decides which
 * sensor to put in front of the person. Nothing here is device-specific.
 *
 * The sign-in button is only drawn on a browser that has registered a
 * passkey, which is what the kz_device cookie records. That cookie is a
 * convenience and not a control — an unknown browser is refused by the
 * signature check, not by the missing cookie — but it stops the login screen
 * offering a method that could only fail.
 *
 * Note that "device" here means a browser profile. Chrome and Firefox on one
 * laptop hold separate cookies and separate passkeys; that is how the
 * platform works, not a shortcut taken here.
 */
class PasskeyController extends Controller
{
    public const DEVICE_COOKIE = 'kz_device';

    /** Two years. Long enough that a working laptop is not asked twice. */
    private const DEVICE_COOKIE_MINUTES = 60 * 24 * 365 * 2;

    /* ================================================== registering ====== */

    /** What the browser needs to create a key. Staff only, already signed in. */
    public function registerOptions(Request $request): JsonResponse
    {
        if (! $this->enabled()) {
            return response()->json(['error' => 'Passkeys are switched off for this site.'], 403);
        }

        $user = $request->user();

        /* Everything this account already has, so the authenticator refuses
           to make a second key for itself rather than silently replacing the
           first. */
        $existing = Passkey::where('user_id', $user->id)
            ->pluck('credential_id')
            ->map(fn ($id) => ['type' => 'public-key', 'id' => $id])
            ->all();

        return response()->json([
            'challenge' => WebAuthn::newChallenge('register'),
            'rp' => [
                'id'   => WebAuthn::rpId(),
                'name' => (string) (Setting::get('site_name') ?: config('app.name')),
            ],
            'user' => [
                'id'          => $this->userHandle($user),
                'name'        => $user->email,
                'displayName' => $user->name,
            ],
            /* ES256 first, RS256 as the fallback Windows Hello sometimes
               needs on older TPMs. */
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'authenticatorSelection' => [
                /* The sensor built into this machine, not a USB key: what was
                   asked for is Windows Hello, Touch ID and Face ID. */
                'authenticatorAttachment' => 'platform',
                'residentKey'             => 'preferred',
                /* Required, so a bare tap without a fingerprint or PIN is not
                   accepted as proof of anything. */
                'userVerification'        => 'required',
            ],
            'timeout'     => 120000,
            'attestation' => 'none',
            'excludeCredentials' => $existing,
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        if (! $this->enabled()) {
            return response()->json(['error' => 'Passkeys are switched off for this site.'], 403);
        }

        $data = $request->validate([
            'clientDataJSON'    => ['required', 'string'],
            'attestationObject' => ['required', 'string'],
            'label'             => ['nullable', 'string', 'max:80'],
        ]);

        $user = $request->user();

        try {
            $credential = WebAuthn::verifyRegistration(
                $data['clientDataJSON'],
                $data['attestationObject'],
                WebAuthn::takeChallenge('register'),
            );
        } catch (Throwable $error) {
            return response()->json(['error' => $error->getMessage()], 422);
        }

        if (Passkey::where('credential_id', $credential['credential_id'])->exists()) {
            return response()->json(['error' => 'This device is already registered.'], 422);
        }

        $token = $this->deviceToken($request);

        $passkey = Passkey::create([
            'user_id'       => $user->id,
            'credential_id' => $credential['credential_id'],
            'public_key'    => $credential['public_key'],
            'algorithm'     => $credential['algorithm'],
            'sign_count'    => $credential['sign_count'],
            'aaguid'        => $credential['aaguid'],
            'label'         => $data['label'] ?? '',
            'device_token'  => $token,
            'device_label'  => $this->describeBrowser($request),
            'last_used_at'  => null,
        ]);

        ActivityLogger::log('passkey-added', $passkey, $user->name . ' registered ' . $passkey->displayLabel());

        return response()->json(['ok' => true, 'label' => $passkey->displayLabel()])
            ->withCookie($this->deviceCookie($token));
    }

    public function destroy(Request $request, Passkey $passkey): RedirectResponse
    {
        /* Somebody else's key is not yours to remove, whatever your role. */
        if ($passkey->user_id !== $request->user()->id) {
            abort(403);
        }

        $label = $passkey->displayLabel();
        ActivityLogger::log('passkey-removed', $passkey, 'Removed the passkey ' . $label);
        $passkey->delete();

        return back()->with('status', 'Removed ' . $label . '.');
    }

    /* =================================================== signing in ====== */

    /**
     * What the browser needs to sign.
     *
     * Only the keys registered on this browser are offered. Somebody who
     * copies this endpoint's answer to another machine learns which
     * credential ids exist and nothing else — they cannot produce a
     * signature without the private half, which never left the device.
     */
    public function loginOptions(Request $request): JsonResponse
    {
        if (! $this->enabled()) {
            return response()->json(['error' => 'Passkeys are switched off for this site.'], 403);
        }

        $allowed = $this->devicePasskeys($request);

        if ($allowed->isEmpty()) {
            return response()->json(['error' => 'No passkey is registered on this device.'], 404);
        }

        return response()->json([
            'challenge'        => WebAuthn::newChallenge('login'),
            'rpId'             => WebAuthn::rpId(),
            'timeout'          => 120000,
            'userVerification' => 'required',
            'allowCredentials' => $allowed
                ->map(fn (Passkey $key) => ['type' => 'public-key', 'id' => $key->credential_id])
                ->values()
                ->all(),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        if (! $this->enabled()) {
            return response()->json(['error' => 'Passkeys are switched off for this site.'], 403);
        }

        $data = $request->validate([
            'credentialId'      => ['required', 'string'],
            'clientDataJSON'    => ['required', 'string'],
            'authenticatorData' => ['required', 'string'],
            'signature'         => ['required', 'string'],
        ]);

        /* Throttled per browser. A passkey cannot be brute forced, but an
           endpoint that verifies signatures without limit is still work
           somebody else can make this server do. */
        $throttle = 'passkey:' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttle, 10)) {
            return response()->json(['error' => 'Too many attempts. Wait a minute.'], 429);
        }

        /* The credential must be one registered on this browser, not merely
           one that exists. */
        $passkey = $this->devicePasskeys($request)
            ->firstWhere('credential_id', $data['credentialId']);

        if (! $passkey) {
            RateLimiter::hit($throttle, 300);

            return response()->json(['error' => 'That passkey is not registered on this device.'], 404);
        }

        try {
            $count = WebAuthn::verifyAssertion(
                ['public_key' => $passkey->public_key, 'sign_count' => $passkey->sign_count],
                $data['clientDataJSON'],
                $data['authenticatorData'],
                $data['signature'],
                WebAuthn::takeChallenge('login'),
            );
        } catch (Throwable $error) {
            RateLimiter::hit($throttle, 300);
            LoginAttempt::record($passkey->user?->email ?? 'passkey', false, 'bad-passkey');

            return response()->json(['error' => $error->getMessage()], 422);
        }

        $user = $passkey->user;

        if (! $user || ! $user->is_active) {
            return response()->json(['error' => 'That account is not active.'], 403);
        }

        RateLimiter::clear($throttle);

        $passkey->forceFill(['sign_count' => $count, 'last_used_at' => now()])->save();

        /* A passkey is both factors at once — something you have, unlocked by
           something you are. There is no second step after it, which is the
           point of asking for userVerification "required" above. */
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at'      => now(),
            'last_login_ip_hash' => hash('sha256', $request->ip() . config('app.key')),
        ])->save();

        LoginAttempt::record($user->email, true, 'passkey');
        ActivityLogger::log('login', $user, $user->name . ' signed in with ' . $passkey->displayLabel());

        return response()->json([
            'ok'       => true,
            'redirect' => $user->isStaff() ? $this->adminPath() : '/',
        ])->withCookie(
            cookie(AuthController::EDITOR_COOKIE, '1', config('session.lifetime'), '/', null, null, false),
        );
    }

    /* ====================================================== helpers ====== */

    /** Whether the login screen should draw the button at all. */
    public static function offeredTo(Request $request): bool
    {
        if (! (bool) Setting::get('admin_allow_passkeys', true) || ! WebAuthn::possible()) {
            return false;
        }

        $token = $request->cookie(self::DEVICE_COOKIE);

        return is_string($token) && $token !== ''
            && Passkey::forDevice($token)->exists();
    }

    /** The passkeys this browser registered. */
    private function devicePasskeys(Request $request)
    {
        $token = $request->cookie(self::DEVICE_COOKIE);

        if (! is_string($token) || $token === '') {
            return collect();
        }

        return Passkey::forDevice($token)->with('user')->get();
    }

    /** This browser's id, made on first use and kept for two years. */
    private function deviceToken(Request $request): string
    {
        $token = $request->cookie(self::DEVICE_COOKIE);

        return is_string($token) && $token !== '' ? $token : Str::random(48);
    }

    private function deviceCookie(string $token): \Symfony\Component\HttpFoundation\Cookie
    {
        /* httpOnly: only the server reads this, to decide whether to render
           the button. Nothing in the page needs it. */
        return cookie(self::DEVICE_COOKIE, $token, self::DEVICE_COOKIE_MINUTES, '/', null, null, true);
    }

    private function enabled(): bool
    {
        return (bool) Setting::get('admin_allow_passkeys', true) && WebAuthn::possible();
    }

    private function adminPath(): string
    {
        return '/' . trim((string) Setting::get('admin_path', 'admin'), '/');
    }

    /** The opaque handle the authenticator stores beside the key. */
    private function userHandle(User $user): string
    {
        if (! $user->webauthn_user_handle) {
            $user->forceFill(['webauthn_user_handle' => WebAuthn::b64uEncode(random_bytes(32))])->save();
        }

        return $user->webauthn_user_handle;
    }

    /** Something a person will recognise in the list of their own devices. */
    private function describeBrowser(Request $request): string
    {
        $agent = (string) $request->userAgent();

        $browser = match (true) {
            str_contains($agent, 'Edg/')     => 'Edge',
            str_contains($agent, 'OPR/')     => 'Opera',
            str_contains($agent, 'Chrome/')  => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/')  => 'Safari',
            default                          => 'Browser',
        };

        $platform = match (true) {
            str_contains($agent, 'Windows')  => 'Windows',
            str_contains($agent, 'Mac OS X') => 'Mac',
            str_contains($agent, 'iPhone')   => 'iPhone',
            str_contains($agent, 'iPad')     => 'iPad',
            str_contains($agent, 'Android')  => 'Android',
            str_contains($agent, 'Linux')    => 'Linux',
            default                          => '',
        };

        return trim($browser . ($platform ? ' on ' . $platform : ''));
    }
}
