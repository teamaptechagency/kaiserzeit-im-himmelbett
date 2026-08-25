@extends('auth.layout')
@section('title', $needsAdmin ? kz('auth.admin_title') : kz('auth.signup_title'))
@section('kicker', $needsAdmin
    ? kz('auth.first_account') . ' · ' . kz('auth.first_account', $other)
    : kz('auth.brand_signup') . ' · ' . kz('auth.brand_signup', $other))

@section('content')

  @if ($needsAdmin)
    {{-- Deliberately loud. Whoever fills this in takes ownership of the site,
         and that should not look like an ordinary sign-up. --}}
    <div class="au__claim">
      <b>{{ kz('auth.admin_claim_title') }}</b>
      {{ kz('auth.admin_claim_body') }}
      <span class="au__en">{{ kz('auth.admin_claim_body', $other) }}</span>
    </div>

    <h1>{{ kz('auth.admin_title') }} <span class="au__en">{{ kz('auth.admin_title', $other) }}</span></h1>
    <p class="au__lead">
      {{ kz('auth.admin_lead') }}
      <span class="au__en">{{ kz('auth.admin_lead', $other) }}</span>
    </p>
  @else
    <h1>{{ kz('auth.signup_title') }} <span class="au__en">{{ kz('auth.signup_title', $other) }}</span></h1>
    <p class="au__lead">
      {{ kz('auth.signup_lead') }}
      <span class="au__en">{{ kz('auth.signup_lead', $other) }}</span>
    </p>
  @endif

  <form method="post" action="/register" autocomplete="on">
    @csrf

    <div class="au__row">
      <div class="au__field">
        <label for="name">{{ kz('auth.name') }} <span class="au__en">{{ kz('auth.name', $other) }}</span></label>
        <input id="name" name="name" type="text" value="{{ old('name') }}"
               autocomplete="name" required autofocus>
      </div>

      <div class="au__field">
        <label for="username">{{ kz('auth.username') }} <span class="au__en">{{ kz('auth.username', $other) }}</span></label>
        <input id="username" name="username" type="text" value="{{ old('username') }}"
               autocomplete="username" autocapitalize="none" spellcheck="false"
               minlength="3" maxlength="30" pattern="[A-Za-z0-9][A-Za-z0-9._\-]{2,29}"
               placeholder="nazmul" required>
        {{-- The characters themselves rather than a sentence about them: it
             is shorter, and it needs no translating. --}}
        <small>a–z 0–9 . _ -</small>
      </div>
    </div>

    <div class="au__field">
      <label for="email">{{ kz('auth.email') }} <span class="au__en">{{ kz('auth.email', $other) }}</span></label>
      <input id="email" name="email" type="email" value="{{ old('email') }}"
             autocomplete="email" placeholder="name@beispiel.de" required>
      <small>{{ kz('auth.email_help') }} · {{ kz('auth.email_help', $other) }}</small>
    </div>

    <div class="au__field">
      <label for="phone">
        {{ kz('auth.mobile') }} <span class="au__en">{{ kz('auth.mobile', $other) }}</span>
        @unless ($needsAdmin)<span class="au__opt">{{ kz('auth.optional') }}</span>@endunless
      </label>
      <input id="phone" name="phone" type="tel" value="{{ old('phone') }}"
             autocomplete="tel" placeholder="+49 …">
      <small>
        {{-- Named for what it is, not $help: Blade hands a view's variables
             down into every @include below it, and a bare $help here was
             picked up by the second password field — which passes none — and
             printed as the literal key. --}}
        @php $mobileHelp = $needsAdmin ? 'auth.mobile_help_admin' : 'auth.mobile_help'; @endphp
        {{ kz($mobileHelp) }} · {{ kz($mobileHelp, $other) }}
      </small>
    </div>

    <div class="au__row">
      @include('auth.partials.password', [
        'id' => 'password', 'name' => 'password',
        'label' => kz('auth.password'), 'en' => kz('auth.password', $other),
        'autocomplete' => 'new-password', 'min' => 10,
        'help' => kz('auth.password_help') . ' · ' . kz('auth.password_help', $other),
      ])

      @include('auth.partials.password', [
        'id' => 'password_confirmation', 'name' => 'password_confirmation',
        'label' => kz('auth.password_repeat'), 'en' => kz('auth.password_repeat', $other),
        'autocomplete' => 'new-password', 'min' => 10, 'help' => null,
      ])
    </div>

    <div class="au__actions">
      <button class="au__btn au__btn--go" type="submit">
        {{ $needsAdmin ? kz('auth.admin_button') : kz('auth.signup_button') }}
      </button>
    </div>
  </form>

  <p class="au__swap">
    {{ kz('auth.have_account') }} <a href="/login">{{ kz('auth.have_account_link') }}</a>
    <span class="au__en">{{ kz('auth.have_account', $other) }} {{ kz('auth.have_account_link', $other) }}</span>
  </p>

@endsection
