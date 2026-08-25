{{--
    One translatable field, shown in every language side by side.

    Side by side rather than behind language tabs on purpose: a gap in one
    language is then visible while somebody is typing, instead of being found
    later on the live site by a guest who cannot read the other one.

    Expects: $model, $field, $label; optionally $type (text|textarea), $help,
    and $rows for a taller textarea.
--}}
@php
    $type = $type ?? 'text';
    $help = $help ?? null;
@endphp

<div class="field">
  <label>{{ $label }}</label>
  @if ($help)<small style="margin:0 0 8px">{{ $help }}</small>@endif

  <div class="langs">
    @foreach (config('kaiserzeit.locales') as $code => $language)
      @php
        $stored = is_object($model) ? ($model->getAttribute($field)[$code] ?? '') : ($model[$field][$code] ?? '');
        $stored = is_array($stored) ? implode(PHP_EOL, $stored) : $stored;
        $value  = old($field . '.' . $code, $stored);
      @endphp
      <div class="field" style="margin:0">
        <label for="{{ $field }}-{{ $code }}"><span class="lang-tag">{{ $code }}</span>{{ $language }}</label>
        @if ($type === 'textarea')
          <textarea id="{{ $field }}-{{ $code }}" name="{{ $field }}[{{ $code }}]"
                    @if (! empty($rows)) rows="{{ $rows }}" @endif>{{ $value }}</textarea>
        @else
          <input id="{{ $field }}-{{ $code }}" name="{{ $field }}[{{ $code }}]" type="text" value="{{ $value }}">
        @endif
      </div>
    @endforeach
  </div>
</div>
