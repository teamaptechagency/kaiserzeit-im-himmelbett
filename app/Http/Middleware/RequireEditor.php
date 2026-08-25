<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who is allowed to write.
 *
 * Signing in is the way in. The Node build had only a shared key typed into a
 * panel on the page; that panel is gone, and an admin session now decides
 * whether the editor appears at all.
 *
 * The x-kz-key header still works, but only when KZ_EDIT_KEY has been set —
 * leave it empty at install time and the header stops being an entrance
 * altogether. It is kept for the case a browser session cannot serve: a
 * script pushing content in, or a check from the command line.
 *
 * hash_equals rather than === so the comparison does not leak the key one
 * character at a time to anyone willing to time the responses.
 */
class RequireEditor
{
    public function handle(Request $request, Closure $next): Response
    {
        /* These routes carry a session and are exempt from the CSRF token, so
           the cross-site check is made here instead.

           Every write the editor makes uses a content-type the browser will
           not send cross-origin without asking first — application/json, or
           the image's own type — and nothing here answers a preflight, so a
           forged form cannot reach this code at all. This is the belt to that
           pair of braces: an Origin from anywhere else is refused outright.
           Same-origin fetches send no Origin on some browsers, so its absence
           is not treated as suspicious. */
        $origin = $request->headers->get('Origin');

        if ($origin !== null && $origin !== '' && $origin !== $request->getSchemeAndHttpHost()) {
            return response()->json(['error' => 'cross-origin writes are not allowed'], 403)
                ->header('cache-control', 'no-store');
        }

        /* Signed in is not the question. Guests hold accounts too — the
           booking flow gives them one — and a guest who could reach this
           could rewrite the site's copy and replace its photos. Staff, and
           an account that is still active. */
        $user = Auth::user();

        if ($user && $user->is_active && $user->isStaff()) {
            return $next($request);
        }

        $expected = (string) config('kaiserzeit.edit_key');
        $offered  = (string) $request->header('x-kz-key');

        if ($expected !== '' && $offered !== '' && hash_equals($expected, $offered)) {
            return $next($request);
        }

        return response()->json(['error' => 'unauthorized'], 401)
            ->header('cache-control', 'no-store');
    }
}
