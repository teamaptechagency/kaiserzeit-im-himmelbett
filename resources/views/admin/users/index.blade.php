@extends('admin.layout')
@section('title', 'Users')

@section('actions')
  <a class="btn btn--go" href="{{ admin_url() }}/users/create">Add account</a>
@endsection

@section('content')

  <div class="tabs">
    <a href="{{ admin_url() }}/users?role=staff"  @class(['is-here' => $role === 'staff'])>Staff ({{ $tally['staff'] }})</a>
    <a href="{{ admin_url() }}/users?role=guests" @class(['is-here' => $role === 'guests'])>Guests ({{ $tally['guests'] }})</a>
    <a href="{{ admin_url() }}/users?role=all"    @class(['is-here' => $role === 'all'])>Everyone</a>
  </div>

  <div class="card">
    <form method="get" class="row" style="align-items:flex-end;margin-bottom:14px">
      <input type="hidden" name="role" value="{{ $role }}">
      <div class="field" style="margin:0">
        <label for="q">Search</label>
        <input id="q" name="q" type="text" value="{{ $search }}" placeholder="Name or email">
      </div>
      <div><button class="btn" type="submit">Find</button></div>
    </form>

    @if ($users->count())
      <div class="tw"><table>
        <thead>
          <tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>2FA</th><th class="num">Bookings</th><th>Last seen</th><th></th></tr>
        </thead>
        <tbody>
          @foreach ($users as $user)
            <tr>
              <td>
                <a href="{{ admin_url() }}/users/{{ $user->id }}/edit">{{ $user->name }}</a>
                @unless ($user->is_active)<span class="chip chip--bad">deactivated</span>@endunless
              </td>
              <td class="dim">{{ $user->username }}</td>
              <td class="dim">{{ $user->email }}</td>
              <td><span class="chip {{ $user->isStaff() ? 'chip--gold' : '' }}">{{ $user->roleLabel() }}</span></td>
              <td>
                @if ($user->hasTwoFactor())<span class="chip chip--ok">on</span>
                @elseif ($user->isStaff())<span class="chip chip--warn">off</span>
                @else <span class="dim">—</span>@endif
              </td>
              <td class="num dim">{{ $user->bookings_count }}</td>
              <td class="dim">{{ $user->last_login_at?->diffForHumans(short: true) ?? 'never' }}</td>
              <td class="num"><a class="btn btn--sm" href="{{ admin_url() }}/users/{{ $user->id }}/edit">Edit</a></td>
            </tr>
          @endforeach
        </tbody>
      </table></div>

      <div class="pager">{{ $users->links() }}<span class="dim">{{ $users->total() }} account(s)</span></div>
    @else
      <p class="empty">No accounts match.</p>
    @endif
  </div>

@endsection
