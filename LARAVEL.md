# The Laravel build

The preview used to be static files on Vercel with three serverless functions
behind them, storing everything in Vercel Blob. It is now a Laravel 12 app
storing everything in MySQL, so it runs on ordinary PHP hosting.

The seven `.dc.html` pages are byte for byte what they were, and the runtime
that renders them — `support.js` — is untouched. That was the constraint the
rest of the port was built around, and it is worth knowing why before changing
anything here.

`image-slot.js` and `editor.js` did change, in one respect: how editing is
unlocked. See below.

---

## Editing is a session, not a key

The Node build put an **Edit** button in the corner of every page. Anyone could
press it; it asked for a shared key, and the key was kept in `localStorage`.

That button is gone. So is the key prompt, and so is `?edit=1`.

Editing now follows an admin session: **sign in at `/login` and every page is
editable**, sign out and the site is a read-only preview with no editing chrome
on it at all. A visitor is never shown a control that asks them for something
they do not have.

How the runtime knows, before it has asked the server anything:

- The session cookie is `httpOnly`, so JavaScript cannot read it.
- On sign-in the server also sets **`kz_editor=1`**, unencrypted and readable.
  It carries one bit and no authority. `image-slot.js` reads it synchronously,
  because the image slots must decide whether to attach their drop targets at
  the moment they are built.
- `/api/state` then returns **`canEdit`**, which is the real answer. If the
  cookie said yes and the server says no — expired, or set by hand — the cookie
  is cleared and the page reloads once, guarded so it cannot loop.

Forging `kz_editor` gets you an editor whose every save the server refuses.

`KZ_EDIT_KEY` survives as an `x-kz-key` header for a script pushing content in
or a check from the command line. It is **optional**: leave it empty at install
time and that header stops being an entrance at all.

### Why /api/* is exempt from the CSRF token, and why that is safe

Those routes carry a session — they have to, since the session is what
authorises a write — but the pages that post to them are served byte for byte
from the design handoff and have nowhere to carry a token.

Every write uses a content-type a browser will not send cross-origin without a
preflight — `application/json`, or the image's own type — and nothing here
answers a preflight. `RequireEditor` adds the belt to those braces: a request
whose `Origin` is anywhere but this site is refused outright.

Signing out is an ordinary form POST and *does* carry a token, fetched from
`/api/state` at the moment of the click rather than at page load, so a tab left
open all afternoon still signs out cleanly.

---

## Two constraints that shape everything

**The pages are never rendered by Blade.** `support.js` re-fetches the page
source with `fetch(location.href)` and parses the template itself. The seven
pages carry 714 `{{ … }}` expressions belonging to that runtime; Blade would
evaluate them as PHP and swallow them. So the pages live in `resources/pages/`
and `PageController` sends them verbatim.

**Every page URL is one segment deep.** The runtime loads `responsive.css`,
`editor.js`, `notes.js` and `assets/*` by relative path, so they have to
resolve against the site root. `/Booking.dc.html` and `/booking` both work;
`/pages/booking` would break all four.

---

## What moved where

| Node build | Laravel build |
|---|---|
| `public/*.dc.html` | `resources/pages/*.dc.html` |
| `public/{support,image-slot,editor,notes}.js` | `public/` — unchanged |
| `public/assets/` | `public/assets/` |
| `api/state.js` | `app/Http/Controllers/StateController.php` |
| `api/content.js` | `app/Http/Controllers/ContentController.php` |
| `api/upload.js` | `app/Http/Controllers/UploadController.php` |
| `api/_store.js` — validation | `app/Support/ContentSanitizer.php` |
| `api/_store.js` — `authorize()` | `app/Http/Middleware/RequireEditor.php` |
| `api/_store.js` — `storageProblem()` | `app/Support/Installation.php` |
| `vercel.json` rewrites | `routes/web.php` |
| Blob `content.json` | `content_entries` + `notes` tables |
| Blob `slots/<id>.<ext>` | `storage/app/public/slots/` + `image_slots` table |
| shared `EDIT_KEY`, typed on the page | an admin account and a session |

The original Node source is kept under `_legacy-node/` for reference. Nothing
runs from there; delete it once the port has been signed off.

### Why notes got their own table

Four of the five groups in `content.json` were flat key → value maps, so they
share `content_entries` and are told apart by `group_name`. Notes carry a dozen
fields each and get queried on their own, so they have their own table. Replies
stay as a JSON column: they are only ever read and written with their parent
note, so a third table would buy nothing.

The Node build read, merged and rewrote one JSON document, which meant two
people editing at once could drop one change. Each key is now its own row
inside a transaction, so concurrent edits to different keys both land.

---

## The API

Unchanged paths, unchanged request and response shapes.

| | |
|---|---|
| `GET /api/state` | Public. Everything the client has changed, plus `canEdit` and `csrf`. Always answers 200 — an empty state means "use the design's own assets", which is the right thing to show while a database is still being connected. |
| `POST /api/content` | Needs an admin session. Merges `{texts, styles, notes, slots, theme}`; a `null` value deletes that override. |
| `POST /api/upload?slot=<id>` | Needs an admin session. Raw image body, `content-type` says the format. 12 MB ceiling. |

