# KAISERZEIT im Himmelbett — clickable preview

A live, clickable version of the design handoff, so the client can walk through
all seven screens and fill in their own photos, wording and section
backgrounds. Everything they change is saved server-side and can be pulled
back into this repo as the final version to hand to the WordPress build.

This is **not** the production site. See [DESIGN-HANDOFF.md](DESIGN-HANDOFF.md)
for what the real build still needs (WordPress, a real booking integration,
i18n, payments, legal pages).

---

## What the client can do

Click **Bearbeiten** in the bottom-left corner of any page and enter the key.
The browser remembers it, so from then on the site is editable straight from
its normal public URL — no special link. "Bearbeiten beenden" in the same
panel signs out again. (`?edit=1` still works as a one-off.)

Anyone without the key sees an ordinary read-only site, so the same URL can be
shared for review.

Once signed in:

| | How |
|---|---|
| **Photos** | Drag an image onto any placeholder, or click it to browse |
| **Copy** | Double-click any text, type, press Enter |
| **Backgrounds** | Switch to *Hintergrund*, click a section, pick a colour or drop in an image |

`/admin` lists all 46 photo slots as a grid with a progress bar, which is the
quickest way to see what is still missing.

The key is the `EDIT_KEY` environment variable, and it is checked against the
server at sign-in rather than in the browser. Reading is public; every write
carries the key and is rejected without it.

---

## Deploying

1. Import the repo into Vercel.
2. Add a **Blob** store to the project (Storage → Create → Blob). This sets
   `BLOB_READ_WRITE_TOKEN` automatically.
3. Add an environment variable **`EDIT_KEY`** — any passphrase. Give it to the
   client; it is the only thing standing between the internet and the upload
   endpoint.
4. Deploy.

Three serverless functions are used (`state`, `upload`, `content`), well inside
the Hobby plan's twelve-function limit.

## Getting the client's work into the repo

```bash
powershell -ExecutionPolicy Bypass -File scripts\pull.ps1 -Site https://<your-preview>.vercel.app
```

This downloads every uploaded photo into `public/assets/uploads/` and writes a
`state.json` beside them with the copy and background changes, rewriting URLs
to local paths. Review, then commit. From that point the repo renders the
client's final version on its own with no backend — clone it, open it, and the
real photos are there.

PowerShell rather than Node, so it runs on a bare Windows machine; it only
does HTTP requests and file writes.

## Running it locally

```bash
npm install && npm run dev
```

Then open <http://localhost:8788/?edit=1> (key: `local`). This runs the real
`api/` handlers against a folder on disk instead of Vercel Blob, so uploading
photos, editing copy and setting backgrounds all work exactly as they do in
production — no Vercel account needed. Uploads land in `.local-store/`, which
is gitignored.

There is also `scripts/serve.ps1` for a files-only server with no `/api` at
all, which is what a fresh clone looks like.

---

## How it fits together

The seven `.dc.html` files are **exactly as delivered** — not one byte changed.
That keeps a redelivery from the designer a drop-in replacement. Everything
needed to run them lives alongside:

| File | Role |
|---|---|
| `public/*.dc.html` | The design prototypes, untouched |
| `public/support.js` | Runtime for the prototype format — the handoff referenced it but never shipped it, so it is reimplemented here |
| `public/image-slot.js` | `<image-slot>` element plus the shared store of client edits |
| `public/editor.js` | Copy and background editing; loaded only under `?edit=1` |
| `public/responsive.css` | Phone corrections the prototypes never specified |
| `public/admin.html` | Grid of all 46 photo slots |
| `api/` | `state` (read), `upload` (photos), `content` (copy + backgrounds) |
| `scripts/dev.mjs` | Local server running the real API against the filesystem |

`api/_store.js` keeps two storage backends behind one interface — Vercel Blob
in production, the filesystem when `KZ_LOCAL_STORE` is set — so validation,
merging and listing are the same code in both.

### Notes worth knowing

**The runtime was missing.** The bundle's README lists `support.js` and
`image-slot.js` under "Files", but neither was in the zip, so the pages
rendered as blank screens. `support.js` reimplements the format the pages were
authored against: `{{ }}` bindings, `<sc-if>`, `<sc-for>`, `<helmet>`, and a
`class Component extends DCLogic` with `state` / `setState` / `renderVals`.

**Templates are re-parsed from source.** `Booking.dc.html` nests a `<sc-for>`
inside a `<select>`, and the HTML parser throws away unknown elements in that
position — which would silently collapse the guest dropdown to one option.
The runtime refetches the page and rewrites the `sc-*` tags to `<template>`
(legal anywhere) before parsing.

**Renders are diffed, not replaced.** Re-rendering the whole tree would drop
focus and the caret every time the client typed a character into the booking
form. The patcher reuses nodes, so typing behaves normally.

**Edits are keyed to survive re-editing.** Copy overrides are keyed on
`page | language | original wording`, so an edit stays attached to its slot no
matter how many times it is changed, and German edits never leak into English.
Backgrounds are keyed on a `data-kz-el` number assigned to the whole template
up front — including branches that are not currently visible, so a hidden
`<sc-if>` opening cannot renumber everything after it.

### Limits

- Repeated wording on a page shares one key, so editing "Verfügbarkeit prüfen"
  in one place changes every instance of it on that page. Usually what you
  want; occasionally not.
- Two people editing simultaneously can lose one change — the copy and
  background overrides are one JSON document with read-modify-write.
- Both A/B demos from the handoff are deliberately left in for the client to
  choose between: the hero booking results (dropdown vs popup) and the
  apartments layout (2 grouped cards vs 4 individual). See "Open Decision" in
  DESIGN-HANDOFF.md.
- Availability, payment and sign-in are still the prototype's simulations.
