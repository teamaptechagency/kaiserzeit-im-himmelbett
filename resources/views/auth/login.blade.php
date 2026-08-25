@extends('auth.layout')
@section('title', kz('auth.brand_signin'))
@section('kicker', kz('auth.brand_signin') . ' · ' . kz('auth.brand_signin', $other))

@push('head')
  @if ($passkeyOffered)
    <script src="/passkey.js" defer></script>
  @endif
@endpush

@section('content')

  <h1>{{ kz('auth.signin_title') }} <span class="au__en">{{ kz('auth.signin_title', $other) }}</span></h1>
  <p class="au__lead">
    {{-- One form for everyone. Which panel somebody lands in is decided by
         the account, not by a choice made here: asking a guest to pick
         "customer or staff?" only invites them to pick wrongly. --}}
    {{ kz('auth.signin_lead') }}
    <span class="au__en">{{ kz('auth.signin_lead', $other) }}</span>
  </p>

  {{-- Drawn only on a browser that has registered a passkey. On every other
       browser it does not exist, rather than appearing and failing. --}}
  @if ($passkeyOffered)
    <button class="au__btn au__btn--go" type="button" id="kz-passkey-signin">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="M12 3a4 4 0 0 1 4 4c0 1.6-.6 2.6-1.4 3.6M8 7a4 4 0 0 1 .6-2.1M6.5 12.5c1-1 1.5-2 1.5-3.5M9.5 21c-.8-1.6-1-3-1-4.5a3.5 3.5 0 0 1 7 0c0 .8 0 1.6-.2 2.4M18.5 19c.4-1.6.5-3 .5-4.5"
              stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
      </svg>
      {{ kz('auth.passkey_button') }}
    </button>
    <p class="au__hint" id="kz-passkey-note" hidden></p>
    <p class="au__or"><span>{{ kz('auth.passkey_or') }}</span></p>
  @endif

  <form method="post" action="/login" autocomplete="on">
    @csrf
    <input type="hidden" name="next" value="{{ $next }}">

    <div class="au__field">
      <label for="email">
        {{ kz('auth.identifier') }} <span class="au__en">{{ kz('auth.identifier', $other) }}</span>
      </label>
      {{-- One field, either kind. The server tells them apart by the @, so
           nobody has to be asked which they are about to type. --}}
      <input id="email" name="email" type="text" value="{{ old('email') }}"
             autocomplete="username" autocapitalize="none" spellcheck="false"
             placeholder="nazmul  ·  name@beispiel.de" required autofocus>
    </div>

    @include('auth.partials.password', [
      'id' => 'password', 'name' => 'password',
      'label' => kz('auth.password'), 'en' => kz('auth.password', $other),
      'autocomplete' => 'current-password', 'help' => null,
    ])

    <div class="au__actions">
      <button class="au__btn au__btn--go" type="submit">{{ kz('auth.signin_button') }}</button>
    </div>
  </form>

  @if ($registrationOpen)
    <p class="au__swap">
      @if ($needsAdmin)
        {{ kz('auth.no_admin') }} <a href="/register">{{ kz('auth.no_admin_link') }}</a>
        <span class="au__en">{{ kz('auth.no_admin', $other) }} {{ kz('auth.no_admin_link', $other) }}</span>
      @else
        {{ kz('auth.no_account') }} <a href="/register">{{ kz('auth.no_account_link') }}</a>
        <span class="au__en">{{ kz('auth.no_account', $other) }} {{ kz('auth.no_account_link', $other) }}</span>
      @endif
    </p>
  @endif

@endsection
