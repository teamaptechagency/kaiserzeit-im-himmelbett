@extends('admin.layout')
@section('title', 'Message from ' . $message->name)

@section('content')

  <div class="card">
    <div class="tw"><table>
      <tbody>
        <tr><th>From</th><td>{{ $message->name }} &lt;<a href="mailto:{{ $message->email }}">{{ $message->email }}</a>&gt;</td></tr>
        @if ($message->subject)<tr><th>Subject</th><td>{{ $message->subject }}</td></tr>@endif
        <tr><th>Language</th><td>{{ strtoupper($message->locale) }}</td></tr>
        <tr><th>Sent</th><td>{{ $message->created_at?->format('l j F Y, H:i') }}</td></tr>
        <tr><th>Status</th><td>
          <span class="chip chip--{{ $message->is_handled ? 'ok' : 'warn' }}">{{ $message->is_handled ? 'handled' : 'open' }}</span>
        </td></tr>
      </tbody>
    </table></div>
  </div>

  <div class="card">
    <h2>What they wrote</h2>
    <p style="white-space:pre-wrap;margin:0">{{ $message->message }}</p>
  </div>

  <div class="ad__actions">
    <a class="btn btn--go" href="mailto:{{ $message->email }}?subject={{ rawurlencode('Re: ' . ($message->subject ?: 'Ihre Nachricht')) }}">Reply by email</a>

    <form method="post" action="{{ admin_url() }}/messages/{{ $message->id }}" class="inline">
      @csrf @method('put')
      <input type="hidden" name="is_handled" value="{{ $message->is_handled ? 0 : 1 }}">
      <button class="btn" type="submit">{{ $message->is_handled ? 'Reopen' : 'Mark handled' }}</button>
    </form>

    <a class="btn" href="{{ admin_url() }}/messages">Back</a>

    <form method="post" action="{{ admin_url() }}/messages/{{ $message->id }}" class="inline"
          onsubmit="return confirm('Delete this message for good?')">
      @csrf @method('delete')
      <button class="btn btn--bad" type="submit">Delete</button>
    </form>
  </div>

@endsection
