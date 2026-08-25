@extends('admin.layout')
@section('title', 'Reviews')

@section('actions')
  <a class="btn btn--go" href="{{ admin_url() }}/reviews/create">Add review</a>
@endsection

@section('content')

  <div class="tabs">
    <a href="{{ admin_url() }}/reviews?status=pending"  @class(['is-here' => $status === 'pending'])>Waiting ({{ $tally['pending'] }})</a>
    <a href="{{ admin_url() }}/reviews?status=approved" @class(['is-here' => $status === 'approved'])>Live ({{ $tally['approved'] }})</a>
    <a href="{{ admin_url() }}/reviews?status=rejected" @class(['is-here' => $status === 'rejected'])>Rejected ({{ $tally['rejected'] }})</a>
    <a href="{{ admin_url() }}/reviews?status=all"      @class(['is-here' => $status === 'all'])>All</a>
  </div>

  @if ($tally['pending'] > 0 && $status !== 'pending')
    <div class="ad__flash">
      {{ $tally['pending'] }} review(s) are waiting to be read. Nothing a guest writes reaches the site
      until somebody approves it.
    </div>
  @endif

  <div class="card">
    <p class="card__hint">Average of what is live: <strong style="color:var(--gold)">{{ $average ?: '—' }}</strong> out of 5.</p>

    @if ($reviews->count())
      <div class="tw"><table>
        <thead>
          <tr><th>Guest</th><th>Review</th><th>Flat</th><th>Rating</th><th>Source</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          @foreach ($reviews as $review)
            <tr>
              <td>
                <a href="{{ admin_url() }}/reviews/{{ $review->id }}/edit">{{ $review->author }}</a>
                @if ($review->is_featured)<span class="chip chip--gold">home page</span>@endif
                <div class="dim" style="font-size:12px">{{ $review->created_at?->format('j M Y') }}</div>
              </td>
              <td>
                {{ Str::limit((string) $review->t('quote', 'de'), 90) }}
                @if ($review->reply)
                  <div class="dim" style="font-size:13px;margin-top:4px">↳ replied: {{ Str::limit($review->reply, 60) }}</div>
                @endif
              </td>
              <td class="dim">{{ $review->apartment?->t('name', 'de') ?? '—' }}</td>
              <td style="color:var(--gold);white-space:nowrap">{{ $review->stars() }}</td>
              <td class="dim">{{ $review->source }}</td>
              <td>
                <span class="chip chip--{{ ['pending' => 'warn', 'approved' => 'ok', 'rejected' => 'bad'][$review->status] ?? '' }}">
                  {{ $review->status }}
                </span>
              </td>
              <td class="num" style="white-space:nowrap">
                {{-- Approve and reject from the list: the queue is worked
                     through in one sitting, and opening each form to do it
                     would triple the clicks. --}}
                @if ($review->status !== 'approved')
                  <form method="post" action="{{ admin_url() }}/reviews/{{ $review->id }}/moderate" class="inline">
                    @csrf <input type="hidden" name="status" value="approved">
                    <button class="btn btn--sm" type="submit">Approve</button>
                  </form>
                @endif
                @if ($review->status !== 'rejected')
                  <form method="post" action="{{ admin_url() }}/reviews/{{ $review->id }}/moderate" class="inline">
                    @csrf <input type="hidden" name="status" value="rejected">
                    <button class="btn btn--sm btn--bad" type="submit">Reject</button>
                  </form>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table></div>

      <div class="pager">{{ $reviews->links() }}<span class="dim">{{ $reviews->total() }} review(s)</span></div>
    @else
      <p class="empty">Nothing here.</p>
    @endif
  </div>

@endsection
