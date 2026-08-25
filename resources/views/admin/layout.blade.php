{{--
    The frame every admin screen sits in.

    Counts in the sidebar are queried here rather than passed in by each
    controller: they belong to the navigation, not to whatever page happens to
    be open, and threading them through twelve controllers would mean the one
    that forgot showed a stale zero.
--}}
@php
    $waiting = [
        'messages' => \App\Models\ContactMessage::where('is_handled', false)->count(),
        'reviews'  => \App\Models\Review::where('status', 'pending')->count(),
        'bookings' => \App\Models\Booking::where('status', 'requested')->count(),
    ];

    $nav = [
        'Site' => [
            [admin_url(''), 'Dashboard', 'chart', null],
            [admin_url('/bookings'), 'Bookings', 'calendar', $waiting['bookings']],
            [admin_url('/messages'), 'Messages', 'mail', $waiting['messages']],
            [admin_url('/reviews'), 'Reviews', 'star', $waiting['reviews']],
        ],
        'Content' => [
            [admin_url('/apartments'), 'Flats', 'home', null],
            [admin_url('/locations'), 'Locations', 'pin', null],
            [admin_url('/amenities'), 'Amenities', 'grid', null],
            [admin_url('/pages'), 'Pages & SEO', 'file', null],
            [admin_url('/translations'), 'Translations', 'globe', null],
            /* Photos are still managed from the site editor and the photo
               overview at /photos. A media library screen belongs here, but
               it does not exist yet and a link to a 404 is worse than none. */
            ['/photos', 'Photos', 'image', null],
        ],
        'Administration' => [
            [admin_url('/users'), 'Users', 'users', null],
            [admin_url('/activity'), 'Activity', 'list', null],
            [admin_url('/security'), 'Security', 'shield', null],
            [admin_url('/settings'), 'Configuration', 'cog', null],
        ],
    ];

    $here = '/' . trim(request()->path(), '/');
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'Admin') — KAISERZEIT</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=EB+Garamond:wght@400;500;600&display=swap" rel="stylesheet">
{{-- A file in public/, not a route. It only looks like the panel's address. --}}
<link rel="stylesheet" href="/admin.css?v=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<script src="/passkey.js" defer></script>
</head>
<body>
<div class="ad">

  <aside class="ad__side">
    <a class="ad__brand" href="{{ admin_url() }}">
      <span class="ad__mark">KZ</span>
      <span>KAISERZEIT<small>Admin</small></span>
    </a>

    <nav>
      @foreach ($nav as $heading => $items)
        <p class="ad__navhead">{{ $heading }}</p>
        <ul class="ad__nav">
          @foreach ($items as [$href, $label, $icon, $badge])
            <li>
              <a href="{{ $href }}" @class(['is-here' => $here === $href || ($href !== admin_url('') && str_starts_with($here, $href))])>
                <span>{{ $label }}</span>
                @if ($badge)<em class="ad__badge">{{ $badge }}</em>@endif
              </a>
            </li>
          @endforeach
        </ul>
      @endforeach
    </nav>

    <div class="ad__me">
      <p class="ad__mename">{{ auth()->user()->name }}</p>
      <p class="ad__merole">{{ auth()->user()->roleLabel() }}</p>
      <div class="ad__melinks">
        <a href="/" target="_blank" rel="noopener">View site</a>
        <form method="post" action="/logout">@csrf<button type="submit">Sign out</button></form>
      </div>
    </div>
  </aside>

  <main class="ad__main">
    <header class="ad__head">
      <h1>@yield('title', 'Admin')</h1>
      <div class="ad__actions">@yield('actions')</div>
    </header>

    @if (session('status'))
      <div class="ad__flash">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
      <div class="ad__flash ad__flash--bad">
        @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
      </div>
    @endif

    @yield('content')
  </main>

</div>
</body>
</html>
