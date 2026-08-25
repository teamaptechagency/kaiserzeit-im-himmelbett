<?php

namespace App\Http\Controllers;

use App\Support\Installation;
use App\Support\SqlFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDO;
use PDOException;
use Throwable;

/**
 * The installer.
 *
 * Four steps: check the server, connect the database, load the tables, make
 * an admin and an edit key. It writes .env itself, so a fresh copy of this
 * app can be put on any host and set up from the browser — no editing files,
 * no phpMyAdmin import, and no shell.
 *
 * Every step re-checks the state of the world rather than trusting the step
 * before it, because people bookmark URLs, press back, and refresh.
 *
 * Steps 3 and 4 run against a connection built from what step 2 saved, not
 * from config: the framework's config was resolved when the request started,
 * before .env had anything useful in it.
 *
 * It locks itself once an admin exists.
 */
class InstallController extends Controller
{
    private const TABLES = ['content_entries', 'notes', 'image_slots', 'users'];

    public function show(Request $request)
    {
        return view('install.index', $this->viewData($this->step($request), $request));
    }

    public function submit(Request $request): RedirectResponse
    {
        if (Installation::isLocked()) {
            return redirect('/install');
        }

        return match ($this->step($request)) {
            2       => $this->connectDatabase($request),
            3       => $this->loadTables($request),
            4       => $this->createAdmin($request),
            default => redirect('/install?step=2'),
        };
    }

    /* ------------------------------------------------------------ step 1 */

    /** What the server can do. Nothing here touches the database. */
    private function requirements(): array
    {
        $env     = base_path('.env');
        $storage = storage_path('app');
        $cache   = base_path('bootstrap/cache');

        return [
            [
                'title' => 'PHP 8.2 or newer',
                'ok'    => version_compare(PHP_VERSION, '8.2.0', '>='),
                'note'  => PHP_VERSION,
                'fix'   => 'Switch the PHP version in your hosting panel. Laravel 12 needs 8.2.',
            ],
            [
                'title' => 'MySQL driver (pdo_mysql)',
                'ok'    => extension_loaded('pdo_mysql'),
                'note'  => extension_loaded('pdo_mysql') ? 'loaded' : 'missing',
                'fix'   => 'Enable the pdo_mysql extension for your PHP version.',
            ],
            [
                'title' => 'mbstring',
                'ok'    => extension_loaded('mbstring'),
                'note'  => extension_loaded('mbstring') ? 'loaded' : 'missing',
                'fix'   => 'Needed to measure the German copy correctly when it is saved.',
            ],
            [
                'title' => '.env is writable',
                'ok'    => is_file($env) ? is_writable($env) : is_writable(base_path()),
                'note'  => is_file($env) ? (is_writable($env) ? 'writable' : 'read only') : 'will be created',
                'fix'   => 'Give the application folder write permission. The installer writes .env into it.',
            ],
            [
                'title' => 'storage folder is writable',
                'ok'    => is_dir($storage) && is_writable($storage),
                'note'  => is_dir($storage) ? (is_writable($storage) ? 'writable' : 'read only') : 'missing',
                'fix'   => 'Set storage to 755 (some hosts need 775), or photos cannot be uploaded.',
            ],
            [
                'title' => 'bootstrap/cache is writable',
                'ok'    => is_dir($cache) && is_writable($cache),
                'note'  => is_dir($cache) ? (is_writable($cache) ? 'writable' : 'read only') : 'missing',
                'fix'   => 'Set bootstrap/cache to 755. Laravel writes its compiled config there.',
            ],
            [
                'title' => 'Upload limit is 12 MB or more',
                'ok'    => $this->bytes(ini_get('post_max_size')) >= 12 * 1024 * 1024,
                'note'  => 'post_max_size ' . (ini_get('post_max_size') ?: 'unknown'),
                'fix'   => 'The editor allows photos up to 12 MB. Raise post_max_size and '
                         . 'upload_max_filesize to at least 12M, or larger photos arrive empty.',
            ],
        ];
    }

    /* ------------------------------------------------------------ step 2 */

