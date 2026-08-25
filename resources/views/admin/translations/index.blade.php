@extends('admin.layout')
@section('title', 'Translations')

@section('content')

  <div class="card">
    <p class="card__hint">
      The interface strings — nav, form labels, the booking flow's wording. Edited a group at a
      time, because nobody changes one label in isolation.
    </p>

    <div class="tabs" style="margin:0">
      @foreach ($groups as $entry)
        <a href="{{ admin_url() }}/translations?group={{ urlencode($entry->group) }}" @class(['is-here' => $group === $entry->group])>
          {{ $entry->group }} ({{ $entry->total }})
          @if (($missing[$entry->group] ?? 0) > 0)
            <span class="chip chip--warn" style="margin-left:4px">{{ $missing[$entry->group] }} empty</span>
          @endif
        </a>
      @endforeach
    </div>
  </div>

  @if ($rows->count())
    <form method="post" action="{{ admin_url() }}/translations">
      @csrf @method('put')
      <input type="hidden" name="group" value="{{ $group }}">

      <div class="card">
        <h2>{{ $group }}</h2>

        <div class="tw"><table>
          <thead>
            <tr>
              <th style="width:180px">Key</th>
              @foreach (config('kaiserzeit.locales') as $code => $language)
                <th>{{ $language }} <span class="dim">({{ $code }})</span></th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            @foreach ($rows as $row)
              <tr>
                <td class="mono dim" style="font-size:13px">{{ $row->key }}</td>
                @foreach (config('kaiserzeit.locales') as $code => $language)
                  @php $value = $row->values[$code] ?? ''; @endphp
                  <td>
                    @if (mb_strlen((string) $value) > 90)
                      <textarea name="values[{{ $row->key }}][{{ $code }}]" rows="3"
                                style="width:100%;padding:7px 9px;background:var(--ink);border:1px solid {{ blank($value) ? 'var(--warn)' : 'var(--line)' }};border-radius:5px;color:var(--cream);font:inherit;font-size:14px">{{ $value }}</textarea>
                    @else
                      <input type="text" name="values[{{ $row->key }}][{{ $code }}]" value="{{ $value }}"
                             style="width:100%;padding:7px 9px;background:var(--ink);border:1px solid {{ blank($value) ? 'var(--warn)' : 'var(--line)' }};border-radius:5px;color:var(--cream);font:inherit;font-size:14px">
                    @endif
                  </td>
                @endforeach
              </tr>
            @endforeach
          </tbody>
        </table></div>
      </div>

      <div class="ad__actions">
        <button class="btn btn--go" type="submit">Save {{ $group }}</button>
      </div>
    </form>
  @else
    <div class="card"><p class="empty">No strings in this group.</p></div>
  @endif

@endsection
