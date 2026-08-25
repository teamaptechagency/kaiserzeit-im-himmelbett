@extends('admin.layout')
@section('title', $apartment->exists ? $apartment->t('name', 'de') : 'New flat')

@section('content')

  <form method="post" action="{{ $apartment->exists ? admin_url("/apartments/{$apartment->id}") : admin_url('/apartments') }}">
    @csrf
    @if ($apartment->exists) @method('put') @endif

    <div class="card">
      <h2>Details</h2>

      <div class="row">
        <div class="field">
          <label for="key">Key</label>
          <input id="key" name="key" type="text" value="{{ old('key', $apartment->key) }}" required>
          <small>Used in URLs and referred to by the design. Lower case, hyphens.</small>
        </div>
        <div class="field">
          <label for="slug">Slug</label>
          <input id="slug" name="slug" type="text" value="{{ old('slug', $apartment->slug) }}">
          <small>Left empty, it follows the key.</small>
        </div>
        <div class="field">
          <label for="location_id">Location</label>
          <select id="location_id" name="location_id">
            <option value="">— none —</option>
            @foreach ($locations as $location)
              <option value="{{ $location->id }}" @selected(old('location_id', $apartment->location_id) == $location->id)>
                {{ $location->t('name', 'de') }} — {{ $location->addressLine() }}
              </option>
            @endforeach
          </select>
          <small>The address lives on the location, so changing it once changes every flat there.</small>
        </div>
      </div>

      <div class="row">
        <div class="field">
          <label for="price_euros">Rate per night (€)</label>
          <input id="price_euros" name="price_euros" type="number" step="0.01" min="0"
                 value="{{ old('price_euros', $apartment->exists ? number_format($apartment->price_per_night / 100, 2, '.', '') : '') }}" required>
          <small>Typed in euros, stored in cents.</small>
        </div>
        <div class="field">
          <label for="max_guests">Sleeps</label>
          <input id="max_guests" name="max_guests" type="number" min="1" max="20" value="{{ old('max_guests', $apartment->max_guests ?? 2) }}" required>
        </div>
        <div class="field">
          <label for="bedrooms">Bedrooms</label>
          <input id="bedrooms" name="bedrooms" type="number" min="0" max="20" value="{{ old('bedrooms', $apartment->bedrooms ?? 1) }}" required>
        </div>
        <div class="field">
          <label for="size_sqm">Size (m2)</label>
          <input id="size_sqm" name="size_sqm" type="number" min="1" value="{{ old('size_sqm', $apartment->size_sqm) }}">
        </div>
        <div class="field">
          <label for="sort">Order</label>
          <input id="sort" name="sort" type="number" min="0" value="{{ old('sort', $apartment->sort ?? 0) }}">
        </div>
      </div>

      <label class="check">
        <input type="hidden" name="is_published" value="0">
        <input type="checkbox" name="is_published" value="1" @checked(old('is_published', $apartment->is_published ?? false))>
        <span><b>Published</b><small>Unpublished flats vanish from the site and from the sitemap, but keep their bookings.</small></span>
      </label>
    </div>

    {{-- Both languages side by side, so a gap in one is visible while typing
         rather than discovered later on the live site. --}}
    @foreach ([
      ['name', 'Name', 'text', 'Shown on the cards and as the page heading.'],
      ['brief', 'Short line', 'textarea', 'One sentence, used on the overview page.'],
      ['description', 'Description', 'textarea', 'The full paragraph on the flat page.'],
      ['features', 'Features', 'textarea', 'One per line.'],
    ] as [$field, $label, $type, $help])
      <div class="card">
        <h2>{{ $label }}</h2>
        <p class="card__hint">{{ $help }}</p>
        <div class="langs">
          @foreach (config('kaiserzeit.locales') as $code => $language)
            @php
              $stored = $apartment->getAttribute($field)[$code] ?? '';
              $stored = is_array($stored) ? implode(PHP_EOL, $stored) : $stored;
              $current = old($field . '.' . $code, $stored);
            @endphp
            <div class="field">
              <label for="{{ $field }}-{{ $code }}"><span class="lang-tag">{{ $code }}</span>{{ $language }}</label>
              @if ($type === 'textarea')
                <textarea id="{{ $field }}-{{ $code }}" name="{{ $field }}[{{ $code }}]">{{ $current }}</textarea>
              @else
                <input id="{{ $field }}-{{ $code }}" name="{{ $field }}[{{ $code }}]" type="text" value="{{ $current }}">
              @endif
            </div>
          @endforeach
        </div>
      </div>
    @endforeach

    <div class="ad__actions">
      <button class="btn btn--go" type="submit">{{ $apartment->exists ? 'Save flat' : 'Create flat' }}</button>
      <a class="btn" href="{{ admin_url() }}/apartments">Cancel</a>
    </div>
  </form>

  @if ($apartment->exists)
    <form method="post" action="{{ admin_url() }}/apartments/{{ $apartment->id }}" style="margin-top:20px"
          onsubmit="return confirm('Delete this flat?')">
      @csrf @method('delete')
      <button class="btn btn--bad btn--sm" type="submit">Delete this flat</button>
    </form>

    @if ($activity->count())
      <div class="card" style="margin-top:20px">
        <h2>History</h2>
        <div class="tw"><table>
          <tbody>
            @foreach ($activity as $entry)
              <tr>
                <td>{{ $entry->user_label }}</td>
                <td>
                  {{ $entry->description ?: $entry->action }}
                  @if ($entry->changes)
                    <ul class="diff dim">
                      @foreach ($entry->changes as $field => $change)
                        <li>{{ $field }}:
                          <del>{{ Str::limit(json_encode($change['from'] ?? null, JSON_UNESCAPED_UNICODE), 50) }}</del>
                          <ins>{{ Str::limit(json_encode($change['to'] ?? null, JSON_UNESCAPED_UNICODE), 50) }}</ins></li>
                      @endforeach
                    </ul>
                  @endif
                </td>
                <td class="num dim">{{ $entry->created_at?->diffForHumans(short: true) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table></div>
      </div>
    @endif
  @endif

@endsection
