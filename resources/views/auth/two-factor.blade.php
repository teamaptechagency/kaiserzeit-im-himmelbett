@extends('auth.layout')
@section('title', kz('auth.twofactor_title'))
@section('kicker', kz('auth.brand_2fa') . ' · ' . kz('auth.brand_2fa', $other))

@section('content')

  {{-- Nothing here says which account is waiting. Somebody who reached this
       screen with a stolen password should learn nothing new from it. --}}
  <h1>{{ kz('auth.twofactor_title') }} <span class="au__en">{{ kz('auth.twofactor_title', $other) }}</span></h1>
  <p class="au__lead">
    {{ kz('auth.twofactor_lead') }}
    <span class="au__en">{{ kz('auth.twofactor_lead', $other) }}</span>
  </p>

  <form method="post" action="/login/two-factor" autocomplete="off">
    @csrf
    <input type="hidden" name="next" value="{{ $next }}">

    <div class="au__field">
      <label for="code">{{ kz('auth.code') }}</label>
      <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
             maxlength="40" placeholder="000000" required autofocus class="au__code">
    </div>

    <div class="au__actions">
      <button class="au__btn au__btn--go" type="submit">{{ kz('auth.continue') }}</button>
    </div>
  </form>

  <p class="au__swap"><a href="/login/cancel">{{ kz('auth.start_over') }}</a></p>

@endsection
