<?php

namespace App\Http\Controllers;

use App\Models\Apartment;
use App\Models\Page;
use App\Support\Installation;
use Illuminate\Http\Response;
use Throwable;

/**
 * sitemap.xml and robots.txt, generated from the same tables the pages are.
 *
 * Both used to be files somebody remembered to edit. Adding an apartment now
 * adds it to the sitemap, unpublishing a page removes it, and nobody has to
 * remember anything.
 */
class SeoController extends Controller
{
    /**
     * Every page and apartment, in both languages.
     *
     * Each URL carries xhtml:link alternates for the other locale. Without
     * them Google treats the German and English versions of a page as
     * duplicates and picks one to show, which is not a choice worth leaving
     * to it on a site whose guests arrive from both.
     */
    public function sitemap(): Response
    {
        $locales = array_keys(config('kaiserzeit.locales'));
        $base    = rtrim(config('app.url'), '/');
        $urls    = [];

        if (Installation::isComplete()) {
            foreach (Page::published()->where('noindex', false)->get() as $page) {
                $urls[] = [
                    'loc'        => $base . $page->path(),
                    'lastmod'    => $page->updated_at,
                    'changefreq' => $page->sitemap_frequency,
                    'priority'   => $page->sitemap_priority,
                    'alternates' => $this->alternates($base, $page->path(), $locales),
                ];
            }

            foreach (Apartment::published()->get() as $apartment) {
                $path = '/apartment/' . $apartment->slug;
                $urls[] = [
                    'loc'        => $base . $path,
                    'lastmod'    => $apartment->updated_at,
                    'changefreq' => 'weekly',
                    'priority'   => 0.8,
                    'alternates' => $this->alternates($base, $path, $locales),
                ];
            }
        }

        $xml = view('seo.sitemap', ['urls' => $urls])->render();

        return response($xml, 200, [
            'content-type'  => 'application/xml; charset=utf-8',
            'cache-control' => 'public, max-age=3600',
        ]);
    }

    /**
     * robots.txt.
     *
     * A site that is not set up yet, or that has been told to stay out of the
     * index, is disallowed wholesale — a half-built preview appearing in
     * search results is far more expensive to undo than to prevent.
     */
    public function robots(): Response
    {
        $base  = rtrim(config('app.url'), '/');
        $lines = ['User-agent: *'];

        if (! Installation::isComplete() || ! $this->indexable()) {
            $lines[] = 'Disallow: /';
        } else {
            foreach (['/admin', '/install', '/login', '/profile', '/api/'] as $path) {
                $lines[] = 'Disallow: ' . $path;
            }
            $lines[] = 'Allow: /';
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';
        }

        return response(implode("\n", $lines) . "\n", 200, [
            'content-type'  => 'text/plain; charset=utf-8',
            'cache-control' => 'public, max-age=3600',
        ]);
    }

    /** The site-wide switch, off until somebody turns it on. */
    private function indexable(): bool
    {
        try {
            return (bool) \App\Models\Setting::get('seo_indexable', false);
        } catch (Throwable $error) {
            return false;
        }
    }

    /** @return array<string, string> locale => URL */
    private function alternates(string $base, string $path, array $locales): array
    {
        $out = [];

        foreach ($locales as $locale) {
            /* The default locale lives at the bare path; the others carry a
               prefix. Keeping the default unprefixed means the URLs the
               client has already shared do not change. */
            $out[$locale] = $locale === config('app.fallback_locale')
                ? $base . $path
                : $base . '/' . $locale . rtrim($path, '/');
        }

        return $out;
    }
}
