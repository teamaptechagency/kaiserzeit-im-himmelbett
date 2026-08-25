<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Apartment;
use App\Models\Booking;
use App\Models\ContactMessage;
use App\Models\LoginAttempt;
use App\Models\PageView;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * What somebody running this site needs to see on opening the panel.
 *
 * Ordered by what needs a decision, not by what is easiest to count: things
 * waiting on a person first — unanswered messages, unmoderated reviews,
 * unconfirmed bookings — then the traffic, then the record of what has
 * already been done.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $days = (int) $request->query('days', 30);
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;

        $traffic = PageView::summary($days);

        return view('admin.dashboard', [
            'days'    => $days,
            'traffic' => $traffic,
            'daily'   => PageView::daily($days),
            'topPages'     => PageView::topPages($days, 8),
            'topReferrers' => PageView::topReferrers($days, 6),

            /* The queue: everything here is somebody's next task. */
            'waiting' => [
                'messages'  => ContactMessage::unhandled()->count(),
                'reviews'   => Review::pending()->count(),
                'bookings'  => Booking::where('status', 'requested')->count(),
            ],

            'counts' => [
                'apartments' => Apartment::count(),
                'published'  => Apartment::published()->count(),
                'reviews'    => Review::approved()->count(),
                'guests'     => User::guests()->count(),
                'staff'      => User::staff()->count(),
            ],

            'upcoming'     => Booking::upcoming()->with('apartment')->limit(6)->get(),
            'latestReviews' => Review::latest()->with('apartment')->limit(5)->get(),
            'activity'     => ActivityLog::with('user')->latest('created_at')->limit(8)->get(),

            /* Failed sign-ins are on the dashboard rather than buried in the
               security screen, because the first hour of somebody working
               through a password list is the hour it is cheap to stop. */
            'failedLogins' => LoginAttempt::failed()->recent(24)->count(),
        ]);
    }
}
