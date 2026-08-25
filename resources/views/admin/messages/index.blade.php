@extends('admin.layout')
@section('title', 'Messages')

@section('content')

  <div class="tabs">
    <a href="{{ admin_url() }}/messages?show=open" @class(['is-here' => $show === 'open'])>Open ({{ $tally['open'] }})</a>
    <a href="{{ admin_url() }}/messages?show=done" @class(['is-here' => $show === 'done'])>Handled ({{ $tally['done'] }})</a>
    <a href="{{ admin_url() }}/messages?show=all"  @class(['is-here' => $show === 'all'])>All</a>
  </div>

  <div class="card">
    @if ($messages->count())
      <div class="tw"><table>
        <thead><tr><th>From</th><th>Message</th><th>When</th><th></th></tr></thead>
        <tbody>
          @foreach ($messages as $message)
            <tr>
              <td>
                <a href="{{ admin_url() }}/messages/{{ $message->id }}">{{ $message->name }}</a>
                @unless ($message->read_at)<span class="chip chip--warn">unread</span>@endunless
                <div class="dim" style="font-size:12px">{{ $message->email }}</div>
              </td>
              <td>
                @if ($message->subject)<strong>{{ $message->subject }}</strong><br>@endif
                {{ Str::limit($message->message, 100) }}
              </td>
              <td class="dim" style="white-space:nowrap">{{ $message->created_at?->diffForHumans(short: true) }}</td>
              <td class="num">
                <form method="post" action="{{ admin_url() }}/messages/{{ $message->id }}" class="inline">
                  @csrf @method('put')
                  <input type="hidden" name="is_handled" value="{{ $message->is_handled ? 0 : 1 }}">
                  <button class="btn btn--sm" type="submit">{{ $message->is_handled ? 'Reopen' : 'Mark handled' }}</button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table></div>

      <div class="pager">{{ $messages->links() }}<span class="dim">{{ $messages->total() }} message(s)</span></div>
    @else
      <p class="empty">Nothing here.</p>
    @endif
  </div>

@endsection
