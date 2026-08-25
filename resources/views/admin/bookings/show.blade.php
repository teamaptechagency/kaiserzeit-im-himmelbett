@extends('admin.layout')
@section('title', 'Booking ' . $booking->reference)

@section('content')

  @if ($clashes->count())
    <div class="ad__flash ad__flash--bad">
      <p>These dates overlap {{ $clashes->count() }} other live booking(s) in the same flat:</p>
      @foreach ($clashes as $clash)
        <p>· <a href="{{ admin_url() }}/bookings/{{ $clash->id }}">{{ $clash->reference }}</a> —
          {{ $clash->check_in->format('j M') }} to {{ $clash->check_out->format('j M') }} ({{ $clash->status }})</p>
      @endforeach
      <p>Two requests for the same week are legitimate. Confirming the second is not.</p>
    </div>
  @endif

  <div class="grid grid--2">

    <div class="card">
      <h2>The stay</h2>
      <div class="tw"><table>
        <tbody>
          <tr><th>Reference</th><td class="mono">{{ $booking->reference }}</td></tr>
          <tr><th>Flat</th><td>{{ $booking->apartment?->t('name', 'de') ?? '—' }}</td></tr>
          <tr><th>Check in</th><td>{{ $booking->check_in->format('l j F Y') }}</td></tr>
          <tr><th>Check out</th><td>{{ $booking->check_out->format('l j F Y') }}</td></tr>
          <tr><th>Nights</th><td>{{ $booking->nights() }}</td></tr>
          <tr><th>Guests</th><td>{{ $booking->guests }}</td></tr>
          <tr><th>Requested</th><td>{{ $booking->created_at?->format('j M Y, H:i') }}</td></tr>
          @if ($booking->confirmed_at)
            <tr><th>Confirmed</th><td>{{ $booking->confirmed_at->format('j M Y, H:i') }}</td></tr>
          @endif
        </tbody>
      </table></div>
    </div>

    <div class="card">
      <h2>The guest</h2>
      <div class="tw"><table>
        <tbody>
          <tr><th>Name</th><td>{{ $booking->name }}</td></tr>
          <tr><th>Email</th><td>
            @if ($booking->email)<a href="mailto:{{ $booking->email }}">{{ $booking->email }}</a>@else — @endif
          </td></tr>
          <tr><th>WhatsApp</th><td>{{ $booking->whatsapp ?: '—' }}</td></tr>
          <tr><th>Account</th><td>{{ $booking->user ? $booking->user->email : 'booked without one' }}</td></tr>
          <tr><th>Language</th><td>{{ strtoupper($booking->locale) }}</td></tr>
          <tr><th>Payment</th><td>{{ $booking->payment_method === 'cash' ? 'Cash on arrival' : 'Arranged digitally' }}</td></tr>
        </tbody>
      </table></div>
    </div>

  </div>

  <div class="grid grid--2">

    <div class="card">
      <h2>Money</h2>
      @php $euro = fn (int $cents) => number_format($cents / 100, 2, ',', '.') . ' €'; @endphp
      <div class="tw"><table>
        <tbody>
          <tr><th>Rate per night</th><td class="num">{{ $euro($booking->nightly_rate) }}</td></tr>
          <tr><th>Cleaning</th><td class="num">{{ $euro($booking->cleaning_fee) }}</td></tr>
          <tr><th>City tax</th><td class="num">{{ $euro($booking->city_tax) }}</td></tr>
          <tr><th>VAT</th><td class="num">{{ $euro($booking->vat) }}</td></tr>
          <tr><th><strong>Total</strong></th><td class="num"><strong style="color:var(--gold)">{{ $booking->totalLabel() }}</strong></td></tr>
        </tbody>
      </table></div>
    </div>

    <div class="card">
      <h2>Status</h2>
      <form method="post" action="{{ admin_url() }}/bookings/{{ $booking->id }}">
        @csrf @method('put')

        <div class="field">
          <label for="status">Where this stands</label>
          <select id="status" name="status">
            @foreach (['requested' => 'Requested', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $value => $label)
              <option value="{{ $value }}" @selected($booking->status === $value)>{{ $label }}</option>
            @endforeach
          </select>
          <small>Confirming re-checks the dates are still free.</small>
        </div>

        <div class="field">
          <label for="note">Note</label>
          <textarea id="note" name="note">{{ old('note', $booking->note) }}</textarea>
          <small>Internal. The guest never sees this.</small>
        </div>

        <button class="btn btn--go" type="submit">Save</button>
        <a class="btn" href="{{ admin_url() }}/bookings">Back</a>
      </form>
    </div>

  </div>

@endsection
