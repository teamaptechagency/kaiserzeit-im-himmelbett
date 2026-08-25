@extends('admin.layout')
@section('title', 'Amenities')

@section('actions')
  <a class="btn btn--go" href="{{ admin_url() }}/amenities/create">Add amenity</a>
@endsection

@section('content')

  <div class="card">
    @if ($amenities->count())
      <div class="tw"><table>
        <thead><tr><th>Title</th><th>Icon</th><th>Description</th><th>Shown</th><th></th></tr></thead>
        <tbody>
          @foreach ($amenities as $amenity)
            <tr>
              <td>
                <a href="{{ admin_url() }}/amenities/{{ $amenity->id }}/edit">{{ $amenity->t('title', 'de') }}</a>
                <div class="dim mono" style="font-size:12px">{{ $amenity->key }}</div>
              </td>
              <td><span class="chip">{{ $amenity->icon }}</span></td>
              <td class="dim">{{ Str::limit((string) $amenity->t('description', 'de'), 80) }}</td>
              <td>
                <span class="chip chip--{{ $amenity->is_visible ? 'ok' : 'warn' }}">
                  {{ $amenity->is_visible ? 'yes' : 'hidden' }}
                </span>
              </td>
              <td class="num"><a class="btn btn--sm" href="{{ admin_url() }}/amenities/{{ $amenity->id }}/edit">Edit</a></td>
            </tr>
          @endforeach
        </tbody>
      </table></div>
    @else
      <p class="empty">No amenities yet.</p>
    @endif
  </div>

@endsection
