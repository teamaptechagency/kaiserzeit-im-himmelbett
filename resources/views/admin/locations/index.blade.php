@extends('admin.layout')
@section('title', 'Locations')

@section('actions')
  <a class="btn btn--go" href="{{ admin_url() }}/locations/create">Add location</a>
@endsection

@section('content')

  <div class="card">
    <p class="card__hint">
      The address lives here, and the flats point at it — so correcting a house number is one
      edit rather than three identical ones.
    </p>

    @if ($locations->count())
      <div class="tw"><table>
        <thead><tr><th>Name</th><th>Address</th><th class="num">Flats</th><th>Live</th><th></th></tr></thead>
        <tbody>
          @foreach ($locations as $location)
            <tr>
              <td>
                <a href="{{ admin_url() }}/locations/{{ $location->id }}/edit">{{ $location->t('name', 'de') }}</a>
                <div class="dim mono" style="font-size:12px">{{ $location->key }}</div>
              </td>
              <td class="dim">{{ $location->addressLine() }}</td>
              <td class="num">{{ $location->apartments_count }}</td>
              <td>
                <span class="chip chip--{{ $location->is_published ? 'ok' : 'warn' }}">
                  {{ $location->is_published ? 'published' : 'hidden' }}
                </span>
              </td>
              <td class="num"><a class="btn btn--sm" href="{{ admin_url() }}/locations/{{ $location->id }}/edit">Edit</a></td>
            </tr>
          @endforeach
        </tbody>
      </table></div>
    @else
      <p class="empty">No locations yet.</p>
    @endif
  </div>

@endsection
