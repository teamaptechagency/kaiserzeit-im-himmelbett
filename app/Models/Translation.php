<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Interface strings — nav, form labels, the booking flow's wording.
 *
 * Kept apart from page content because nobody writes these as prose: they are
 * the design's own vocabulary, in two languages, and they are read dozens of
 * times per render. The whole set is one cached array.
 */
class Translation extends Model
{
    protected $guarded = [];
    protected $casts = ['values' => 'array'];

    public const CACHE_KEY = 'kz.translations';

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public static function map(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $out = [];
            foreach (static::all() as $row) {
                $out[$row->group][$row->key] = $row->values;
            }
            return $out;
        });
    }

    /** kz('nav.booking') reads through here. */
    public static function get(string $group, string $key, ?string $locale = null, string $default = ''): string
    {
        $values = self::map()[$group][$key] ?? null;

        if (! is_array($values)) {
            return $default;
        }

        $locale ??= app()->getLocale();

        /*
         * Down a chain rather than to one language: the locale asked for,
         * then English, then German. A Polish guest on a half-translated
         * screen gets English for the rest, which is a great deal more use
         * than the German they opened the language list to escape.
         */
        foreach (array_merge([$locale], (array) config('kaiserzeit.fallback_chain', ['en', 'de'])) as $try) {
            if (! blank($values[$try] ?? null)) {
                return (string) $values[$try];
            }
        }

        /* Anything at all beats showing the key. */
        foreach ($values as $value) {
            if (! blank($value)) {
                return (string) $value;
            }
        }

        return $default;
    }
}
