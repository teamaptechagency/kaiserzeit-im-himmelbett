@extends('admin.layout')
@section('title', 'Configuration')

@section('content')

  <div class="card">
    <p class="card__hint">
      Everything here can be changed without a deployment. Database credentials and the app key
      deliberately stay in <code>.env</code>, where changing one is an act rather than a form
      submission — and where getting it wrong takes the site down rather than making it odd.
    </p>
  </div>

  <form method="post" action="{{ admin_url() }}/settings">
    @csrf
    @method('put')

    @foreach ($grouped as $group => $fields)
      <div class="card">
        <h2>{{ ucfirst($group) }}</h2>

        @foreach ($fields as $key => $field)
          @php $name = 'settings[' . $key . ']'; @endphp

          @if ($field['type'] === 'bool')
            <label class="check">
              <input type="checkbox" name="{{ $name }}" value="1" @checked((bool) $field['value'])>
              <span><b>{{ $field['label'] }}</b>@if ($field['help'])<small>{{ $field['help'] }}</small>@endif</span>
            </label>

          @elseif ($field['type'] === 'trans')
            <div class="field">
              <label>{{ $field['label'] }}</label>
              @if ($field['help'])<small style="margin:0 0 8px">{{ $field['help'] }}</small>@endif
              <div class="langs">
                @foreach (config('kaiserzeit.locales') as $code => $language)
                  <div class="field" style="margin:0">
                    <label for="{{ $key }}-{{ $code }}"><span class="lang-tag">{{ $code }}</span>{{ $language }}</label>
                    <input id="{{ $key }}-{{ $code }}" name="settings[{{ $key }}][{{ $code }}]" type="text"
                           value="{{ is_array($field['value']) ? ($field['value'][$code] ?? '') : '' }}">
                  </div>
                @endforeach
              </div>
            </div>

          @elseif ($field['type'] === 'locale')
            <div class="field">
              <label for="{{ $key }}">{{ $field['label'] }}</label>
              <select id="{{ $key }}" name="{{ $name }}">
                @foreach (config('kaiserzeit.locales') as $code => $language)
                  <option value="{{ $code }}" @selected($field['value'] === $code)>{{ $language }}</option>
                @endforeach
              </select>
              @if ($field['help'])<small>{{ $field['help'] }}</small>@endif
            </div>

          @else
            <div class="field">
              <label for="{{ $key }}">{{ $field['label'] }}@if ($field['type'] === 'money') <span class="dim">(€)</span>@endif</label>
              <input id="{{ $key }}" name="{{ $name }}"
                     type="{{ $field['type'] === 'email' ? 'email' : ($field['type'] === 'number' ? 'number' : ($field['type'] === 'money' ? 'number' : 'text')) }}"
                     @if ($field['type'] === 'money') step="0.01" min="0" @endif
                     value="{{ $field['type'] === 'money' ? number_format(((int) $field['value']) / 100, 2, '.', '') : (is_array($field['value']) ? '' : $field['value']) }}">
              @if ($field['help'])<small>{{ $field['help'] }}</small>@endif
            </div>
          @endif
        @endforeach
      </div>
    @endforeach

    <div class="ad__actions">
      <button class="btn btn--go" type="submit">Save configuration</button>
    </div>
  </form>

@endsection
