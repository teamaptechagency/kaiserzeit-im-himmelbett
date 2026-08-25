@extends('admin.layout')
@section('title', $location->exists ? $location->t('name', 'de') : 'New location')

@section('content')

  <form method="post" action="{{ $location->exists ? admin_url("/locations/{$location->id}") : admin_url('/locations') }}">
    @csrf
    @if ($location->exists) @method('put') @endif

    <div class="card">
      <h2>Address</h2>

      <div class="row">
        <div class="field">
          <label for="key">Key</label>
          <input id="key" name="key" type="text" value="{{ old('key', $location->key) }}" required>
          <small>Lower case, hyphens. Referred to by the design.</small>
        </div>
        <div class="field">
          <label for="street">Street and number</label>
          <input id="street" name="street" type="text" value="{{ old('street', $location->street) }}" required>
        </div>
        <div class="field">
          <label for="postcode">Postcode</label>
          <input id="postcode" name="postcode" type="text" value="{{ old('postcode', $location->postcode) }}" required>
        </div>
        <div class="field">
          <label for="city">City</label>
          <input id="city" name="city" type="text" value="{{ old('city', $location->city ?? 'Leipzig') }}" required>
        </div>
      </div>

      <div class="row">
        <div class="field">
          <label for="latitude">Latitude</label>
          <input id="latitude" name="latitude" type="text" value="{{ old('latitude', $location->latitude) }}">
          <small>Optional. Used by the map and by structured data.</small>
        </div>
        <div class="field">
          <label for="longitude">Longitude</label>
          <input id="longitude" name="longitude" type="text" value="{{ old('longitude', $location->longitude) }}">
        </div>
        <div class="field">
          <label for="sort">Order</label>
          <input id="sort" name="sort" type="number" min="0" value="{{ old('sort', $location->sort ?? 0) }}">
        </div>
      </div>

      <label class="check">
        <input type="hidden" name="is_published" value="0">
        <input type="checkbox" name="is_published" value="1" @checked(old('is_published', $location->is_published ?? true))>
        <span><b>Published</b><small>Hiding a location does not hide the flats in it.</small></span>
      </label>
    </div>

    <div class="card">
      <h2>Wording</h2>
      @include('admin.partials.langfield', ['model' => $location, 'field' => 'name', 'label' => 'Name'])
      @include('admin.partials.langfield', ['model' => $location, 'field' => 'description', 'label' => 'Description', 'type' => 'textarea'])
    </div>

    <div class="ad__actions">
      <button class="btn btn--go" type="submit">{{ $location->exists ? 'Save location' : 'Create location' }}</button>
      <a class="btn" href="{{ admin_url() }}/locations">Cancel</a>
    </div>
  </form>

  @if ($location->exists)
    <form method="post" action="{{ admin_url() }}/locations/{{ $location->id }}" style="margin-top:20px"
          onsubmit="return confirm('Delete this location?')">
      @csrf @method('delete')
      <button class="btn btn--bad btn--sm" type="submit">Delete this location</button>
    </form>
  @endif

@endsection
