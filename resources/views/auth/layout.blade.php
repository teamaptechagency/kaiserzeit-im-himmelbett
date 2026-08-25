{{--
    The shell every sign-in screen sits in.

    Two panels: one of the flats on the left, the form on the right. The
    picture is not decoration — it is the first thing a guest sees of the
    place, and a sign-in screen that looks like the site it belongs to is one
    fewer reason to wonder whether the link was genuine.

    Every word comes from the translations table rather than from this file,
    so the switch at the top can lead with either language. See SetLocale for
    how the starting one is chosen — a visitor whose browser asks for English
    gets English before touching anything.
--}}
@php
    use App\Http\Middleware\SetLocale;
    use App\Models\Apartment;
    use App\Models\ImageSlot;
    use App\Models\Setting;

    $locale  = app()->getLocale();
    $other   = SetLocale::other();
    $locales = config('kaiserzeit.locales', ['de' => 'Deutsch']);

    /*
     * A photograph of a real flat, once one has been uploaded.
     *
     * Until then the vintage map of Blumenstraße stands in, rather than the
     * plaster texture that was here before: it is the site's own artwork, it
     * shows the actual building, and it gives the panel something to be
     * about. A texture is a backdrop; this is a picture of the place.
     *
     * The map is cream where a photograph is dark, so it sets a flag — the
     * scrim has to work much harder to keep white text legible over it.
     *
     * Wrapped because this screen has to render on a site whose database is
     * not answering, which is exactly when somebody signs in to find out why.
     */
    $flat     = null;
    $photo    = null;
    $siteName = 'KAISERZEIT im Himmelbett';

    try {
        $flat  = Apartment::published()->first();
        $photo = ImageSlot::where('slot', 'like', 'apt-hero-%')->first()
              ?? ImageSlot::where('slot', 'like', 'apt-%')->first();
        $siteName = Setting::get('site_name') ?: $siteName;
    } catch (Throwable $error) {
        /* Leave the defaults. */
    }

    $image    = $photo?->url() ?? '/assets/vintage-map-blumenstrasse.png';
    $onLight  = $photo === null;
    $flatName = $photo ? $flat?->t('name') : 'Blumenstraße 1, Leipzig';

    /* Keeps whatever brought them here across a language change. */
    $switchTo = request()->fullUrlWithQuery(['lang' => $other]);
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', $locale) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title') — {{ $siteName }}</title>
<meta name="robots" content="noindex,nofollow">
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=EB+Garamond:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/auth.css?v=6">
<script src="/auth.js" defer></script>
@stack('head')
</head>
<body>
<main class="au">

  {{-- ------------------------------------------------------------ picture --}}
  <section class="au__art @if ($onLight) au__art--light @endif"
           style="background-image:url('{{ $image }}')" aria-hidden="true">
    <div class="au__scrim"></div>
    <div class="au__artcopy">
      <span class="au__eyebrow">Ein erlesener Aufenthalt in Leipzig</span>
      <p class="au__quote">
        Zwischen hohen Altbaudecken und samtweichen Details — das Erbe der
        Kaiserzeit mit dem Komfort von heute.
      </p>
      @if ($flatName)
        <p class="au__caption">{{ $flatName }}</p>
      @endif
    </div>
  </section>

  {{-- --------------------------------------------------------------- form --}}
  <section class="au__panel">
    <div class="au__inner">

      <div class="au__top">
        <a class="au__brand" href="/">
          <span class="au__mark">KZ</span>
          <span>{{ $siteName }}<small>@yield('kicker')</small></span>
        </a>

        {{--
          The language picker.

          Closed it shows the short code, because that is all the room a
          header has for something nobody came here to look at. Open, every
          language is written in its own language and in full — somebody
          hunting for theirs scans for the word they would use for it, so
          "Français", not "FR" and not "Französisch".

          <details> rather than a <select>: a select cannot show a tick beside
          the current choice, and this works with scripts switched off.
        --}}
        <details class="au__lang">
          <summary aria-label="{{ kz('auth.language') }}">
            <span class="au__lang-code">{{ strtoupper($locale) }}</span>
            <svg width="10" height="10" viewBox="0 0 12 12" aria-hidden="true">
              <path d="M2 4.5 6 8.5 10 4.5" fill="none" stroke="currentColor" stroke-width="1.4"
                    stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </summary>

          <ul class="au__langlist">
            @foreach ($locales as $code => $name)
              <li>
                <a href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}"
                   hreflang="{{ $code }}" lang="{{ $code }}"
                   @if ($code === $locale) aria-current="true" @endif>
                  <span class="au__langname">{{ $name }}</span>
                  <span class="au__langcode">{{ strtoupper($code) }}</span>
                </a>
              </li>
            @endforeach
          </ul>
        </details>
      </div>

      @if (session('status'))
        <div class="au__note">{{ session('status') }}</div>
      @endif

      @if ($errors->any())
        <div class="au__note au__note--bad">
          @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
        </div>
      @endif

      @yield('content')

      <p class="au__foot">
        <a href="/">← {{ kz('auth.back_to_site') }}</a>
      </p>

    </div>
  </section>

</main>
@stack('scripts')
</body>
</html>
