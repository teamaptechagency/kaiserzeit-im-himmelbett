@extends('admin.layout')
@section('title', 'Activity')

@section('content')

  <div class="card">
    <p class="card__hint">
      Every change made through this panel. Only the fields that actually differed are recorded —
      passwords and two-factor secrets are never written here, whatever changed.
    </p>

    <form method="get" class="row" style="align-items:flex-end;margin-bottom:6px">
      <div class="field" style="margin:0">
        <label for="user">Who</label>
        <select id="user" name="user">
          <option value="">Anyone</option>
          @foreach ($people as $person)
            <option value="{{ $person->id }}" @selected((string) $filters['user'] === (string) $person->id)>{{ $person->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="field" style="margin:0">
        <label for="action">Did what</label>
        <select id="action" name="action">
          <option value="">Anything</option>
          @foreach ($actions as $action)
            <option value="{{ $action }}" @selected($filters['action'] === $action)>{{ $action }}</option>
          @endforeach
        </select>
      </div>
      <div class="field" style="margin:0">
        <label for="subject">To what</label>
        <select id="subject" name="subject">
          <option value="">Anything</option>
          @foreach ($subjects as $subject)
            <option value="{{ $subject }}" @selected($filters['subject'] === $subject)>{{ class_basename($subject) }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <button class="btn" type="submit">Filter</button>
        <a class="btn btn--sm" href="{{ admin_url() }}/activity">Clear</a>
      </div>
    </form>
  </div>

  <div class="card">
    @if ($logs->count())
      <div class="tw"><table>
        <thead><tr><th>When</th><th>Who</th><th>What</th><th>Changed</th></tr></thead>
        <tbody>
          @foreach ($logs as $entry)
            <tr>
              <td class="dim" style="white-space:nowrap">
                {{ $entry->created_at?->format('j M H:i') }}
                <div style="font-size:12px">{{ $entry->created_at?->diffForHumans(short: true) }}</div>
              </td>
              <td>
                {{ $entry->user_label }}
                @unless ($entry->user)<div class="dim" style="font-size:12px">account since deleted</div>@endunless
              </td>
              <td>
                {{ $entry->description ?: $entry->action }}
                <div class="dim" style="font-size:12px">
                  <span class="chip">{{ $entry->action }}</span>
                  @if ($entry->subject_type) {{ $entry->subjectName() }}: {{ $entry->subject_label }} @endif
                </div>
              </td>
              <td>
                @if ($entry->changes)
                  <ul class="diff">
                    @foreach ($entry->changes as $field => $change)
                      <li>
                        <span class="dim">{{ $field }}</span>
                        <del>{{ Str::limit(is_scalar($change['from'] ?? null) ? (string) $change['from'] : json_encode($change['from'] ?? null, JSON_UNESCAPED_UNICODE), 45) }}</del>
                        <ins>{{ Str::limit(is_scalar($change['to'] ?? null) ? (string) $change['to'] : json_encode($change['to'] ?? null, JSON_UNESCAPED_UNICODE), 45) }}</ins>
                      </li>
                    @endforeach
                  </ul>
                @else
                  <span class="dim">—</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table></div>

      <div class="pager">{{ $logs->links() }}<span class="dim">{{ number_format($logs->total()) }} entries</span></div>
    @else
      <p class="empty">Nothing matches.</p>
    @endif
  </div>

@endsection
