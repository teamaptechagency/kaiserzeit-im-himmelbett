<?php

return [

    /*
     * The languages the site speaks.
     *
     * Each is named in its own language, not in German or English. Somebody
     * hunting for their language in a list scans for the word they would use
     * for it — "Français", never "Französisch" and never "French". That is
     * the entire reason the list is written this way.
     *
     * The first is the default.
     *
     * All of these read left to right. Arabic, Hebrew and Persian are the
     * obvious omissions: adding one here is a single line, but the sign-in
     * screens position a dozen things by physical left and right and would
     * come out mirrored. That is a separate piece of work, not a line.
     */
    'locales' => [
        'de' => 'Deutsch',
        'en' => 'English',
        'fr' => 'Français',
        'es' => 'Español',
        'it' => 'Italiano',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'cs' => 'Čeština',
        'pt' => 'Português',
        'ru' => 'Русский',
        'tr' => 'Türkçe',
        'zh' => '中文',
    ],

    /*
     * Where an untranslated string falls back to, in order.
     *
     * English before German. A French guest looking at a half-translated
     * screen is better served by English than by the German they came to the
     * language list to get away from — and whoever runs the site reads both,
     * so nothing is lost at that end either.
     */
    'fallback_chain' => ['en', 'de'],

    /*
     * The shared key the editor used to send as x-kz-key. Signing in is how
     * anyone edits now; this is left for a script pushing content in, and is
     * empty by default so that header is not a way in at all.
     */
    'edit_key' => env('KZ_EDIT_KEY', ''),

    /* Matches MAX_BYTES in the editor. */
    'max_upload_bytes' => 12 * 1024 * 1024,

    /* content-type => file extension. */
    'image_types' => [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/webp'    => 'webp',
        'image/avif'    => 'avif',
        'image/gif'     => 'gif',
        'image/svg+xml' => 'svg',
    ],

    /* How long page_views rows are kept before being folded into the daily
       rollup. Three months is enough to answer "versus last quarter". */
    'analytics_retention_days' => 90,

];
