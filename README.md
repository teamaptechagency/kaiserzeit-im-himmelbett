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

Open the preview with `?edit=1` — for example
`https://<your-preview>.vercel.app/?edit=1` — and a toolbar appears at the
bottom:

| | How |
|---|---|
| **Photos** | Drag an image onto any placeholder, or click it to browse |
| **Copy** | Double-click any text, type, press Enter |
| **Backgrounds** | Switch to *Hintergrund*, click a section, pick a colour or drop in an image |

`/admin` lists all 46 photo slots as a grid with a progress bar, which is the
quickest way to see what is still missing.

Without `?edit=1` the site is read-only — that is the link to share for
review.

Editing asks once for a key, which is the `EDIT_KEY` environment variable.
Reading is public; only writing needs the key.

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

PowerShell is used because Node is not installed on the machine this was built
on; the script only does HTTP requests and file writes.

## Running it locally

```bash
powershell -ExecutionPolicy Bypass -File scripts\serve.ps1 -Port 8787
```

Then open <http://localhost:8787/>. This serves `public/` only and knows
nothing about `/api/*`, so the pages fall back to whatever is in
`public/assets/uploads/state.json`. Editing needs a real deployment.

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
