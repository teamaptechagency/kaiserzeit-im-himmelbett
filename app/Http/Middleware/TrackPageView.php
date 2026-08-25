<?php

namespace App\Http\Middleware;

use App\Models\PageView;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts visitors, without following them.
 *
 * Recorded after the response has been sent, so a slow write never shows up
 * as a slow page. Anything that is not a person reading a page — assets, the
 * API, the admin panel, bots — is skipped, because a counter that includes
 * them answers a question nobody asked.
 *
 * The stored `visitor_hash` mixes the IP, the user agent, the app key and
 * today's date. Same person, same day, same hash; tomorrow, a different one.
 * That counts unique visitors per day and leaves nothing behind that can be
 * traced back to an address afterwards — no consent banner needed for it.
 */
class TrackPageView
{
    /** Prefixes that are not somebody reading the site. */
    private const IGNORE = ['api', 'admin', 'install', 'login', 'logout', 'storage', 'up', 'sitemap.xml', 'robots.txt'];

    private const BOTS = [
        'bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'preview',
        'monitor', 'curl', 'wget', 'headless', 'lighthouse', 'pingdom',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /** Laravel calls this once the response is on its way to the browser. */
    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $this->countable($request, $response)) {
                return;
            }

            $agent = (string) $request->userAgent();
            $path  = '/' . trim($request->path(), '/');
            $hash  = hash('sha256', implode('|', [
                $request->ip(),
                $agent,
                config('app.key'),
                now()->toDateString(),
            ]));

            /* "New" means not seen earlier today. Cheap, and it is the only
               sense of new this deliberately forgetful hash can support. */
            $seenToday = PageView::where('visitor_hash', $hash)
                ->whereDate('viewed_at', now()->toDateString())
                ->exists();

            PageView::create([
                'path'           => mb_substr($path === '/' ? '/' : $path, 0, 190),
                'locale'         => app()->getLocale(),
                'visitor_hash'   => $hash,
                'referrer_host'  => $this->referrerHost($request),
                'device'         => $this->device($agent),
                'is_new_visitor' => ! $seenToday,
                'viewed_at'      => now(),
            ]);
        } catch (Throwable $error) {
            /* A counter is never worth a 500. */
            report($error);
        }
    }

    private function countable(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $request->ajax()) {
            return false;
        }

        /* Only pages. A redirect or a 404 is not a read. */
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if (! str_contains((string) $response->headers->get('content-type'), 'text/html')) {
            return false;
        }

        $first = strtok(trim($request->path(), '/'), '/') ?: '';
        if (in_array($first, self::IGNORE, true)) {
            return false;
        }

        return ! $this->isBot((string) $request->userAgent());
    }

    private function isBot(string $agent): bool
    {
        $agent = strtolower($agent);

        if ($agent === '') {
            return true;
        }

        foreach (self::BOTS as $needle) {
            if (str_contains($agent, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** The host only — a full referrer URL can carry someone's search terms. */
    private function referrerHost(Request $request): ?string
    {
        $referrer = (string) $request->headers->get('referer');

        if ($referrer === '') {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (! is_string($host) || $host === $request->getHost()) {
            return null;
        }

        return mb_substr($host, 0, 120);
    }

    private function device(string $agent): string
    {
        $agent = strtolower($agent);

        if (str_contains($agent, 'ipad') || str_contains($agent, 'tablet')) {
            return 'tablet';
        }

        if (str_contains($agent, 'mobi') || str_contains($agent, 'android') || str_contains($agent, 'iphone')) {
            return 'mobile';
        }

        return 'desktop';
    }
}
