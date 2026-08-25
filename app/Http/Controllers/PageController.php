<?php

namespace App\Http\Controllers;

use App\Support\Installation;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the seven design pages.
 *
 * They are sent byte for byte, never through Blade. support.js re-fetches the
 * page source and parses it itself, and the templates carry 714 {{ … }}
 * expressions that Blade would evaluate as PHP and swallow. Keeping the files
 * verbatim is also what lets the whole runtime — support.js, image-slot.js,
 * editor.js, notes.js — stay exactly as it was.
 *
 * Every page URL is one segment deep for the same reason: the runtime loads
 * responsive.css, editor.js, notes.js and assets/* by relative path, so they
 * have to resolve against the site root. A nested URL would break all four.
 *
 * no-store matches the header vercel.json set. The client edits and reloads,
 * and a cached template would show them yesterday's copy.
 */
class PageController extends Controller
{
    /** URL name => file in resources/pages. */
    public const PAGES = [
        'Home'       => 'Home.dc.html',
        'Apartments' => 'Apartments.dc.html',
        'Apartment'  => 'Apartment.dc.html',
        'Booking'    => 'Booking.dc.html',
        'About Us'   => 'About Us.dc.html',
        'Contact'    => 'Contact.dc.html',
        'Profile'    => 'Profile.dc.html',
    ];

    /** Tidier aliases for the same pages. The originals keep working. */
    public const ALIASES = [
        'home'       => 'Home',
        'apartments' => 'Apartments',
        'apartment'  => 'Apartment',
        'booking'    => 'Booking',
        'about'      => 'About Us',
        'contact'    => 'Contact',
        'profile'    => 'Profile',
    ];

    public function show(string $page): Response
    {
        $file = self::PAGES[$page] ?? null;

        if ($file === null) {
            throw new NotFoundHttpException("No page called {$page}");
        }

        return $this->send(resource_path('pages/' . $file));
    }

    public function alias(string $alias): Response
    {
        return $this->show(self::ALIASES[$alias]);
    }

    public function admin(): Response
    {
        return $this->send(resource_path('pages/admin.html'));
    }

    /**
     * An uploaded photo, served through PHP.
     *
     * Only reached when `storage:link` could not be made — where symlinks are
     * allowed the web server answers first and this never runs. The filename
     * is already pinned to a safe charset by the route, and basename is
     * applied anyway so no request can climb out of the slots folder.
     */
    public function photo(string $file): BinaryFileResponse
    {
        $path = Installation::slotsPath() . DIRECTORY_SEPARATOR . basename($file);

        if (! is_file($path)) {
            throw new NotFoundHttpException('No photo called ' . $file);
        }

        return response()->file($path, ['cache-control' => 'public, max-age=60']);
    }

    private function send(string $path): Response
    {
        if (! is_file($path)) {
            throw new NotFoundHttpException(basename($path) . ' is missing from resources/pages');
        }

        return response((string) file_get_contents($path))
            ->header('content-type', 'text/html; charset=utf-8')
            ->header('cache-control', 'no-store');
    }
}