    /** Connects, and on success writes .env and moves on. */
    private function connectDatabase(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'db_host' => ['required', 'string', 'max:190'],
            'db_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'db_name' => ['required', 'string', 'max:190'],
            'db_user' => ['required', 'string', 'max:190'],
            'db_pass' => ['nullable', 'string', 'max:190'],
            'app_url' => ['required', 'string', 'max:190', 'regex:#^https?://#i'],
        ], [
            'app_url.regex' => 'The site address should start with http:// or https://',
        ]);

        $credentials = [
            'host'     => trim($data['db_host']),
            'port'     => (int) $data['db_port'],
            'database' => trim($data['db_name']),
            'username' => trim($data['db_user']),
            'password' => (string) ($data['db_pass'] ?? ''),
            'app_url'  => rtrim(trim($data['app_url']), '/'),
        ];

        $problem = $this->connectionProblem($credentials);
        if ($problem !== null) {
            return $this->back(2, [$problem]);
        }

        $written = $this->writeEnv([
            'APP_URL'       => $credentials['app_url'],
            'DB_CONNECTION' => 'mysql',
            'DB_HOST'       => $credentials['host'],
            'DB_PORT'       => (string) $credentials['port'],
            'DB_DATABASE'   => $credentials['database'],
            'DB_USERNAME'   => $credentials['username'],
            'DB_PASSWORD'   => $credentials['password'],
        ]);

        if ($written !== null) {
            return $this->back(2, [$written]);
        }

        $request->session()->put('install', $credentials);

        return redirect('/install?step=3');
    }

    /* ------------------------------------------------------------ step 3 */

    /**
     * Loads the schema, and optionally imports a .sql file first.
     *
     * A dump is run before the migrations because it usually brings its own
     * tables. When it does, the migration ledger is marked up to date rather
     * than re-running migrations that would collide with what the dump just
     * created. When the dump was data only, or when there was no dump at all,
     * the migrations run normally.
     */
    private function loadTables(Request $request): RedirectResponse
    {
        $pdo = $this->savedConnection($request);
        if ($pdo === null) {
            return redirect('/install?step=2');
        }

        $notices = [];

        if ($request->hasFile('sql_file')) {
            $file = $request->file('sql_file');

            if (! $file->isValid()) {
                return $this->back(3, ['The upload did not complete: ' . $file->getErrorMessage()]);
            }

            if (strtolower((string) $file->getClientOriginalExtension()) !== 'sql') {
                return $this->back(3, ['That is not a .sql file.']);
            }

            $result = SqlFile::run($pdo, $file->getRealPath());

            if ($result['error'] !== null) {
                return $this->back(3, [
                    'The import stopped at statement ' . ($result['ran'] + 1) . ' of '
                    . $result['total'] . ': ' . $result['error'],
                ]);
            }

            $notices[] = 'Imported ' . $result['ran'] . ' statements from ' . $file->getClientOriginalName() . '.';
        }

        $this->useInstallerConnection($request);

        try {
            if ($this->tablesPresent($pdo) && ! $this->hasLedger($pdo)) {
                /* The dump brought the schema but not Laravel's record of it.
                   Writing that record now keeps a later `php artisan migrate`
                   from trying to create tables that already exist. */
                Artisan::call('migrate:install', ['--database' => 'installer']);
                $this->recordMigrations($pdo);
                $notices[] = 'The uploaded file already contained the tables, so the migrations were '
                           . 'marked as applied rather than run again.';
            } else {
                Artisan::call('migrate', ['--database' => 'installer', '--force' => true]);
            }
        } catch (Throwable $error) {
            return $this->back(3, ['Creating the tables failed: ' . $error->getMessage()]);
        }

        $missing = array_values(array_filter(
            self::TABLES,
            fn (string $table) => ! $this->tableExists($pdo, $table),
        ));

        if ($missing !== []) {
            return $this->back(3, ['These tables are still missing: ' . implode(', ', $missing)]);
        }

        return redirect('/install?step=4')->with('install_notice', implode(' ', $notices));
    }

    /* ------------------------------------------------------------ step 4 */

    private function createAdmin(Request $request): RedirectResponse
    {
        $pdo = $this->savedConnection($request);
        if ($pdo === null) {
            return redirect('/install?step=2');
        }

        /* A .sql file restored in step 3 brings its own accounts. Asking for
           another one would fail on the unique email, and refusing to go on
           would strand the restore with no edit key — so the account half of
           this step is simply skipped. */
        $restored = $this->adminCount($pdo) > 0;

        $rules = ['edit_key' => ['nullable', 'string', 'min:8', 'max:120']];

        if (! $restored) {
            $rules += [
                'name'     => ['required', 'string', 'max:120'],
                'email'    => ['required', 'email', 'max:190'],
                'password' => ['required', 'string', 'min:10', 'confirmed'],
            ];
        }

        $data = $request->validate($rules, [
            'password.min'       => 'Use at least 10 characters. Longer beats clever.',
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        /* Not how anyone signs in — that is the account above. This is the
           x-kz-key header, for a script pushing content in or a check from
           the command line. Left empty, the header stops being an entrance at
           all, which is the right default: one fewer way in than there are
           people who need one. */
        $editKey = trim((string) ($data['edit_key'] ?? ''));

        if (! $restored) {
            try {
                $this->useInstallerConnection($request);

                DB::connection('installer')->table('users')->insert([
                    'name'       => $data['name'],
                    'email'      => $data['email'],
                    'password'   => Hash::make($data['password']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (Throwable $error) {
                return $this->back(4, ['The account could not be created: ' . $error->getMessage()]);
            }
        }

        $written = $this->writeEnv(['KZ_EDIT_KEY' => $editKey]);
        if ($written !== null) {
            return $this->back(4, [$written]);
        }

        /* Photos are served from storage/app/public. The symlink is the fast
           path; without it the /storage/slots route serves them through PHP,
           so a host that forbids symlinks is not a dead end. */
        try {
            Artisan::call('storage:link');
        } catch (Throwable $error) {
            report($error);
        }

        $request->session()->forget('install');

        return redirect('/install?done=1')->with('install_edit_key', $editKey);
    }

    /* ------------------------------------------------------------ pieces */

    private function step(Request $request): int
    {
        return max(1, min(4, (int) $request->query('step', 1)));
    }

    private function back(int $step, array $problems): RedirectResponse
    {
        return redirect('/install?step=' . $step)
            ->withInput()
            ->with('install_errors', $problems);
    }

    /** Opens a connection with the given details, or says why it could not. */
    private function connectionProblem(array $c): ?string
    {
        try {
            $this->pdo($c);
            return null;
        } catch (PDOException $e) {
            $message = $e->getMessage();

            /* The driver's own wording is unhelpful to anyone who is not a
               DBA, so the common three get translated. Order matters: MySQL
               reports a database the user cannot reach as "Access denied ...
               to database 'x'", which is a different problem from a bad
               password and needs a different fix. */
            if (str_contains($message, 'Unknown database') || str_contains($message, 'to database')) {
                return 'Could not open a database called "' . $c['database'] . '". Either it does not '
                     . 'exist yet, or this user has not been added to it. Both are done in your '
                     . 'hosting panel.';
            }
            if (str_contains($message, 'Access denied')) {
                return 'The database refused that username or password.';
            }
            if (str_contains($message, 'getaddrinfo')
                || str_contains($message, 'Connection refused')
                || str_contains($message, 'onnect to MySQL')
                || str_contains($message, 'onnect to server')) {
                return 'Nothing answered at "' . $c['host'] . ':' . $c['port'] . '". On most shared '
                     . 'hosting this should be localhost.';
            }
            return $message;
        }
    }

    private function pdo(array $c): PDO
    {
        $dsn = 'mysql:host=' . $c['host'] . ';port=' . $c['port']
             . ';dbname=' . $c['database'] . ';charset=utf8mb4';

        return new PDO($dsn, $c['username'], $c['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    /** The connection step 2 saved, or null if the session has gone. */
    private function savedConnection(Request $request): ?PDO
    {
        $saved = $request->session()->get('install');
        if (! is_array($saved) || empty($saved['database'])) {
            return null;
        }

        try {
            return $this->pdo($saved);
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Points Laravel's own database layer at what step 2 saved, so Artisan
     * and Eloquent reach the same database this request has been using.
     */
    private function useInstallerConnection(Request $request): void
    {
        $c = (array) $request->session()->get('install');

        config([
            'database.connections.installer' => [
                'driver'    => 'mysql',
                'host'      => $c['host'] ?? '127.0.0.1',
                'port'      => $c['port'] ?? 3306,
                'database'  => $c['database'] ?? '',
                'username'  => $c['username'] ?? '',
                'password'  => $c['password'] ?? '',
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
                'strict'    => true,
                'engine'    => null,
            ],
        ]);

        DB::purge('installer');
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        try {
            return (bool) $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
        } catch (PDOException $e) {
            return false;
        }
    }

    private function tablesPresent(PDO $pdo): bool
    {
        foreach (self::TABLES as $table) {
            if (! $this->tableExists($pdo, $table)) {
                return false;
            }
        }

        return true;
    }

    private function hasLedger(PDO $pdo): bool
    {
        if (! $this->tableExists($pdo, 'migrations')) {
            return false;
        }

        try {
            return (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /** Writes every migration file into the ledger as one batch. */
    private function recordMigrations(PDO $pdo): void
    {
        $known = [];
        foreach ($pdo->query('SELECT migration FROM migrations')->fetchAll() as $row) {
            $known[$row['migration']] = true;
        }

        $insert = $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, 1)');

        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            $name = basename($file, '.php');
            if (! isset($known[$name])) {
                $insert->execute([$name]);
            }
        }
    }

    private function adminCount(PDO $pdo): int
    {
        if (! $this->tableExists($pdo, 'users')) {
            return 0;
        }

        try {
            return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Adds or replaces keys in .env.
     *
     * The value is written double-quoted with its quotes, dollars and
     * backslashes escaped, so a database password containing a space or a
     * hash cannot silently truncate the line — which would take the whole app
     * down on the next request.
     */
    private function writeEnv(array $values): ?string
    {
        $path = base_path('.env');

        if (! is_file($path) && is_file(base_path('.env.example'))) {
            @copy(base_path('.env.example'), $path);
        }

        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        $backslash = chr(92);
        $search    = [$backslash, '"', '$'];
        $replace   = [$backslash . $backslash, $backslash . '"', $backslash . '$'];

        foreach ($values as $key => $value) {
            $line    = $key . '="' . str_replace($search, $replace, (string) $value) . '"';
            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

            $contents = preg_match($pattern, $contents) === 1
                ? preg_replace($pattern, $line, $contents)
                : rtrim($contents, "\n") . "\n" . $line . "\n";
        }

        if (@file_put_contents($path, $contents) === false) {
            return 'Could not write .env. Give the application folder write permission and try again.';
        }

        /* A cached config would keep serving the old values. */
        try {
            Artisan::call('config:clear');
        } catch (Throwable $e) {
            @unlink(base_path('bootstrap/cache/config.php'));
        }

        return null;
    }

    /** "12M" and friends, as a number of bytes. */
    private function bytes(string|false $size): int
    {
        if (! $size) {
            return 0;
        }

        $value = (int) $size;

        return match (strtolower(substr(trim($size), -1))) {
            'g'     => $value * 1024 * 1024 * 1024,
            'm'     => $value * 1024 * 1024,
            'k'     => $value * 1024,
            default => $value,
        };
    }

    private function viewData(int $step, Request $request): array
    {
        $saved = (array) $request->session()->get('install');

        /* Only meaningful on step 4, but resolved here so the view stays free
           of database calls. */
        $pdo = $step === 4 ? $this->savedConnection($request) : null;

        return [
            'adminExists'  => $pdo !== null && $this->adminCount($pdo) > 0,
            'step'         => $step,
            'locked'       => Installation::isLocked(),
            'finished'     => $request->query('done') !== null,
            'requirements' => $this->requirements(),
            'saved'        => $saved,
            'appUrlGuess'  => $saved['app_url']
                ?? rtrim($request->getSchemeAndHttpHost() . $request->getBaseUrl(), '/'),
            'editKey'      => $request->session()->get('install_edit_key'),
            'notice'       => $request->session()->get('install_notice'),
            'problems'     => (array) $request->session()->get('install_errors', []),
            'stepNames'    => [1 => 'Server', 2 => 'Database', 3 => 'Content', 4 => 'Admin'],
        ];
    }
}
