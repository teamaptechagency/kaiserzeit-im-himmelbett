@extends('admin.layout')
@section('title', $user->exists ? 'Edit account' : 'New account')

@section('content')

  <form method="post" action="{{ $user->exists ? admin_url("/users/{$user->id}") : admin_url('/users') }}">
    @csrf
    @if ($user->exists) @method('put') @endif

    <div class="grid grid--2">

      <div class="card">
        <h2>Account</h2>

        <div class="row">
          <div class="field">
            <label for="name">Name</label>
            <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required>
          </div>
          <div class="field">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" value="{{ old('username', $user->username) }}"
                   autocapitalize="none" spellcheck="false" required>
            <small>Either this or the email signs them in.</small>
          </div>
        </div>

        <div class="field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required>
          <small>This is the sign-in.</small>
        </div>

        <div class="row">
          <div class="field">
            <label for="password">{{ $user->exists ? 'New password' : 'Password' }}</label>
            <input id="password" name="password" type="password" autocomplete="new-password" @required(! $user->exists)>
            <small>{{ $user->exists ? 'Leave empty to keep the current one.' : 'At least 10 characters.' }}</small>
          </div>
          <div class="field">
            <label for="password_confirmation">Repeat it</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
          </div>
        </div>
      </div>

      <div class="card">
        <h2>Access</h2>

        <div class="field">
          <label for="role">Role</label>
          <select id="role" name="role" @disabled(! auth()->user()->isAdmin())>
            @foreach (\App\Models\User::ROLES as $value => $label)
              <option value="{{ $value }}" @selected(old('role', $user->role) === $value)>{{ $label }}</option>
            @endforeach
          </select>
          <small>
            @if (auth()->user()->isAdmin())
              Editors reach the panel and the site editor. Guests reach neither — only their own bookings.
            @else
              Only an administrator can change roles.
            @endif
          </small>
        </div>

        <label class="check">
          <input type="hidden" name="is_active" value="0">
          <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active ?? true))>
          <span><b>Active</b><small>A deactivated account is signed out at its next click and cannot sign back in.</small></span>
        </label>

        <div class="row">
          <div class="field">
            <label for="phone">Mobile</label>
            <input id="phone" name="phone" type="text" value="{{ old('phone', $user->phone) }}">
          </div>
          <div class="field">
            <label for="whatsapp">WhatsApp</label>
            <input id="whatsapp" name="whatsapp" type="text" value="{{ old('whatsapp', $user->whatsapp) }}">
          </div>
          <div class="field">
            <label for="locale">Language</label>
            <select id="locale" name="locale">
              @foreach (config('kaiserzeit.locales') as $code => $label)
                <option value="{{ $code }}" @selected(old('locale', $user->locale ?? 'de') === $code)>{{ $label }}</option>
              @endforeach
            </select>
          </div>
        </div>

        @if ($user->exists)
          <p class="card__hint" style="margin-top:6px">
            Two-factor: {!! $user->hasTwoFactor()
              ? '<span class="chip chip--ok">on</span>'
              : '<span class="chip chip--warn">off</span>' !!}
            — only the account holder can set it up, from their own security screen.
          </p>
        @endif
      </div>

    </div>

    <div class="ad__actions">
      <button class="btn btn--go" type="submit">{{ $user->exists ? 'Save' : 'Create account' }}</button>
      <a class="btn" href="{{ admin_url() }}/users">Cancel</a>
    </div>
  </form>

  @if ($user->exists && auth()->id() !== $user->id)
    <form method="post" action="{{ admin_url() }}/users/{{ $user->id }}" style="margin-top:20px"
          onsubmit="return confirm('Delete {{ $user->email }}? Their activity record stays.')">
      @csrf @method('delete')
      <button class="btn btn--bad btn--sm" type="submit">Delete this account</button>
    </form>
  @endif

  @if ($user->exists && ($activity ?? collect())->count())
    <div class="card" style="margin-top:20px">
      <h2>What they have done</h2>
      <div class="tw"><table>
        <tbody>
          @foreach ($activity as $entry)
            <tr>
              <td>{{ $entry->description ?: $entry->action }}</td>
              <td class="num dim">{{ $entry->created_at?->diffForHumans(short: true) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table></div>
    </div>
  @endif

@endsection
