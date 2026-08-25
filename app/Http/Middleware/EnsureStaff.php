<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel is for staff, not for everyone with an account.
 *
 * Guests hold accounts too — the booking flow gives them one — so "signed in"
 * is not the question. A guest who finds /admin is sent back to the site
 * rather than to the sign-in form: they are already signed in, and offering
 * them the form again only suggests they signed in wrongly.
 *
 * A deactivated account is signed out here rather than merely refused, so a
 * dismissed editor's open tab stops working at the next click.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user) {
            return redirect('/login?next=' . urlencode($request->fullUrl()));
        }

        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->withErrors(['email' => 'That account has been deactivated.']);
        }

        if (! $user->isStaff()) {
            abort(403, 'This area is for site staff.');
        }

        return $next($request);
    }
}
