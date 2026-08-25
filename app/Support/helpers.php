<?php

use App\Models\Setting;
use App\Models\Translation;

if (! function_exists('kz')) {
    /**
     * One interface string, from the translations table.
     *
     * kz('auth.email') — the group and the key, split on the first dot.
     *
     * Falls back to the key itself rather than to an empty string: a missing
     * translation should look like a missing translation, not like a label
     * somebody forgot to write.
     *
     * @param string|null $locale null for the current one
     */
    function kz(string $path, ?string $locale = null, ?string $default = null): string
    {
        [$group, $key] = array_pad(explode('.', $path, 2), 2, '');

        if ($key === '') {
            return $default ?? $path;
        }

        try {
            $value = Translation::get($group, $key, $locale, '');
        } catch (Throwable $error) {
            /* No database yet — during installation. */
            $value = '';
        }

        return $value !== '' ? $value : ($default ?? $path);
    }
}

if (! function_exists('admin_url')) {
    /**
     * A URL inside the admin panel, wherever the panel currently lives.
     *
     * The panel's address is a setting, so nothing may hard-code /admin — a
     * link that did would break the moment somebody moved it, and would do so
     * silently, as a 404 in a menu.
     *
     * Resolved once per request. The settings themselves are cached, so this
     * costs a static read after the first call; the static also means a link
     * rendered at the top of a page and one at the bottom cannot disagree.
     */
    function admin_url(string $path = ''): string
    {
        static $base = null;

        if ($base === null) {
            try {
                $base = '/' . trim((string) (Setting::get('admin_path') ?: 'admin'), '/');
            } catch (Throwable $error) {
                /* Before the database exists — during installation — the
                   panel is wherever it started. */
                $base = '/admin';
            }
        }

        $path = trim($path, '/');

        return $path === '' ? $base : $base . '/' . $path;
    }
}
