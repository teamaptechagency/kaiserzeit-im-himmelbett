@extends('admin.layout')
@section('title', 'Bookings')

@section('content')

  <div class="tabs">
    @foreach (['requested' => 'Requested', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'all' => 'All'] as $value => $label)
      <a href="{{ admin_url() }}/bookings?status={{ $value }}" @class(['is-here' => $status === $value])>
        {{ $label }}@if ($value !== 'all' && ($tally[$value] ?? 0)) ({{ $tally[$value] }})@endif
      </a>
    @endforeach
  </div>

  <div class="card">
    @if ($bookings->count())
      <div class="tw"><table>
        <thead>
          <tr><th>Ref</th><th>Guest</th><th>Flat</th><th>Dates</th><th class="num">Nights</th>
              <th class="num">Total</th><th>Status</th></tr>
        </thead>
        <tbody>
          @foreach ($bookings as $booking)
            <tr>
              <td><a class="mono" href="{{ admin_url() }}/bookings/{{ $booking->id }}">{{ $booking->reference }}</a></td>
              <td>
                {{ $booking->name }}
                <div class="dim" style="font-size:12px">{{ $booking->email ?: $booking->whatsapp }}</div>
              </td>
              <td class="dim">{{ $booking->apartment?->t('name', 'de') ?? '—' }}</td>
              <td class="dim" style="white-space:nowrap">
                {{ $booking->check_in->format('j M Y') }}<br>{{ $booking->check_out->format('j M Y') }}
              </td>
              <td class="num dim">{{ $booking->nights() }}</td>
              <td class="num">{{ $booking->totalLabel() }}</td>
              <td>
                <span class="chip chip--{{ ['requested' => 'warn', 'confirmed' => 'ok', 'cancelled' => 'bad', 'completed' => ''][$booking->status] ?? '' }}">
                  {{ $booking->status }}
                </span>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table></div>

      <div class="pager">{{ $bookings->links() }}<span class="dim">{{ $bookings->total() }} booking(s)</span></div>
    @else
      <p class="empty">Nothing here.</p>
    @endif
  </div>

@endsection
