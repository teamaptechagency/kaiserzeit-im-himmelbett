<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The audit trail.
 *
 * Filterable by person, by action and by what was touched, because the
 * question is never "what happened" in general — it is "who changed the rate
 * on the Belle Étage", and a single undifferentiated list cannot answer that
 * once there are more than a few hundred rows.
 */
class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'user'    => $request->query('user', ''),
            'action'  => $request->query('action', ''),
            'subject' => $request->query('subject', ''),
        ];

        $logs = ActivityLog::query()
            ->with('user')
            ->when($filters['user'] !== '', fn ($q) => $q->where('user_id', $filters['user']))
            ->when($filters['action'] !== '', fn ($q) => $q->where('action', $filters['action']))
            ->when($filters['subject'] !== '', fn ($q) => $q->where('subject_type', $filters['subject']))
            ->latest('created_at')
            ->paginate(40)
            ->withQueryString();

        return view('admin.activity.index', [
            'logs'    => $logs,
            'filters' => $filters,
            'people'  => User::staff()->orderBy('name')->get(),
            /* Built from what is actually in the table rather than from a
               hard-coded list, so a new kind of entry is filterable the day
               it first appears. */
            'actions'  => ActivityLog::distinct()->orderBy('action')->pluck('action'),
            'subjects' => ActivityLog::whereNotNull('subject_type')->distinct()->pluck('subject_type'),
        ]);
    }
}
