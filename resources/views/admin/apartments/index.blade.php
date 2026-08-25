@extends('admin.layout')
@section('title', 'Flats')

@section('actions')
  <a class="btn btn--go" href="{{ admin_url() }}/apartments/create">Add flat</a>
@endsection

@section('content')

  <div class="card">
    @if ($apartments->count())
      <div class="tw"><table>
        <thead>
          <tr><th>Name</th><th>Location</th><th class="num">Rate</th><th class="num">Sleeps</th>
              <th class="num">Live bookings</th><th>Translated</th><th></th></tr>
        </thead>
        <tbody>
          @foreach ($apartments as $flat)
            <tr>
              <td>
                <a href="{{ admin_url() }}/apartments/{{ $flat->id }}/edit">{{ $flat->t('name', 'de') }}</a>
                @unless ($flat->is_published)<span class="chip chip--warn">draft</span>@endunless
                <div class="dim mono" style="font-size:12px">{{ $flat->key }}</div>
              </td>
              <td class="dim">{{ $flat->location?->t('name', 'de') ?? '—' }}</td>
              <td class="num">{{ $flat->priceLabel() }}</td>
              <td class="num dim">{{ $flat->max_guests }}</td>
              <td class="num">{{ $flat->bookings_count ?: '—' }}</td>
              <td>
                {{-- A flat described in one language and not the other shows
                     German copy to an English reader, and nobody notices until
                     a guest says so. Flagged here rather than left to chance. --}}
                @foreach (config('kaiserzeit.locales') as $code => $label)
                  @php $done = filled($flat->t('name', $code)) && filled($flat->t('description', $code)); @endphp
                  <span class="chip {{ $done ? 'chip--ok' : 'chip--warn' }}">{{ strtoupper($code) }}</span>
                @endforeach
              </td>
              <td class="num"><a class="btn btn--sm" href="{{ admin_url() }}/apartments/{{ $flat->id }}/edit">Edit</a></td>
            </tr>
          @endforeach
        </tbody>
      </table></div>
    @else
      <p class="empty">No flats yet.</p>
    @endif
  </div>

@endsection
