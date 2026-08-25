<?php

namespace App\Support;

use App\Models\ImageSlot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Is this copy of the site set up, and if not, what exactly is missing?
 *
 * api/_store.js had storageProblem(), which existed for one reason: the SDK's
 * own error for a missing token ("No token found") named neither the variable
 * nor where to set it. The same idea applies to a database that has not been
 * connected yet, so the answer here always names the fix.
 *
 * The test is the state of the world, not a marker file. A marker would come
 * apart on a copy whose .env was edited by hand — the installer would still
 * think the site was unconfigured, and anyone who found /install could point
 * it at a database of their own.
 *
 * Probed once per request. That is one small query on a warm site, and on a
 * cold one it is the query that decides whether to show the installer at all.
 */
class Installation
{
    private static ?string $problem = null;
    private static bool $probed = false;

    public static function problem(): ?string
    {
        if (self::$probed) {
            return self::$problem;
        }
        self::$probed = true;

        if (! config('database.connections.' . config('database.default') . '.database')) {
            return self::$problem = 'No database is configured yet. Open /install to set one up.';
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            return self::$problem = 'The database refused the connection: ' . $e->getMessage()
                . ' Open /install to correct the details.';
        }

        foreach (['content_entries', 'notes', 'image_slots'] as $table) {
            if (! Schema::hasTable($table)) {
                return self::$problem = 'The database is connected but its tables are missing. '
                    . 'Open /install and run step 3.';
            }
        }

        if (! config('kaiserzeit.edit_key')) {
            return self::$problem = 'KZ_EDIT_KEY is not set, so nothing can be saved. '
                . 'Open /install to set an edit key.';
        }

        return self::$problem = null;
    }

    public static function isComplete(): bool
    {
        return self::problem() === null;
    }

    /**
     * Where the installer stops being a tool and becomes a liability.
     *
     * Once the site is set up AND a photo or an override exists, the wizard
     * locks. Setup alone is not enough: a fresh install has to stay open long
     * enough for its owner to reach step 4.
     */
    public static function isLocked(): bool
    {
        if (! self::isComplete()) {
            return false;
        }

        try {
            return DB::table('users')->count() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Storage the uploads need, created on demand. */
    public static function slotsPath(): string
    {
        $path = storage_path('app/public/slots');
        if (! is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        return $path;
    }
}
