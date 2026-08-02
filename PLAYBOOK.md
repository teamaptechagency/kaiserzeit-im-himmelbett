# Playbook: turning a static site into a client-editable preview

How this project was built, written so the same shape can be dropped onto any
other static site — a design handoff, a landing page, a marketing site.

The goal is always the same: **the client fills in their own photos, wording
and colours on a real URL, and everything they do comes back into your repo as
the final version.** No CMS, no database, no logins to hand out.

---

## The shape

```
public/            the site, byte for byte as delivered — you do not edit these
  runtime.js       whatever the pages need to run (may be nothing)
  store.js         loads the override state; defines the shared t() dictionary
  editor.js        text + background editing        } loaded only in edit mode
  notes.js         pinned comments                  }
  overrides.css    responsive corrections you must not put in the design files
api/
  state.js         GET  — everything the client has changed        (public)
  upload.js        POST — one photo                                (key)
  content.js       POST — merge text / style / note overrides      (key)
  _store.js        storage behind one interface: blob or filesystem
scripts/
  dev.mjs          serves public/ and routes /api/* to the real handlers
  pull.ps1         downloads everything back into the repo
```

Three serverless functions, one blob store, no database. That fits every free
tier and there is nothing to administer.

---

## The one rule that makes it work

**Never edit the delivered files.** Everything is added alongside.

The moment you start editing the design's HTML, a redelivery from the designer
becomes a merge, and the client's changes become entangled with markup. Keep
the pages untouched and hold every client change as an *override* applied at
runtime. Then a new drop from the designer is a file copy.

This one constraint drives most of the decisions below.

---

## The override store

One JSON document plus a folder of uploads. Four kinds:

| Kind | Key | Value |
|---|---|---|
| `images` | slot id | uploaded photo URL |
| `texts` | `page \| lang \| original wording` | replacement wording |
| `styles` | `page \| css selector` | `{color, image, overlay, strength, photo}` |
| `notes` | generated id | `{page, el, x, y, author, text, replies, resolved}` |

Everything is keyed, never positional, so two people changing different things
merge instead of overwriting.

### Key text on the original wording, not on a path

The obvious key is a DOM path or a binding expression. Both break: paths move
when a section is added, expressions repeat inside loops.

Key on `page | language | the original string` instead. Then:

- re-editing the same slot overwrites rather than accumulating
- the same wording repeated in a desktop nav and a mobile menu changes together
- a German edit cannot leak into English, because the language is in the key
- an edit survives the designer reordering the page

Always compute the key from the *template's* value, never from what is
currently displayed, or the second edit writes a new key.

### Give every element a stable identity

Backgrounds and comment pins need to name an element. Assign a number to
**every element in the template up front — including branches that are not
currently rendered** — and emit it as `data-kz-el`.

If you number lazily as you render, a hidden conditional block opening will
renumber everything after it, and yesterday's background lands on today's
footer.

---

## Applying overrides without fighting the page

### Backgrounds: inject a stylesheet, do not write inline styles

Inline styles belong to the page. If the page re-renders — or you are diffing
a template — anything you wrote there is wiped. Build a `<style>` element
instead:

```
[data-kz-el="12"] { background-color: #3a2a1a !important; }
```

Zero interaction with the page's own rendering.

### Overlays: a background layer, not an element on top

The obvious way to darken a section is an absolutely positioned overlay. It
also darkens the headline.

Use a gradient layer inside `background-image`, which paints behind text:

```css
background-image:
  linear-gradient(rgba(0,0,0,.5), rgba(0,0,0,.5)),   /* tint, on top */
  url(photo.jpg);                                     /* photo, below */
```

Layers paint front to back, so the tint is listed first.

This cannot reach a photo that is a *child element* rather than a CSS
background. For those, expose an opacity control on the element itself, and
only show it where such a child exists.

### Text: diff, never re-render

If the page re-renders on every change, a client typing into a form loses
focus and caret on each keystroke. Patch the existing DOM: reuse nodes,
and write `element.value` only when it actually differs.

---

## Comments

Anchor a pin to **an element plus a fraction of its box** — never to page
coordinates:

```js
{ el: "61", x: 0.75, y: 0.5 }
```

Page coordinates drift the moment a photo loads at a different height or the
window resizes. Element-relative anchoring survives both: a pin placed on a
headline at 1500px wide is still on it at 375px.

Position them in *document* coordinates in one absolutely positioned layer, so
scrolling needs no recalculation. Recompute on resize, on re-render, and after
images load.

---

## Access

