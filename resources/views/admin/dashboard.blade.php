@extends('admin.layout')
@section('title', 'Dashboard')

@section('actions')
  <div class="tabs" style="margin:0">
    @foreach ([7, 30, 90] as $option)
      <a href="{{ admin_url() }}?days={{ $option }}" @class(['is-here' => $days === $option])>{{ $option }} days</a>
    @endforeach
  </div>
@endsection

@section('content')

  {{-- Anything here is waiting on a person. It comes first for that reason. --}}
  @if (array_sum($waiting) > 0)
    <div class="card">
      <h2>Waiting for you</h2>
      <p class="card__hint">Nothing below happens on its own.</p>
      <div class="grid grid--4">
        @foreach ([
          ['bookings', 'booking requests', admin_url('/bookings?status=requested')],
          ['messages', 'unanswered messages', admin_url('/messages?show=open')],
          ['reviews', 'reviews to moderate', admin_url('/reviews?status=pending')],
        ] as [$key, $label, $href])
          @if ($waiting[$key] > 0)
            <a class="stat stat--alert" href="{{ $href }}">
              <span class="stat__n">{{ $waiting[$key] }}</span>
              <span class="stat__l">{{ $label }}</span>
            </a>
          @endif
        @endforeach
      </div>
    </div>
  @endif

  {{-- Traffic --}}
  <div class="card">
    <h2>Visitors</h2>
    <p class="card__hint">
      Last {{ $days }} days. Bots, assets and the admin panel are not counted, and no IP address is stored.
    </p>

    <div class="grid grid--4">
      <div class="stat"><span class="stat__n">{{ number_format($traffic['visitors']) }}</span><span class="stat__l">unique visitors</span></div>
      <div class="stat"><span class="stat__n">{{ number_format($traffic['views']) }}</span><span class="stat__l">page views</span></div>
      <div class="stat"><span class="stat__n">{{ number_format($traffic['today']) }}</span><span class="stat__l">views today</span></div>
      <div class="stat"><span class="stat__n">{{ number_format($traffic['live']) }}</span><span class="stat__l">here in the last 5 min</span></div>
    </div>

    @php $peak = max(1, max($daily ?: [1])); @endphp
    <div class="spark" role="img" aria-label="Daily page views over the last {{ $days }} days">
      @foreach ($daily as $day => $views)
        <i style="height: {{ max(2, round($views / $peak * 100)) }}%" title="{{ $day }}: {{ $views }} views"></i>
      @endforeach
    </div>
  </div>

  <div class="grid grid--2">

    <div class="card">
      <h2>Most read</h2>
      @if (count($topPages))
        <div class="tw"><table>
          <thead><tr><th>Page</th><th class="num">Views</th><th class="num">Visitors</th></tr></thead>
          <tbody>
            @foreach ($topPages as $page)
              <tr>
                <td><a href="{{ $page['path'] }}" target="_blank" rel="noopener">{{ $page['path'] }}</a></td>
                <td class="num">{{ number_format($page['views']) }}</td>
                <td class="num dim">{{ number_format($page['visitors']) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table></div>
      @else
        <p class="empty">No traffic recorded yet.</p>
      @endif
    </div>

    <div class="card">
      <h2>Arriving from</h2>
      <p class="card__hint">The host only — a full referrer can carry somebody's search terms.</p>
      @if (count($topReferrers))
        <div class="tw"><table>
          <thead><tr><th>Source</th><th class="num">Views</th></tr></thead>
          <tbody>
            @foreach ($topReferrers as $referrer)
              <tr><td>{{ $referrer['referrer_host'] }}</td><td class="num">{{ number_format($referrer['views']) }}</td></tr>
            @endforeach
          </tbody>
        </table></div>
      @else
        <p class="empty">Everyone so far arrived directly.</p>
      @endif
    </div>

  </div>

  <div class="grid grid--2">

    <div class="card">
      <h2>Next arrivals</h2>
      @if ($upcoming->count())
        <div class="tw"><table>
          <thead><tr><th>Ref</th><th>Flat</th><th>Dates</th><th>Status</th></tr></thead>
          <tbody>
            @foreach ($upcoming as $booking)
              <tr>
                <td><a href="{{ admin_url() }}/bookings/{{ $booking->id }}" class="mono">{{ $booking->reference }}</a></td>
                <td>{{ $booking->apartment?->t('name') ?? '—' }}</td>
                <td class="dim">{{ $booking->check_in->format('j M') }} – {{ $booking->check_out->format('j M') }}</td>
                <td><span class="chip chip--{{ $booking->status === 'confirmed' ? 'ok' : 'warn' }}">{{ $booking->status }}</span></td>
              </tr>
            @endforeach
          </tbody>
        </table></div>
      @else
        <p class="empty">No bookings on the books.</p>
      @endif
    </div>

    <div class="card">
      <h2>Latest reviews</h2>
      @if ($latestReviews->count())
        <div class="tw"><table>
          <thead><tr><th>Guest</th><th>Rating</th><th>Status</th></tr></thead>
          <tbody>
            @foreach ($latestReviews as $review)
              <tr>
                <td><a href="{{ admin_url() }}/reviews/{{ $review->id }}/edit">{{ $review->author }}</a>
                  <div class="dim" style="font-size:13px">{{ Str::limit((string) $review->t('quote'), 60) }}</div></td>
                <td style="color:var(--gold)">{{ $review->stars() }}</td>
                <td><span class="chip chip--{{ ['pending' => 'warn', 'approved' => 'ok', 'rejected' => 'bad'][$review->status] ?? '' }}">{{ $review->status }}</span></td>
              </tr>
            @endforeach
          </tbody>
        </table></div>
      @else
        <p class="empty">No reviews yet.</p>
      @endif
    </div>

  </div>

  <div class="grid grid--2">

    <div class="card">
      <h2>The site</h2>
      <div class="grid grid--4">
        <div class="stat"><span class="stat__n">{{ $counts['published'] }}<span class="dim" style="font-size:16px">/{{ $counts['apartments'] }}</span></span><span class="stat__l">flats published</span></div>
        <div class="stat"><span class="stat__n">{{ $counts['reviews'] }}</span><span class="stat__l">reviews live</span></div>
        <div class="stat"><span class="stat__n">{{ $counts['guests'] }}</span><span class="stat__l">guest accounts</span></div>
        <div class="stat"><span class="stat__n">{{ $counts['staff'] }}</span><span class="stat__l">staff accounts</span></div>
      </div>

      @if ($failedLogins > 0)
        <p style="margin:16px 0 0">
          <span class="chip chip--{{ $failedLogins >= 10 ? 'bad' : 'warn' }}">{{ $failedLogins }} failed sign-in(s) in 24h</span>
          <a href="{{ admin_url() }}/security" style="margin-left:8px">Look</a>
        </p>
      @endif
    </div>

    <div class="card">
      <h2>Recent activity</h2>
      @if ($activity->count())
        <div class="tw"><table>
          <tbody>
            @foreach ($activity as $entry)
              <tr>
                <td>{{ $entry->user_label }}</td>
                <td>{{ $entry->description ?: $entry->action }}</td>
                <td class="num dim">{{ $entry->created_at?->diffForHumans(short: true) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table></div>
        <p style="margin:12px 0 0"><a href="{{ admin_url() }}/activity">Everything →</a></p>
      @else
        <p class="empty">Nothing logged yet.</p>
      @endif
    </div>

  </div>

@endsection
