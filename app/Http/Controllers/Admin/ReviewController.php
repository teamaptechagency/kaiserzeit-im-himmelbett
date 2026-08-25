<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Apartment;
use App\Models\Review;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reviews, and the moderation queue in front of them.
 *
 * Nothing a guest writes appears on the site until somebody has read it.
 * That is not squeamishness about criticism — a public form that publishes
 * straight through is a spam target within a week of going live, and a
 * holiday-let site that starts advertising cheap watches loses more than the
 * bad review would have cost it.
 *
 * Rejected reviews are kept rather than deleted. "Did you take my review
 * down?" deserves an answer.
 */
class ReviewController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'all');

        $reviews = Review::query()
            ->when(in_array($status, ['pending', 'approved', 'rejected'], true),
                fn ($q) => $q->where('status', $status))
            ->with('apartment')
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.reviews.index', [
            'reviews' => $reviews,
            'status'  => $status,
            'tally'   => [
                'pending'  => Review::where('status', 'pending')->count(),
                'approved' => Review::where('status', 'approved')->count(),
                'rejected' => Review::where('status', 'rejected')->count(),
            ],
            'average' => round((float) Review::approved()->avg('rating'), 1),
        ]);
    }

    public function create()
    {
        return view('admin.reviews.form', [
            'review'     => new Review(['rating' => 5, 'status' => 'approved', 'source' => 'site']),
            'apartments' => Apartment::orderBy('sort')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $review = new Review();
        $this->fill($review, $this->validated($request));
        $review->save();

        ActivityLogger::log('created', $review, "Added a review by {$review->author}");

        return redirect(admin_url('/reviews'))->with('status', 'Review added.');
    }

    public function edit(Review $review)
    {
        return view('admin.reviews.form', [
            'review'     => $review,
            'apartments' => Apartment::orderBy('sort')->get(),
        ]);
    }

    public function update(Request $request, Review $review): RedirectResponse
    {
        $this->fill($review, $this->validated($request, $review));

        ActivityLogger::saved($review, 'updated', "Updated the review by {$review->author}");

        return redirect(admin_url('/reviews'))->with('status', 'Review updated.');
    }

    /** Approve or reject from the list, without opening the whole form. */
    public function moderate(Request $request, Review $review): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
        ]);

        $review->status = $data['status'];
        ActivityLogger::saved($review, 'moderated', "Marked the review by {$review->author} as {$data['status']}");

        return back()->with('status', "Review {$data['status']}.");
    }

    /** The hosts' public reply, shown under the review. */
    public function reply(Request $request, Review $review): RedirectResponse
    {
        $data = $request->validate(['reply' => ['nullable', 'string', 'max:2000']]);

        $review->fill([
            'reply'      => $data['reply'] ?: null,
            'replied_at' => $data['reply'] ? now() : null,
        ]);

        ActivityLogger::saved($review, 'replied', "Replied to the review by {$review->author}");

        return back()->with('status', $data['reply'] ? 'Reply saved.' : 'Reply removed.');
    }

    public function destroy(Review $review): RedirectResponse
    {
        $label = $review->author;
        ActivityLogger::log('deleted', $review, "Deleted the review by {$label}");
        $review->delete();

        return redirect(admin_url('/reviews'))->with('status', "Deleted the review by {$label}.");
    }

    /* -------------------------------------------------------------- pieces */

    private function validated(Request $request, ?Review $review = null): array
    {
        return $request->validate([
            'author'       => ['required', 'string', 'max:120'],
            'author_note'  => ['nullable', 'string', 'max:160'],
            'email'        => ['nullable', 'email', 'max:190'],
            'title'        => ['nullable', 'string', 'max:190'],
            'apartment_id' => ['nullable', 'exists:apartments,id'],
            'rating'       => ['required', 'integer', 'min:1', 'max:5'],
            'stayed_at'    => ['nullable', 'date'],
            'source'       => ['required', Rule::in(['site', 'google', 'airbnb', 'booking'])],
            'status'       => ['required', Rule::in(['pending', 'approved', 'rejected'])],
            'is_featured'  => ['nullable', 'boolean'],
            'sort'         => ['nullable', 'integer', 'min:0'],
            'quote'        => ['required', 'array'],
            'quote.*'      => ['nullable', 'string', 'max:3000'],
            'reply'        => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function fill(Review $review, array $data): void
    {
        $review->fill([
            'author'       => $data['author'],
            'author_note'  => $data['author_note'] ?? '',
            'email'        => $data['email'] ?? null,
            'title'        => $data['title'] ?? null,
            'apartment_id' => $data['apartment_id'] ?? null,
            'rating'       => (int) $data['rating'],
            'stayed_at'    => $data['stayed_at'] ?? null,
            'source'       => $data['source'],
            'status'       => $data['status'],
            /* Featuring something that has not been approved would put it on
               the home page while it is still in the queue. */
            'is_featured'  => (bool) ($data['is_featured'] ?? false) && $data['status'] === 'approved',
            'sort'         => (int) ($data['sort'] ?? 0),
            'quote'        => array_filter($data['quote'], fn ($v) => $v !== null),
            'reply'        => $data['reply'] ?? null,
            'replied_at'   => ! empty($data['reply']) ? ($review->replied_at ?? now()) : null,
        ]);
    }
}