One shared key in an environment variable. Not accounts, not OAuth.

- **Reading is public.** The preview must render for anyone with the link.
- **Every write carries the key** and is rejected without it.
- Verify the key **against the server** at sign-in, not in the browser, so a
  wrong key fails at the prompt rather than at the first upload. An empty
  patch to the content endpoint is a free way to do this — no extra function.
- Remember it in `localStorage` so the site is editable from its normal public
  URL. A `?edit=1` link works too but should not be the only way in.
- Put the sign-in button somewhere unobtrusive; if the design already has an
  edit affordance, use that one rather than adding a second.

## Validate anything replayed to other viewers

Client text and style values are stored and served to everyone who opens the
link afterwards. Pin the shape server-side:

- colours must match a hex pattern
- enums must be in a known set
- numbers clamped to a range
- URLs restricted to your own uploads
- unknown fields dropped, not passed through

Otherwise a stored value like `red;} body{display:none} .x{` becomes CSS for
every later visitor. The key-holder is trusted, but a typo should not be able
to break the site permanently either.

---

## Editing ergonomics that are not obvious

- **Make links inert in edit mode.** Link wording is editable text, so a
  single click drags the client to another page mid-edit. Swallow clicks on
  links and add a page picker to the toolbar instead. Never rewrite `href`.
- **Sliders apply live, save on release.** Otherwise a drag is fifty requests.
- **Show the count of open comments** on the toolbar so nothing is missed.
- **Localise the tools too.** If the site has a language switch, the toolbar,
  panels and buttons must follow it — hold labels as dictionary keys and
  re-resolve them on a language-change event.

---

## Getting the work back

A script that reads `/api/state`, downloads every upload into
`public/assets/uploads/`, rewrites URLs to local paths, and writes the whole
state next to them.

After that the repo renders the client's final version **with no backend at
all** — clone it, open it, the real photos are there. Test this explicitly by
serving the folder with the API returning 404. It is the deliverable you hand
to the production build.

---

## Applying it to a new site

1. Copy the site into `public/`. Do not touch it.
2. Decide what is replaceable. If the markup has no hooks, add identity at
   runtime (`data-kz-el` on every element) rather than editing the HTML.
3. Stand up the three endpoints and the storage interface.
4. Write `dev.mjs` first — the real handlers against a local folder. Being
   able to run the whole thing without a deployment pays for itself in an
   hour.
5. Add editing one mode at a time: photos, then text, then backgrounds, then
   comments. Each is independent.
6. Write the pull script, and verify the no-backend render.
7. Only then deploy.

---

## Traps hit while building this

Each of these cost real time. Check them early on the next one.

**The runtime may be missing.** A handoff can list files it does not ship. The
seven pages here rendered as blank screens because the framework they were
authored against was not in the bundle. Open every page in a browser before
quoting the work.

**HTML parsers silently drop unknown elements in some positions.** A custom
`<sc-for>` inside a `<select>` is discarded — the guest dropdown collapsed to
one option with no error anywhere. If you are parsing templates, re-parse from
source and rewrite custom tags to `<template>`, which is legal everywhere. Use
`DOMParser`; its documents are inert, so no images load and no inline handlers
compile.

**Re-rendering destroys form state.** Diff and patch.

**`repeat(auto-fit, minmax(380px, 1fr))` overflows a 375px phone.** The floor
is a hard minimum. `minmax(min(380px, 100%), 1fr)` fixes every such grid at
once and changes nothing on desktop.

**Edge runtimes cannot use Node built-ins.** `node:fs` is unavailable, and
storage SDKs often pull in `undici`, which needs `net`/`tls`/`zlib`. For
binary uploads and blob I/O there is no benefit to the edge anyway — use the
Node runtime.

**Environment variables need a redeploy.** Adding one does not affect the
running deployment. This looks exactly like a broken feature.

**Windows PowerShell 5.1 assumes ISO-8859-1** when a response has no charset,
so `Jörg` comes back as `JÃ¶rg`. Send `charset=utf-8` *and* decode the raw
bytes yourself. This corrupts every accented string, quietly.

**`requestAnimationFrame` does not fire in a hidden tab.** Anything that
schedules renders through it will appear frozen in a background tab or an
automated test. Expected behaviour, confusing symptom.

**Bulk renames across a file are dangerous.** A rename of `t.body` also
rewrote `document.body`; a rename of `T.save` matched inside `T.saved`. Both
produced valid syntax and a silently missing button. After any sweeping
rename, run the file — a headless smoke test that just executes the module
catches what a syntax check cannot.
