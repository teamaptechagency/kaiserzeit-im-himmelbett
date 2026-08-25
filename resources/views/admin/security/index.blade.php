@extends('admin.layout')
@section('title', 'Security')

@section('content')

  {{-- ------------------------------------------------------- passkeys --- --}}
  <div class="card">
    <h2>Fingerprint, Windows Hello and Face ID</h2>
    <p class="card__hint">
      A passkey lives on this device and is released by your fingerprint, face or PIN. The private
      half never leaves the machine and never reaches this server, so a stolen database holds
      nothing that can sign in. It counts as both factors at once.
    </p>

    @if ($passkeyProblem)
      <p><span class="chip chip--warn">Not available here</span></p>
      <p class="card__hint">{{ $passkeyProblem }}</p>
    @elseif (! $passkeysAllowed)
      <p><span class="chip chip--warn">Switched off for this site</span>
        — turn it on in <a href="{{ admin_url('/settings') }}">Configuration</a>.</p>
    @else
      @if ($passkeys->count())
        <div class="tw"><table>
          <thead><tr><th>Device</th><th>Browser</th><th>Added</th><th>Last used</th><th></th></tr></thead>
          <tbody>
            @foreach ($passkeys as $key)
              <tr>
                <td>
                  {{ $key->displayLabel() }}
                  @if ($key->device_token === $thisDevice)<span class="chip chip--ok">this browser</span>@endif
                </td>
                <td class="dim">{{ $key->device_label ?: '—' }}</td>
                <td class="dim">{{ $key->created_at?->format('j M Y') }}</td>
                <td class="dim">{{ $key->last_used_at?->diffForHumans(short: true) ?? 'never' }}</td>
                <td class="num">
                  <form method="post" action="/passkey/{{ $key->id }}" class="inline"
                        onsubmit="return confirm('Remove this passkey? That device will need the password again.')">
                    @csrf @method('delete')
                    <button class="btn btn--sm btn--bad" type="submit">Remove</button>
                  </form>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table></div>
      @else
        <p class="empty">No passkeys yet.</p>
      @endif

      @if ($registeredHere)
        <p class="card__hint" style="margin-top:14px">
          <span class="chip chip--ok">This browser is registered</span>
          The sign-in screen offers the sensor here. On any other browser the button does not
          appear at all.
        </p>
      @else
        <div class="row" style="align-items:flex-end;margin-top:14px">
          <div class="field" style="margin:0">
            <label for="kz-passkey-label">Name this device</label>
            <input id="kz-passkey-label" type="text" maxlength="80" placeholder="Office laptop">
            <small>So you can tell it apart from the others in this list.</small>
          </div>
          <div>
            <button class="btn btn--go" type="button" id="kz-passkey-register">Register this device</button>
          </div>
        </div>
      @endif

      <p class="card__hint" id="kz-passkey-note" hidden style="margin-top:12px"></p>

      <p class="card__hint" style="margin-top:14px">
        A note on the word "device": this is per browser. Chrome and Firefox on one laptop hold
        separate passkeys and register separately — that is how the platform works, not a
        shortcut taken here.
      </p>
    @endif
  </div>

  <div class="grid grid--2">

    {{-- --------------------------------------------------- two-factor --- --}}
    <div class="card">
      <h2>Two-factor authentication</h2>

      @if ($user->hasTwoFactor())
        <p class="card__hint">
          <span class="chip chip--ok">On since {{ $user->two_factor_confirmed_at->format('j M Y') }}</span>
        </p>
        <p class="card__hint">
          Signing in asks for a six-digit code from your authenticator app. A stolen password
          on its own no longer gets anybody in.
        </p>

        @if ($recoveryCodes)
          <p class="card__hint" style="color:var(--warn)">
            Recovery codes — each works once, for the day the phone is lost. Save them somewhere
            that is not the phone.
          </p>
          <ol class="codes">
            @foreach ($recoveryCodes as $code)<li>{{ $code }}</li>@endforeach
          </ol>
        @else
          <p class="card__hint">
            {{ count($user->two_factor_recovery_codes ?? []) }} unused recovery code(s) remain.
          </p>
        @endif

        <form method="post" action="{{ admin_url() }}/security/2fa/codes" class="inline">
          @csrf
          <button class="btn btn--sm" type="submit">New recovery codes</button>
        </form>

        <hr style="border:0;border-top:1px solid var(--line);margin:18px 0">

        <form method="post" action="{{ admin_url() }}/security/2fa/disable">
          @csrf
          <div class="field">
            <label for="off-current">Turn it off — confirm with your password</label>
            <input id="off-current" name="current" type="password" autocomplete="current-password" required>
            <small>Asked for, so that an unlocked screen is not enough to remove it.</small>
          </div>
          <button class="btn btn--bad btn--sm" type="submit">Turn two-factor off</button>
        </form>

      @elseif ($pendingSecret)
        <p class="card__hint">
          Add this to your authenticator app, then type the code it shows. Nothing is switched on
          until that code checks out — a secret that failed to scan must not be able to lock you out.
        </p>

        <p class="card__hint">Type this in by hand:</p>
        <code class="key">{{ \App\Support\TwoFactor::readable($pendingSecret) }}</code>

        <p class="card__hint" style="margin-top:12px">
          Or open this link on the phone:<br>
          <a href="{{ $pendingUri }}" class="mono" style="word-break:break-all">{{ Str::limit($pendingUri, 70) }}</a>
        </p>

        @if ($recoveryCodes)
          <p class="card__hint" style="margin-top:14px;color:var(--warn)">Save these recovery codes first:</p>
          <ol class="codes">
            @foreach ($recoveryCodes as $code)<li>{{ $code }}</li>@endforeach
          </ol>
        @endif

        <form method="post" action="{{ admin_url() }}/security/2fa/confirm" style="margin-top:16px">
          @csrf
          <div class="field">
            <label for="code">The six digits the app is showing</label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
          </div>
          <button class="btn btn--go" type="submit">Turn two-factor on</button>
        </form>

      @else
        <p class="card__hint">
          <span class="chip chip--warn">Off</span>
        </p>
        <p class="card__hint">
          This account can be reached with a password alone. Two-factor adds a code from your
          phone, so a password that leaks somewhere else is not enough on its own.
        </p>
        <form method="post" action="{{ admin_url() }}/security/2fa/start">
          @csrf
          <button class="btn btn--go" type="submit">Set up two-factor</button>
        </form>
      @endif
    </div>

    {{-- ----------------------------------------------------- password --- --}}
    <div class="card">
      <h2>Password</h2>
      <p class="card__hint">
        Changing it signs out every other browser this account is open in.
      </p>

      <form method="post" action="{{ admin_url() }}/security/password">
        @csrf
        <div class="field">
          <label for="current">Current password</label>
          <input id="current" name="current" type="password" autocomplete="current-password" required>
        </div>
        <div class="row">
          <div class="field">
            <label for="password">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required>
            <small>At least 10 characters.</small>
          </div>
          <div class="field">
            <label for="password_confirmation">Repeat it</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
          </div>
        </div>
        <button class="btn btn--go" type="submit">Change password</button>
      </form>

      <hr style="border:0;border-top:1px solid var(--line);margin:20px 0">

      <h2>Signed in elsewhere</h2>
      @if (config('session.driver') === 'database')
        @if (count($sessions))
          <div class="tw"><table>
            <thead><tr><th>Where</th><th>Last seen</th></tr></thead>
            <tbody>
              @foreach ($sessions as $session)
                <tr>
                  <td>{{ Str::limit($session['agent'], 44) }} @if ($session['current'])<span class="chip chip--ok">this one</span>@endif</td>
                  <td class="dim">{{ $session['last']->diffForHumans() }}</td>
                </tr>
              @endforeach
            </tbody>
          </table></div>
        @else
          <p class="empty">Only this browser.</p>
        @endif
      @else
        <p class="card__hint">
          Sessions are stored as files on this server, which cannot be listed per account.
          Signing out everywhere else still works.
        </p>
      @endif

      <form method="post" action="{{ admin_url() }}/security/sessions" style="margin-top:10px">
        @csrf
        <div class="field">
          <label for="sess-current">Confirm with your password</label>
          <input id="sess-current" name="current" type="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn--sm" type="submit">Sign out everywhere else</button>
      </form>
    </div>

  </div>

  {{-- ------------------------------------------------------- attempts --- --}}
  <div class="card">
    <h2>Sign-in attempts</h2>
    <p class="card__hint">
      {{ $failedToday }} failed in the last 24 hours. Addresses are hashed, not stored — counting
      attempts from one source does not need to know where it is.
    </p>

    @if ($suspicious->count())
      <div class="ad__flash ad__flash--bad">
        @foreach ($suspicious as $row)
          <p>One source: {{ $row->attempts }} failures against {{ $row->accounts }} account(s) in 24 hours.
            @if ($row->accounts > 1) That is somebody working through a list. @endif</p>
        @endforeach
      </div>
    @endif

    @if ($attempts->count())
      <div class="tw"><table>
        <thead><tr><th>When</th><th>Account</th><th>Result</th><th>Browser</th></tr></thead>
        <tbody>
          @foreach ($attempts as $attempt)
            <tr>
              <td class="dim">{{ $attempt->created_at?->diffForHumans(short: true) }}</td>
              <td>{{ $attempt->email }}</td>
              <td>
                @if ($attempt->successful)
                  <span class="chip chip--ok">signed in</span>
                @else
                  <span class="chip chip--bad">{{ $attempt->reason ?? 'failed' }}</span>
                @endif
              </td>
              <td class="dim">{{ Str::limit($attempt->user_agent, 40) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table></div>
    @else
      <p class="empty">Nothing recorded yet.</p>
    @endif
  </div>

@endsection
