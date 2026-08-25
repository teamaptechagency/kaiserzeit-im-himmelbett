@extends('admin.layout')
@section('title', $review->exists ? 'Review by ' . $review->author : 'New review')

@section('content')

  <form method="post" action="{{ $review->exists ? admin_url("/reviews/{$review->id}") : admin_url('/reviews') }}">
    @csrf
    @if ($review->exists) @method('put') @endif

    <div class="grid grid--2">

      <div class="card">
        <h2>Who</h2>

        <div class="row">
          <div class="field">
            <label for="author">Name</label>
            <input id="author" name="author" type="text" value="{{ old('author', $review->author) }}" required>
          </div>
          <div class="field">
            <label for="author_note">Note under the name</label>
            <input id="author_note" name="author_note" type="text" value="{{ old('author_note', $review->author_note) }}">
            <small>Such as "stayed in March".</small>
          </div>
        </div>

        <div class="row">
          <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email', $review->email) }}">
            <small>Never shown. Kept so you can reply privately.</small>
          </div>
          <div class="field">
            <label for="stayed_at">Stayed</label>
            <input id="stayed_at" name="stayed_at" type="date" value="{{ old('stayed_at', $review->stayed_at?->toDateString()) }}">
          </div>
        </div>

        <div class="row">
          <div class="field">
            <label for="apartment_id">Flat</label>
            <select id="apartment_id" name="apartment_id">
              <option value="">— not about one in particular —</option>
              @foreach ($apartments as $flat)
                <option value="{{ $flat->id }}" @selected(old('apartment_id', $review->apartment_id) == $flat->id)>
                  {{ $flat->t('name', 'de') }}
                </option>
              @endforeach
            </select>
          </div>
          <div class="field">
            <label for="source">Came from</label>
            <select id="source" name="source">
              @foreach (['site' => 'This site', 'google' => 'Google', 'airbnb' => 'Airbnb', 'booking' => 'Booking.com'] as $value => $label)
                <option value="{{ $value }}" @selected(old('source', $review->source ?? 'site') === $value)>{{ $label }}</option>
              @endforeach
            </select>
          </div>
        </div>
      </div>

      <div class="card">
        <h2>Standing</h2>

        <div class="row">
          <div class="field">
            <label for="rating">Rating</label>
            <select id="rating" name="rating" required>
              @for ($stars = 5; $stars >= 1; $stars--)
                <option value="{{ $stars }}" @selected((int) old('rating', $review->rating ?? 5) === $stars)>
                  {{ str_repeat('★', $stars) }}{{ str_repeat('☆', 5 - $stars) }} ({{ $stars }})
                </option>
              @endfor
            </select>
          </div>
          <div class="field">
            <label for="status">Status</label>
            <select id="status" name="status" required>
              @foreach (['pending' => 'Waiting to be read', 'approved' => 'Live on the site', 'rejected' => 'Rejected'] as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $review->status ?? 'approved') === $value)>{{ $label }}</option>
              @endforeach
            </select>
            <small>Rejected reviews are kept, not deleted — "did you take mine down?" deserves an answer.</small>
          </div>
          <div class="field">
            <label for="sort">Order</label>
            <input id="sort" name="sort" type="number" min="0" value="{{ old('sort', $review->sort ?? 0) }}">
          </div>
        </div>

        <label class="check">
          <input type="hidden" name="is_featured" value="0">
          <input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $review->is_featured ?? false))>
          <span><b>Show on the home page</b><small>Only takes effect once the review is approved.</small></span>
        </label>

        <div class="field">
          <label for="title">Headline</label>
          <input id="title" name="title" type="text" value="{{ old('title', $review->title) }}">
        </div>
      </div>

    </div>

    <div class="card">
      <h2>What they wrote</h2>
      <div class="langs">
        @foreach (config('kaiserzeit.locales') as $code => $language)
          <div class="field">
            <label for="quote-{{ $code }}"><span class="lang-tag">{{ $code }}</span>{{ $language }}</label>
            <textarea id="quote-{{ $code }}" name="quote[{{ $code }}]">{{ old('quote.' . $code, $review->getAttribute('quote')[$code] ?? '') }}</textarea>
          </div>
        @endforeach
      </div>
      <p class="card__hint" style="margin:12px 0 0">
        A guest writes in one language. Filling the other is a translation, not an invention —
        leave it empty and the site falls back to what they actually said.
      </p>
    </div>

    <div class="card">
      <h2>Your reply</h2>
      <p class="card__hint">Shown publicly under the review.</p>
      <div class="field">
        <textarea id="reply" name="reply">{{ old('reply', $review->reply) }}</textarea>
      </div>
    </div>

    <div class="ad__actions">
      <button class="btn btn--go" type="submit">{{ $review->exists ? 'Save review' : 'Add review' }}</button>
      <a class="btn" href="{{ admin_url() }}/reviews">Cancel</a>
    </div>
  </form>

  @if ($review->exists)
    <form method="post" action="{{ admin_url() }}/reviews/{{ $review->id }}" style="margin-top:20px"
          onsubmit="return confirm('Delete this review outright? Rejecting it keeps the record.')">
      @csrf @method('delete')
      <button class="btn btn--bad btn--sm" type="submit">Delete this review</button>
    </form>
  @endif

@endsection