`ContentSanitizer` is a port of the validation in `api/content.js`, field for
field. It is not defensive tidying that can be dropped: style values are
interpolated straight into a stylesheet in the browser, so a saved value like
`red;} body{display:none` would become CSS for every later viewer.

---

## Setting it up

Open **`/install`** in a browser. Four steps: check the server, connect the
database, load the tables, create the admin account. It writes `.env` itself —
no file editing, no phpMyAdmin, no shell.

That account is the one that turns editing on, so it is the credential to hand
to whoever is allowed to change the preview.

Step 3 takes an optional **`.sql` file**. Use it to restore an existing preview:
the dump runs first, and anything it did not bring is created afterwards. If
the dump already contains the tables but not Laravel's record of them, the
migration ledger is marked up to date rather than re-running migrations that
would collide. A dump that contains an admin account skips the account half of
step 4 and only asks for the edit key.

The uploaded file is read from PHP's temporary folder and never saved inside
the site.

The installer locks itself once an admin account exists. The lock is keyed to
that, not to a marker file — a copy whose `.env` was edited by hand would
otherwise leave the wizard open, and anyone who found the URL could point it at
a database of their own. **Once the site is up, remove the two `/install`
routes from `routes/web.php`.**

### What the host needs

PHP 8.2+, `pdo_mysql`, `mbstring`, a writable `.env`, `storage` and
`bootstrap/cache`, and `post_max_size` of at least `12M` — the editor allows
12 MB photos, and PHP drops a larger body before Laravel sees it. Step 1 checks
all seven and names the fix for anything red.

`SESSION_DRIVER` and `CACHE_STORE` are set to `file`, not Laravel 12's default
of `database`. The installer runs before there is a database, so a
database-backed session cannot be what carries it.

### Uploaded photos

They are served from `/storage/slots/…`. `storage:link` is attempted at the end
of step 4; where symlinks are forbidden, the `/storage/slots/{file}` route
serves them through PHP instead. The URL is the same either way.

---

## Running it locally

```
php artisan serve --port=8123
```

Then `http://127.0.0.1:8123`. Sign in at `/login` and every page is editable;
there is no query string to add and no key to paste.

The Vite toolchain that ships with Laravel is unused: the design pages bring
their own CSS and the runtime is plain JavaScript. `npm install` is not needed
to run the site.

---

## Signing in

Three ways, in the order somebody actually meets them.

**Password.** Throttled five attempts per email and address together, so one
account being guessed at does not lock out everyone in the same office.

**A code from an authenticator app.** TOTP, RFC 6238, implemented in
`app/Support/TwoFactor.php` and checked against the RFC's own test vectors by
`tests/`. Enrolment is two steps: the secret is generated, and nothing is
switched on until a code from it verifies. A secret that failed to scan must
not be able to lock anybody out. The secret and the recovery codes are
encrypted at rest.

**A passkey — fingerprint, Windows Hello, Face ID.** All three are one
protocol; the browser decides which sensor to show.
`app/Support/WebAuthn.php` does the work: CBOR, COSE to PEM, and the signature
check, ported from the implementation already running on wpnazmul.com.

### The device rule

The passkey button appears **only on a browser that has registered one**, and
nowhere else — not greyed out, not present and failing: absent.

Registration sets `kz_device`, an httpOnly cookie holding a random token that
is also stored on the passkey row. The login page is rendered by the server,
so the server reads that cookie and decides whether to draw the button at all.
`/login/passkey/options` answers 404 to a browser it does not recognise, so a
stranger is not even told which credentials exist.

That cookie is a convenience, not a control. What actually refuses an
impostor is the signature: the private half never leaves the device and never
reaches this server. Clearing the cookie costs the button and nothing else —
sign in with the password and register again.

"Device" means a browser profile. Chrome and Firefox on one laptop hold
separate passkeys and register separately. That is how the platform works.

### Passkeys need a domain name

WebAuthn binds a key to a hostname, and browsers refuse an IP address. Serving
the site from `http://127.0.0.1:8123` produces a failure with no useful
message, so `WebAuthn::unavailableReason()` names it and the security screen
prints it. `localhost` is the one exception browsers make.

---

## The panel's own settings

Under **Configuration → Admin**:

| | |
|---|---|
| **Panel address** | Moves the panel off `/admin`. Not a lock — whoever finds it still meets the sign-in form — but it takes the panel out of the way of the scanners that try `/admin` on every site they find, which is most of what fills the failed sign-in log. Reserved paths are refused, the route cache is cleared, and every internal link follows because nothing hard-codes the path: they all go through `admin_url()`. |
| **Allow passkeys** | Off hides the button and refuses the endpoints. |
| **Require a second factor** | Staff with neither a passkey nor an authenticator app are sent to their own security screen until they set one up. The security screen and sign-out stay reachable, or it would be a trap. A passkey counts: it is a key held by the device, released by a fingerprint, face or PIN. |
| **Idle sign-out** | Minutes. |

Database credentials and the app key deliberately stay in `.env`, where
changing one is an act rather than a form submission.
