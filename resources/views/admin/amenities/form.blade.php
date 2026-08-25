@extends('admin.layout')
@section('title', $amenity->exists ? $amenity->t('title', 'de') : 'New amenity')

@section('content')

  <form method="post" action="{{ $amenity->exists ? admin_url("/amenities/{$amenity->id}") : admin_url('/amenities') }}">
    @csrf
    @if ($amenity->exists) @method('put') @endif

    <div class="card">
      <h2>Block</h2>

      <div class="row">
        <div class="field">
          <label for="key">Key</label>
          <input id="key" name="key" type="text" value="{{ old('key', $amenity->key) }}" required>
        </div>
        <div class="field">
          <label for="icon">Icon</label>
          <select id="icon" name="icon" required>
            @foreach (\App\Http\Controllers\Admin\AmenityController::ICONS as $icon)
              <option value="{{ $icon }}" @selected(old('icon', $amenity->icon) === $icon)>{{ $icon }}</option>
            @endforeach
          </select>
          <small>Only these are drawn by the design. Anything else renders as an empty square.</small>
        </div>
        <div class="field">
          <label for="sort">Order</label>
          <input id="sort" name="sort" type="number" min="0" value="{{ old('sort', $amenity->sort ?? 0) }}">
        </div>
      </div>

      <label class="check">
        <input type="hidden" name="is_visible" value="0">
        <input type="checkbox" name="is_visible" value="1" @checked(old('is_visible', $amenity->is_visible ?? true))>
        <span><b>Shown on the site</b></span>
      </label>
    </div>

    <div class="card">
      <h2>Wording</h2>
      @include('admin.partials.langfield', ['model' => $amenity, 'field' => 'title', 'label' => 'Title'])
      @include('admin.partials.langfield', ['model' => $amenity, 'field' => 'description', 'label' => 'Description', 'type' => 'textarea'])
    </div>

    <div class="ad__actions">
      <button class="btn btn--go" type="submit">{{ $amenity->exists ? 'Save amenity' : 'Create amenity' }}</button>
      <a class="btn" href="{{ admin_url() }}/amenities">Cancel</a>
    </div>
  </form>

  @if ($amenity->exists)
    <form method="post" action="{{ admin_url() }}/amenities/{{ $amenity->id }}" style="margin-top:20px"
          onsubmit="return confirm('Delete this amenity?')">
      @csrf @method('delete')
      <button class="btn btn--bad btn--sm" type="submit">Delete this amenity</button>
    </form>
  @endif

@endsection
