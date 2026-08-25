<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * App configuration.
 *
 * Everything here is a value somebody running the site should be able to
 * change without a deployment. Anything that would break the app if it were
 * wrong — database credentials, the app key — deliberately stays in .env,
 * where changing it is a deliberate act rather than a form submission.
 */
class SettingController extends Controller
{
    /** field => [group, type, label, help] */
    public const FIELDS = [
        'site_name'      => ['general', 'text',  'Site name', 'Shown in the browser tab and in structured data.'],
        'tagline'        => ['general', 'trans', 'Tagline', 'The line under the logo in the footer.'],
        'default_locale' => ['general', 'locale', 'Default language', 'The one served at the bare URL, without a prefix.'],

        'contact_email'  => ['contact', 'email', 'Contact email', 'Where the contact form is delivered.'],
        'contact_phone'  => ['contact', 'text',  'Phone', ''],
        'whatsapp'       => ['contact', 'text',  'WhatsApp number', 'International format, digits only.'],

        'seo_indexable'  => ['seo', 'bool',  'Allow search engines', 'Off until the site is ready. Controls robots.txt.'],
        'business_type'  => ['seo', 'text',  'Schema.org type', 'LodgingBusiness for a holiday let.'],
        'google_site_verification' => ['seo', 'text', 'Google verification token', 'From Search Console. Rendered as a meta tag.'],

        'booking_cleaning_fee' => ['booking', 'money', 'Cleaning fee', 'Added to every booking, in euros.'],
        'booking_city_tax'     => ['booking', 'money', 'City tax per night', 'Leipzig charges this per person per night.'],
        'booking_min_nights'   => ['booking', 'number', 'Minimum nights', ''],

        /*
         * The panel's own settings.
         *
         * admin_path moves the panel off /admin. It is not a lock — anyone
         * who knows the address still meets the sign-in form — but it takes
         * the panel out of the way of the scanners that try /admin on every
         * site they find, and those are most of what fills the failed sign-in
         * log.
         */
        'admin_path'            => ['admin', 'slug', 'Panel address', 'Where this panel lives. Changing it signs nobody out, but bookmarks to the old address stop working.'],
        'admin_allow_passkeys'  => ['admin', 'bool', 'Allow passkeys', 'Fingerprint, Windows Hello and Face ID. The sign-in button appears only on a browser that has registered one.'],
        'admin_require_2fa'     => ['admin', 'bool', 'Require a second factor for staff', 'Staff without one are sent to their security screen until they set it up. A passkey counts as one.'],
        'admin_session_minutes' => ['admin', 'number', 'Sign out after (minutes idle)', 'Applies to everyone signed in. Leave at 0 to keep Laravel\'s own setting.'],

        /*
         * Guest sign-up. Switching it off does not close the first-
         * administrator bootstrap: a site left with no way in would then have
         * no way in at all.
         */
        'allow_guest_registration' => ['admin', 'bool', 'Let guests create accounts', 'The booking flow offers one. Off, the only accounts are the ones made here.'],
    ];

    /** Reserved by the site itself; the panel cannot be moved on top of one. */
    public const RESERVED_PATHS = [
        'api', 'install', 'login', 'logout', 'register', 'storage', 'photos', 'up',
        'home', 'apartments', 'apartment', 'booking', 'about', 'contact', 'profile',
        'sitemap.xml', 'robots.txt',
    ];

    public function index()
    {
        $values = Setting::all_values();

        $grouped = [];
        foreach (self::FIELDS as $key => [$group, $type, $label, $help]) {
            $grouped[$group][$key] = compact('type', 'label', 'help') + ['value' => $values[$key] ?? null];
        }

        return view('admin.settings.index', ['grouped' => $grouped]);
    }

    public function update(Request $request): RedirectResponse
    {
        $submitted = (array) $request->input('settings', []);
        $changed   = [];

        if ($problem = $this->pathProblem($submitted['admin_path'] ?? null)) {
            return back()->withErrors(['admin_path' => $problem])->withInput();
        }

        foreach (self::FIELDS as $key => [$group, $type]) {
            /* A checkbox that is off sends nothing at all, so booleans are
               read from presence rather than from the submitted array. */
            if ($type === 'bool') {
                $value = (bool) ($submitted[$key] ?? false);
            } elseif (! array_key_exists($key, $submitted)) {
                continue;
            } else {
                $value = $this->cast($type, $submitted[$key]);
            }

            $before = Setting::get($key);

            if ($before !== $value) {
                Setting::put($key, $value, $group);
                $changed[$key] = ['from' => $before, 'to' => $value];
            }
        }

        if ($changed !== []) {
            ActivityLogger::log('settings-updated', null, 'Changed ' . count($changed) . ' setting(s)', $changed);
        }

        /* Moving the panel changes a route, and a cached route table would go
           on serving the old one — leaving the panel at an address the
           settings screen says it has left. */
        if (isset($changed['admin_path'])) {
            try {
                Artisan::call('route:clear');
            } catch (Throwable $error) {
                report($error);
            }

            $where = '/' . trim((string) $changed['admin_path']['to'], '/');

            return redirect($where . '/settings')
                ->with('status', 'The panel now lives at ' . $where . '. Update your bookmark.');
        }

        return back()->with('status', $changed === [] ? 'Nothing changed.' : 'Settings saved.');
    }

    /**
     * Refuses a panel address that would collide with the site.
     *
     * Moving the panel on top of /booking would take the booking page off the
     * site, and the person who did it would be looking at the panel wondering
     * where the page went.
     */
    private function pathProblem(mixed $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim(strtolower((string) $path), '/ ');

        if ($path === '') {
            return 'The panel needs an address. "admin" is the usual one.';
        }

        if (preg_match('/^[a-z0-9][a-z0-9-]{1,38}$/', $path) !== 1) {
            return 'Use 2 to 39 characters: lower case letters, numbers and hyphens.';
        }

        if (in_array($path, self::RESERVED_PATHS, true)) {
            return 'The site already uses /' . $path . ' for something else.';
        }

        return null;
    }

    private function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            /* Money is typed in euros and kept in cents, the same as a rate. */
            'money'  => (int) round(((float) $value) * 100),
            'number' => (int) $value,
            'trans'  => array_filter((array) $value, fn ($v) => $v !== null),
            default  => is_string($value) ? trim($value) : $value,
        };
    }
}
