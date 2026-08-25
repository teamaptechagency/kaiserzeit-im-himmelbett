<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Site-wide values: contact details, social links, analytics ids.
 *
 * Read on nearly every request, changed a few times a year, so the whole set
 * is cached as one entry and dropped whenever any row is written.
 */
class Setting extends Model
{
    protected $guarded = [];
    protected $casts = ['value' => 'array'];

    public const CACHE_KEY = 'kz.settings';

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public static function all_values(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return static::query()->pluck('value', 'key')->all();
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::all_values()[$key] ?? $default;
    }

    public static function put(string $key, mixed $value, string $group = 'general'): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
    }
}
