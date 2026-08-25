@extends('admin.layout')
@section('title', 'Pages & SEO')

@section('content')

  <div class="card">
    <p class="card__hint">
      A page with no meta description does not get none — it gets whichever sentence Google
      decides to quote, which is rarely the one you would have chosen. Anything flagged below
      is worth ten minutes.
    </p>
  </div>

  <div class="card">
    @if ($pages->count())
      <div class="tw"><table>
        <thead>
          <tr><th>Page</th><th>URL</th><th>Search engines</th><th>Sitemap</th><th>Wants attention</th><th></th></tr>
        </thead>
        <tbody>
          @foreach ($pages as $page)
            <tr>
              <td>
                <a href="{{ admin_url() }}/pages/{{ $page->id }}/edit">{{ $page->t('title', 'de') ?: $page->key }}</a>
                <div class="dim mono" style="font-size:12px">{{ $page->key }}</div>
              </td>
              <td class="dim mono" style="font-size:13px">{{ $page->path() }}</td>
              <td>
                @if (! $page->is_published)
                  <span class="chip chip--warn">unpublished</span>
                @elseif ($page->noindex)
                  <span class="chip chip--bad">noindex</span>
                @else
                  <span class="chip chip--ok">indexed</span>
                @endif
              </td>
              <td class="dim">{{ number_format($page->sitemap_priority, 1) }} · {{ $page->sitemap_frequency }}</td>
              <td>
                @forelse ($notes[$page->id] as $note)
                  <div><span class="chip chip--warn">{{ $note }}</span></div>
                @empty
                  <span class="dim">nothing</span>
                @endforelse
              </td>
              <td class="num"><a class="btn btn--sm" href="{{ admin_url() }}/pages/{{ $page->id }}/edit">Edit</a></td>
            </tr>
          @endforeach
        </tbody>
      </table></div>
    @else
      <p class="empty">No pages.</p>
    @endif
  </div>

  <div class="card">
    <h2>What is published</h2>
    <p class="card__hint">
      Both are generated from this table, so publishing a page adds it and unpublishing removes it.
    </p>
    <p>
      <a href="{{ $baseUrl }}/sitemap.xml" target="_blank" rel="noopener">sitemap.xml</a> ·
      <a href="{{ $baseUrl }}/robots.txt" target="_blank" rel="noopener">robots.txt</a>
    </p>
    @unless (\App\Models\Setting::get('seo_indexable', false))
      <p><span class="chip chip--warn">Search engines are shut out site-wide</span>
        — turn that on in <a href="{{ admin_url() }}/settings">Configuration</a> when the site is ready.</p>
    @endunless
  </div>

@endsection
