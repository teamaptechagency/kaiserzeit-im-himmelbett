<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Apartment;
use App\Models\Booking;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Booking requests.
 *
 * Confirming one checks the dates are still free. Two requests for the same
 * flat and the same week can both sit in the queue quite legitimately — the
 * clash only matters at the moment somebody says yes to the second, and that
 * is where it is caught.
 */
class BookingController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'requested');

        $bookings = Booking::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['apartment', 'user'])
            ->orderByDesc('check_in')
            ->paginate(25)
            ->withQueryString();

        return view('admin.bookings.index', [
            'bookings' => $bookings,
            'status'   => $status,
            'tally'    => Booking::selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(Booking $booking)
    {
        return view('admin.bookings.show', [
            'booking'    => $booking->load(['apartment', 'user']),
            'apartments' => Apartment::orderBy('sort')->get(),
            /* Anything else in this flat that overlaps these dates. */
            'clashes'    => Booking::where('apartment_id', $booking->apartment_id)
                ->where('id', '!=', $booking->id)
                ->whereIn('status', ['requested', 'confirmed'])
                ->where('check_in', '<', $booking->check_out)
                ->where('check_out', '>', $booking->check_in)
                ->get(),
        ]);
    }

    public function update(Request $request, Booking $booking): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['requested', 'confirmed', 'cancelled', 'completed'])],
            'note'   => ['nullable', 'string', 'max:2000'],
        ]);

        if ($data['status'] === 'confirmed' && $booking->status !== 'confirmed') {
            $free = $booking->apartment?->isFreeBetween(
                $booking->check_in->toDateString(),
                $booking->check_out->toDateString(),
                $booking->id,
            );

            if ($free === false) {
                return back()->withErrors([
                    'status' => 'Those dates are already taken in this flat. Cancel the other booking first.',
                ]);
            }

            $booking->confirmed_at = now();
        }

        $booking->fill(['status' => $data['status'], 'note' => $data['note'] ?? $booking->note]);

        ActivityLogger::saved($booking, 'updated', "Booking {$booking->reference} is now {$data['status']}");

        return back()->with('status', "Booking {$data['status']}.");
    }
}
