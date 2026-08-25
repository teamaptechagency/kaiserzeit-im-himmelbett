<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Where the site insists on more than a password, this is what insists.
 *
 * Staff without a second factor are sent to their own security screen rather
 * than refused outright — the point is to get them to set one up, and a
 * locked door with no handle achieves the opposite. The security screen and
 * the sign-out route stay reachable for the same reason.
 *
 * A passkey counts. It is a second factor by construction: a key held by the
 * device, released only by a fingerprint, face or PIN.
 */
class RequireSecondFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $this->required()) {
            return $next($request);
        }

        if ($user->hasTwoFactor() || $user->passkeys()->exists()) {
            return $next($request);
        }

        /* The way out has to stay open, or this becomes a trap. */
        if ($request->is('*/security*') || $request->is('*/passkey/*') || $request->is('logout')) {
            return $next($request);
        }

        return redirect(admin_url('/security'))->with(
            'status',
            'This site requires a second factor. Set up a passkey or an authenticator app to carry on.',
        );
    }

    private function required(): bool
    {
        try {
            return (bool) Setting::get('admin_require_2fa', false);
        } catch (Throwable $error) {
            return false;
        }
    }
}
