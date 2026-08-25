<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Which language the server-rendered pages speak.
 *
 * A choice made once should hold. Four sources, most deliberate first:
 *
 *   1. ?lang=fr             they just picked it from the list
 *   2. the kz_lang cookie   what they picked here before — a year long
 *   3. the account          what they picked on any other device
 *   4. Accept-Language      what their browser asks for
 *
 * and German if none of those says anything.
 *
 * Picking from the list writes both the cookie and, for anybody signed in,
 * the account — so it survives a new browser, a new phone and a cleared
 * cookie jar. Nobody should have to choose their language twice.
 *
 * Three matters more than it looks even before anyone has chosen: somebody
 * arriving from a link with a French browser should not have to find a
 * language list to discover the site speaks French. The first screen already
 * does, and the list is then for correcting that rather than for repairing
 * it.
 *
 * Must run after EncryptCookies — see bootstrap/app.php, where putting it
 * first meant reading ciphertext and forgetting the choice on every page.
 */
class SetLocale
{
    public const COOKIE = 'kz_lang';

    /** A year. This is a preference, not a session. */
    private const REMEMBER_MINUTES = 60 * 24 * 365;

    public function handle(Request $request, Closure $next): Response
    {
        $available = array_keys(config('kaiserzeit.locales', ['de' => 'Deutsch']));
        $chosen    = null;

        /* 1. Picked from the list just now. */
        $wanted = (string) $request->query('lang', '');

        if (in_array($wanted, $available, true)) {
            $chosen = $wanted;
            $this->remember($chosen);
        }

        /* 2. Picked here before. */
        if ($chosen === null) {
            $remembered = (string) $request->cookie(self::COOKIE, '');
            if (in_array($remembered, $available, true)) {
                $chosen = $remembered;
            }
        }

        /* 3. Picked on some other device, and carried by the account. */
        if ($chosen === null && Auth::check()) {
            $saved = (string) (Auth::user()->locale ?? '');
            if (in_array($saved, $available, true)) {
                $chosen = $saved;
                /* Put it back in the cookie so the next request does not
                   have to reach for the account again. */
                $this->remember($chosen);
            }
        }

        /* 4. What the browser asks for. */
        if ($chosen === null) {
            $chosen = $request->getPreferredLanguage($available);
        }

        app()->setLocale($chosen ?: config('app.fallback_locale', 'de'));

        return $next($request);
    }

    /**
     * Keeps the choice in the cookie, and on the account where there is one.
     *
     * Written with forceFill rather than through validation: this is one
     * column, already checked against the configured list, and a language
     * preference should never be able to fail a save for a reason that
     * belongs to some other field.
     */
    private function remember(string $locale): void
    {
        Cookie::queue(Cookie::make(
            self::COOKIE, $locale, self::REMEMBER_MINUTES, '/', null, null, false,
        ));

        if (! Auth::check()) {
            return;
        }

        try {
            $user = Auth::user();

            if ($user->locale !== $locale) {
                $user->forceFill(['locale' => $locale])->save();
            }
        } catch (Throwable $error) {
            /* A language preference is never worth a failed request. */
            report($error);
        }
    }

    /** The languages on offer, each named in its own language. */
    public static function options(): array
    {
        return config('kaiserzeit.locales', ['de' => 'Deutsch']);
    }

    /**
     * The next language along, for anywhere that shows a second one.
     *
     * With a list this long "the other one" stops meaning much, so it is the
     * first fallback that is not the current language — English for a German
     * reader, German for everybody else.
     */
    public static function other(): string
    {
        $current = app()->getLocale();

        foreach ((array) config('kaiserzeit.fallback_chain', ['en', 'de']) as $locale) {
            if ($locale !== $current) {
                return $locale;
            }
        }

        foreach (array_keys(self::options()) as $locale) {
            if ($locale !== $current) {
                return $locale;
            }
        }

        return $current;
    }
}
