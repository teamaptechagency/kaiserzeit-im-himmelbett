@extends('admin.layout')
@section('title', $page->t('title', 'de') ?: $page->key)

@section('content')

  @if ($notes)
    <div class="ad__flash ad__flash--bad">
      @foreach ($notes as $note)<p>{{ $note }}</p>@endforeach
    </div>
  @endif

  <form method="post" action="{{ admin_url() }}/pages/{{ $page->id }}">
    @csrf @method('put')

    <div class="card">
      <h2>Address and visibility</h2>

      <div class="row">
        <div class="field">
          <label for="slug">Slug</label>
          <input id="slug" name="slug" type="text" value="{{ old('slug', $page->slug) }}">
          <small>Empty means the home page. Currently: <code>{{ $baseUrl . $page->path() }}</code></small>
        </div>
        <div class="field">
          <label for="canonical">Canonical URL</label>
          <input id="canonical" name="canonical" type="text" value="{{ old('canonical', $page->canonical) }}">
          <small>Only when this page duplicates another. Leave empty otherwise.</small>
        </div>
      </div>

      <div class="row">
        <div class="field">
          <label for="sitemap_priority">Sitemap priority</label>
          <input id="sitemap_priority" name="sitemap_priority" type="number" step="0.1" min="0" max="1"
                 value="{{ old('sitemap_priority', $page->sitemap_priority) }}" required>
          <small>Relative to this site only. 1.0 for the home page.</small>
        </div>
        <div class="field">
          <label for="sitemap_frequency">Changes</label>
          <select id="sitemap_frequency" name="sitemap_frequency">
            @foreach (['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'] as $option)
              <option value="{{ $option }}" @selected(old('sitemap_frequency', $page->sitemap_frequency) === $option)>{{ $option }}</option>
            @endforeach
          </select>
        </div>
      </div>

      <label class="check">
        <input type="hidden" name="is_published" value="0">
        <input type="checkbox" name="is_published" value="1" @checked(old('is_published', $page->is_published))>
        <span><b>Published</b><small>Unpublished pages leave the site and the sitemap.</small></span>
      </label>

      <label class="check">
        <input type="hidden" name="noindex" value="0">
        <input type="checkbox" name="noindex" value="1" @checked(old('noindex', $page->noindex))>
        <span><b>Keep out of search results</b><small>Right for a guest account page; wrong for anything you want found.</small></span>
      </label>
    </div>

    <div class="card">
      <h2>Titles</h2>
      @include('admin.partials.langfield', ['model' => $page, 'field' => 'title', 'label' => 'Page title', 'help' => 'Used on the page itself and as a fallback for the search title.'])
      @include('admin.partials.langfield', ['model' => $page, 'field' => 'meta_title', 'label' => 'Search title', 'help' => 'What appears as the blue link. Around 60 characters before it is cut.'])
    </div>

    <div class="card">
      <h2>Meta description</h2>
      <p class="card__hint">
        The grey sentence under the link. Between {{ \App\Http\Controllers\Admin\PageController::DESCRIPTION_MIN }}
        and {{ \App\Http\Controllers\Admin\PageController::DESCRIPTION_MAX }} characters: shorter wastes the
        space, longer gets cut off mid-word.
      </p>
      @include('admin.partials.langfield', ['model' => $page, 'field' => 'meta_description', 'label' => 'Description', 'type' => 'textarea', 'rows' => 3])
    </div>

    <div class="card">
      <h2>When shared</h2>
      <p class="card__hint">What WhatsApp, Facebook and the rest show. Falls back to the search title and description.</p>
      @include('admin.partials.langfield', ['model' => $page, 'field' => 'og_title', 'label' => 'Share title'])
      @include('admin.partials.langfield', ['model' => $page, 'field' => 'og_description', 'label' => 'Share description', 'type' => 'textarea', 'rows' => 3])
    </div>

    <div class="ad__actions">
      <button class="btn btn--go" type="submit">Save page</button>
      <a class="btn" href="{{ admin_url() }}/pages">Cancel</a>
      <a class="btn" href="{{ $page->path() }}" target="_blank" rel="noopener">View</a>
    </div>
  </form>

@endsection
