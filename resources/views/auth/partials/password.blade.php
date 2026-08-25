{{--
    A password field with a reveal button.

    Ten characters is a lot to type blind, and a mistyped one on a sign-up
    form is only discovered by the confirm field disagreeing — which tells you
    that one of the two is wrong, not which.

    Expects: $id, $name, $label, $en, $help — pass $help as null where there
    is none rather than leaving it out. Blade hands the including view's
    variables down into every include below it, so an omitted $help is not an
    empty one: it is whatever happened to be in scope, which on the sign-up
    form was the mobile field's.

    Optional: $autocomplete, $min.
--}}
<div class="au__field">
  <label for="{{ $id }}">{{ $label }} <span class="au__en">{{ $en }}</span></label>

  <div class="au__pw">
    <input id="{{ $id }}"
           name="{{ $name }}"
           type="password"
           autocomplete="{{ $autocomplete ?? 'current-password' }}"
           @if (! empty($min)) minlength="{{ $min }}" @endif
           required>

    <button class="au__eye" type="button"
            data-reveals="{{ $id }}"
            aria-label="Passwort anzeigen · Show password"
            aria-pressed="false">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12z"
              stroke="currentColor" stroke-width="1.5"/>
        <circle cx="12" cy="12" r="2.6" stroke="currentColor" stroke-width="1.5"/>
      </svg>
    </button>
  </div>

  @if (! empty($help))
    <small>{{ $help }}</small>
  @endif
</div>
