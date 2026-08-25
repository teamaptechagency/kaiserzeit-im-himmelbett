<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One page view.
 *
 * No IP address is kept. `visitor_hash` is a digest of the address, the user
 * agent and the day, so two visits by the same person on the same day count
 * once and the value is worthless tomorrow. That is enough for "how many
 * visitors this month" and not enough to follow anyone around, which is the
 * trade this site should be making.
 */
class PageView extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'viewed_at'      => 'datetime',
        'is_new_visitor' => 'boolean',
    ];

    /** Headline numbers for the dashboard. */
    public static function summary(int $days = 30): array
    {
        $since = now()->subDays($days)->startOfDay();

        $totals = static::where('viewed_at', '>=', $since)
            ->selectRaw('COUNT(*) views, COUNT(DISTINCT visitor_hash) visitors')
            ->first();

        return [
            'days'     => $days,
            'views'    => (int) ($totals->views ?? 0),
            'visitors' => (int) ($totals->visitors ?? 0),
            'today'    => static::whereDate('viewed_at', now()->toDateString())->count(),
            'live'     => static::where('viewed_at', '>=', now()->subMinutes(5))
                ->distinct('visitor_hash')->count('visitor_hash'),
        ];
    }

    /** Busiest pages over the window. */
    public static function topPages(int $days = 30, int $limit = 10): array
    {
        return static::where('viewed_at', '>=', now()->subDays($days)->startOfDay())
            ->selectRaw('path, COUNT(*) views, COUNT(DISTINCT visitor_hash) visitors')
            ->groupBy('path')
            ->orderByDesc('views')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /** Where people arrived from. Direct traffic has no referrer. */
    public static function topReferrers(int $days = 30, int $limit = 10): array
    {
        return static::where('viewed_at', '>=', now()->subDays($days)->startOfDay())
            ->whereNotNull('referrer_host')
            ->selectRaw('referrer_host, COUNT(*) views')
            ->groupBy('referrer_host')
            ->orderByDesc('views')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /** A view per day, for the chart. Days with no traffic come back as 0. */
    public static function daily(int $days = 30): array
    {
        $rows = static::where('viewed_at', '>=', now()->subDays($days)->startOfDay())
            ->selectRaw('DATE(viewed_at) day, COUNT(*) views, COUNT(DISTINCT visitor_hash) visitors')
            ->groupBy('day')
            ->pluck('views', 'day')
            ->all();

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $out[$day] = (int) ($rows[$day] ?? 0);
        }

        return $out;
    }

    /**
     * Folds yesterday and earlier into the daily table and drops the rows.
     *
     * Called by a scheduled command. Without it the table grows without
     * limit, and "views this year" turns into a scan of every row.
     */
    public static function rollUp(int $keepDays = 90): int
    {
        $cutoff = now()->subDays($keepDays)->startOfDay();

        $rows = static::where('viewed_at', '<', $cutoff)
            ->selectRaw('DATE(viewed_at) day, path, COUNT(*) views, COUNT(DISTINCT visitor_hash) visitors')
            ->groupBy('day', 'path')
            ->get();

        foreach ($rows as $row) {
            DB::table('page_view_daily')->updateOrInsert(
                ['day' => $row->day, 'path' => $row->path],
                ['views' => $row->views, 'visitors' => $row->visitors, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        return static::where('viewed_at', '<', $cutoff)->delete();
    }
}
