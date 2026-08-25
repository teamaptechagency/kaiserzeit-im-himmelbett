{{--
    The installer's only screen. Which of the six panels it shows comes from
    $step, $locked and $finished.

    Kept as one file for the same reason the Node build kept one: an installer
    is read once, by someone who is stuck, and jumping between six partials to
    follow what a step does helps nobody.
--}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $finished ? 'Ready' : 'Set up' }} — KAISERZEIT im Himmelbett</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=EB+Garamond:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/install.css?v=1">
</head>
<body>
<main class="ob">

  <div class="ob__brand">
    <span class="ob__mark">KZ</span>
    <span>KAISERZEIT im Himmelbett<small>Guided setup</small></span>
  </div>

  @unless ($locked || $finished)
    <ol class="ob__rail">
      @foreach ($stepNames as $number => $name)
        <li class="ob__rail-item @if ($number === $step) is-now @elseif ($number < $step) is-done @endif">
          <span class="ob__rail-dot">{{ $number < $step ? '✓' : $number }}</span>{{ $name }}
        </li>
      @endforeach
    </ol>
  @endunless

  @if ($problems || $errors->any())
    <div class="ob__alert">
      @foreach ($problems as $problem)
        <p>{{ $problem }}</p>
      @endforeach
      @foreach ($errors->all() as $message)
        <p>{{ $message }}</p>
      @endforeach
    </div>
  @endif

  @if ($notice)
    <div class="ob__notice"><p>{{ $notice }}</p></div>
  @endif

  {{-- ------------------------------------------------------------ locked --}}
  @if ($locked && ! $finished)

    <div class="ob__card">
      <span class="ob__eyebrow ob__eyebrow--lock">Locked</span>
      <h1>This site is already set up</h1>
      <p class="ob__lead">
        The installer will not run again while an admin account exists — otherwise
        anyone who found this URL could point your site at their own database.
      </p>
      <div class="ob__actions">
        <a class="ob__btn ob__btn--go" href="/">View the site</a>
        <a class="ob__btn ob__btn--ghost" href="/admin">Admin</a>
      </div>
      <p class="ob__foot">
        Nothing on the site needs the installer any more. Removing the
        <code>/install</code> route is the belt to this braces.
      </p>
    </div>

  {{-- -------------------------------------------------------------- done --}}
  @elseif ($finished)

    <div class="ob__card">
      <span class="ob__eyebrow">Done</span>
      <h1>Your preview is live</h1>
      <p class="ob__lead">
        The database is connected, the tables are in place and your admin account is ready.
        Sign in and every page becomes editable; signed out, the site is a read-only preview.
      </p>

      @if ($editKey)
        <div class="ob__key">
          <p class="ob__key-label">The API key, for scripts sending <code>x-kz-key</code></p>
          <code class="ob__key-value">{{ $editKey }}</code>
          <p class="ob__key-note">
            Shown once. Stored in <code>.env</code> as <code>KZ_EDIT_KEY</code>, so it can be
            read back from there — but copy it now. Nobody needs it to edit in a browser.
          </p>
        </div>
      @endif

      <div class="ob__actions">
        <a class="ob__btn ob__btn--go" href="/login">Sign in and start editing</a>
        <a class="ob__btn ob__btn--ghost" href="/">Open the preview</a>
      </div>
    </div>

  {{-- ------------------------------------------------------------ step 1 --}}
  @elseif ($step === 1)

    @php $blockers = array_filter($requirements, fn ($r) => ! $r['ok']); @endphp

    <div class="ob__card">
      <span class="ob__eyebrow">Step 1 of 4 · Server</span>
      <h1>Can this server run the site?</h1>
      <p class="ob__lead">Checked live. Anything red has to be fixed on the host before the rest will work.</p>

      <ul class="ob__steps">
        @foreach ($requirements as $requirement)
          <li class="ob__step is-{{ $requirement['ok'] ? 'ok' : 'warn' }}">
            <span class="ob__dot">{{ $requirement['ok'] ? '✓' : '!' }}</span>
            <div>
              <h2>{{ $requirement['title'] }}</h2>
              <p>{{ $requirement['ok'] ? 'Fine.' : $requirement['fix'] }}</p>
            </div>
            <span class="ob__note">{{ $requirement['note'] }}</span>
          </li>
        @endforeach
      </ul>

      <div class="ob__actions">
        @if ($blockers)
          <span class="ob__btn ob__btn--dead">Fix the above, then reload</span>
        @else
          <a class="ob__btn ob__btn--go" href="/install?step=2">Continue</a>
        @endif
      </div>
    </div>

  {{-- ------------------------------------------------------------ step 2 --}}
  @elseif ($step === 2)

    <div class="ob__card">
      <span class="ob__eyebrow">Step 2 of 4 · Database</span>
      <h1>Connect the database</h1>
      <p class="ob__lead">
        Create an empty database and a user for it in your hosting panel first, then put
        those details here. They are written to <code>.env</code>, never shown again.
      </p>

      <form method="post" action="/install?step=2">
        @csrf

        <div class="ob__row">
          <div class="ob__field">
            <label for="db_host">Host</label>
            <input id="db_host" name="db_host" value="{{ old('db_host', $saved['host'] ?? 'localhost') }}" required>
            <small>Almost always localhost.</small>
          </div>
          <div class="ob__field ob__field--narrow">
            <label for="db_port">Port</label>
            <input id="db_port" name="db_port" value="{{ old('db_port', $saved['port'] ?? 3306) }}" required>
          </div>
        </div>

        <div class="ob__field">
          <label for="db_name">Database name</label>
          <input id="db_name" name="db_name" value="{{ old('db_name', $saved['database'] ?? '') }}" required>
        </div>

        <div class="ob__row">
          <div class="ob__field">
            <label for="db_user">Username</label>
            <input id="db_user" name="db_user" value="{{ old('db_user', $saved['username'] ?? '') }}" required>
          </div>
          <div class="ob__field">
            <label for="db_pass">Password</label>
            <input id="db_pass" name="db_pass" type="password" value="{{ old('db_pass', $saved['password'] ?? '') }}">
          </div>
        </div>

        <div class="ob__field">
          <label for="app_url">Site address</label>
          <input id="app_url" name="app_url" value="{{ old('app_url', $appUrlGuess) }}" required>
          <small>Where the preview will be reached. Used for links in the app.</small>
        </div>

        <div class="ob__actions">
          <button class="ob__btn ob__btn--go" type="submit">Test and continue</button>
          <a class="ob__btn ob__btn--ghost" href="/install?step=1">Back</a>
        </div>
      </form>
    </div>

  {{-- ------------------------------------------------------------ step 3 --}}
  @elseif ($step === 3)

    <div class="ob__card">
      <span class="ob__eyebrow">Step 3 of 4 · Content</span>
      <h1>Load the tables</h1>
      <p class="ob__lead">
        This creates the four tables the preview reads from: copy overrides, section
        backgrounds and photo framing, pinned comments, and the photo index.
      </p>

      <form method="post" action="/install?step=3" enctype="multipart/form-data">
        @csrf

        <div class="ob__import">
          <h2>Import a database file</h2>
          <p>
            Optional. Pick a <code>.sql</code> file to restore an existing preview — its copy,
            comments and photo index. It runs before the tables are created, and anything the
            file did not bring is created afterwards.
          </p>
          <input id="sql_file" name="sql_file" type="file" accept=".sql,application/sql,text/plain">
          <small>
            The file is read from PHP's temporary folder and never saved inside the site.
            Current limit: {{ ini_get('upload_max_filesize') ?: 'unknown' }}.
          </small>
        </div>

        <div class="ob__actions">
          <button class="ob__btn ob__btn--go" type="submit">Create the tables</button>
          <a class="ob__btn ob__btn--ghost" href="/install?step=2">Back</a>
        </div>
      </form>
    </div>

  {{-- ------------------------------------------------------------ step 4 --}}
  @else

    <div class="ob__card">
      <span class="ob__eyebrow">Step 4 of 4 · Admin</span>

      @if ($adminExists)
        <h1>Set the edit key</h1>
        <p class="ob__lead">
          The file you imported already contains an admin account, so there is nothing to
          create — only the key the client edits with. After this the installer locks itself.
        </p>
      @else
        <h1>Create your admin account</h1>
        <p class="ob__lead">The only account the installer makes. After this it locks itself.</p>
      @endif

      <form method="post" action="/install?step=4" autocomplete="off">
        @csrf

        @unless ($adminExists)
        <div class="ob__row">
          <div class="ob__field">
            <label for="name">Your name</label>
            <input id="name" name="name" value="{{ old('name') }}" required>
          </div>
          <div class="ob__field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required>
            <small>This is the login.</small>
          </div>
        </div>

        <div class="ob__row">
          <div class="ob__field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required>
            <small>At least 10 characters.</small>
          </div>
          <div class="ob__field">
            <label for="password_confirmation">Repeat it</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required>
          </div>
        </div>
        @endunless

        <div class="ob__field">
          <label for="edit_key">API key <span style="opacity:.6">— optional</span></label>
          <input id="edit_key" name="edit_key" value="{{ old('edit_key') }}" placeholder="Leave empty unless you need it">
          <small>
            Not how anyone signs in: the account above is. This is for a script pushing content
            in through <code>x-kz-key</code>, or a check from the command line. Leave it empty
            and the header stops being a way in at all.
          </small>
        </div>

        <div class="ob__actions">
          <button class="ob__btn ob__btn--go" type="submit">Finish</button>
        </div>
      </form>
    </div>

  @endif

  <p class="ob__foot">KAISERZEIT im Himmelbett · Laravel {{ app()->version() }} · PHP {{ PHP_VERSION }}</p>
</main>
</body>
</html>
