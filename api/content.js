/*
 * POST /api/content — merge copy, background and comment overrides.
 *
 * Body: { texts?, styles?, notes? } where each is { key: value|null }.
 * A null value deletes that entry and restores the design's original.
 *
 * Read-modify-write on one JSON document. The browser serialises its saves,
 * so a single person editing never races; two people editing at the same
 * moment could drop one change. Notes are keyed by their own id, so two
 * people commenting on different spots merge cleanly.
 */
import {
  authorize, readBody, readContent, send, storageProblem, writeContent
} from "./_store.js";

const MAX_KEYS = 40;
const MAX_TEXT = 5000;
const MAX_NOTE = 2000;
const MAX_NOTES = 500;
const MAX_REPLIES = 60;

/* Style values are interpolated straight into a stylesheet in the browser, so
   they are pinned to an exact shape here. Without this, a saved value like
   "red;} body{display:none" would be injected as CSS for every later viewer. */
function cleanStyle(value) {
  if (!value || typeof value !== "object") return null;

  const out = {};
  if (typeof value.color === "string" && /^#[0-9a-f]{3,8}$/i.test(value.color.trim())) {
    out.color = value.color.trim();
  }
  if (typeof value.image === "string") {
    const url = value.image.trim();
    /* Uploads only: an absolute https URL or a path inside the site. */
    if (url === "" || /^https:\/\/[\w.-]+\/[\w./%-]*(\?[\w=&.%-]*)?$/i.test(url) ||
        /^[\w./-]+$/.test(url)) {
      out.image = url;
    }
  }
  if (["dark", "light", "custom"].includes(value.overlay)) out.overlay = value.overlay;
  if (value.text === "light" || value.text === "dark") out.text = value.text;
  if (typeof value.overlayColor === "string" && /^#[0-9a-f]{3,8}$/i.test(value.overlayColor.trim())) {
    out.overlayColor = value.overlayColor.trim();
  }
  if (value.strength != null) out.strength = Math.min(0.95, Math.max(0, Number(value.strength) || 0));
  if (value.photo != null) out.photo = Math.min(1, Math.max(0, Number(value.photo) || 0));

  return Object.keys(out).length ? out : null;
}

/* How a photo sits in its slot: whether it fills or fits, how far it is
   zoomed, and which edge is kept when it is cropped. */
function cleanSlot(value) {
  if (!value || typeof value !== "object") return null;

  const out = {};
  if (value.fit === "cover" || value.fit === "contain") out.fit = value.fit;
  if (["top", "center", "bottom"].includes(value.position)) out.position = value.position;
  if (value.zoom != null) out.zoom = Math.min(3, Math.max(1, Number(value.zoom) || 1));

  return Object.keys(out).length ? out : null;
}

/* Notes are the one place a client types free-form content that is stored
   and replayed to other viewers, so the shape is pinned down here rather
   than trusted from the browser. */
function cleanNote(value) {
  if (!value || typeof value !== "object") return null;

  const text = typeof value.text === "string" ? value.text.slice(0, MAX_NOTE) : "";
  const author = typeof value.author === "string" ? value.author.slice(0, 80) : "";
  if (!text.trim()) return null;

  const replies = Array.isArray(value.replies)
    ? value.replies.slice(0, MAX_REPLIES).map((reply) => ({
        author: typeof reply.author === "string" ? reply.author.slice(0, 80) : "",
        text: typeof reply.text === "string" ? reply.text.slice(0, MAX_NOTE) : "",
        at: Number(reply.at) || 0
      })).filter((reply) => reply.text.trim())
    : [];

  return {
    page: typeof value.page === "string" ? value.page.slice(0, 120) : "",
    el: typeof value.el === "string" ? value.el.slice(0, 40) : "root",
    x: Math.min(1, Math.max(0, Number(value.x) || 0)),
    y: Math.min(1, Math.max(0, Number(value.y) || 0)),
    author,
    text,
    at: Number(value.at) || 0,
    resolved: !!value.resolved,
    replies
  };
}

export default async function handler(req, res) {
  if (req.method !== "POST") return send(res, 405, { error: "POST only" });

  const denied = authorize(req);
  if (denied) return send(res, 401, { error: denied });

  let patch;
  try {
    patch = JSON.parse((await readBody(req)).toString("utf8"));
  } catch {
    return send(res, 400, { error: "body must be JSON" });
  }
  if (!patch || typeof patch !== "object") {
    return send(res, 400, { error: "body must be an object" });
  }

  const pick = (name) =>
    patch[name] && typeof patch[name] === "object" ? patch[name] : {};
  const texts = pick("texts");
  const styles = pick("styles");
  const notes = pick("notes");
  const slots = pick("slots");
  const theme = pick("theme");

  /* Site-wide, so it is a single object rather than a keyed map. */
  const FONT_SETS = ["original", "modern", "classic"];

  /* An empty patch is the sign-in check: it has already passed authorize(),
     so answering here lets the login button verify a key without a fourth
     function and without a pointless write. */
  const total = Object.keys(texts).length + Object.keys(styles).length +
                Object.keys(notes).length + Object.keys(slots).length +
                Object.keys(theme).length;
  /* The sign-in probe is answered before storage, so a key can still be
     verified while the store is being connected. */
  if (!total) return send(res, 200, { ok: true, verified: true, storage: storageProblem() });

  const problem = storageProblem();
  if (problem) return send(res, 503, { error: problem });
  if (total > MAX_KEYS) return send(res, 400, { error: "too many keys in one request" });

  for (const value of Object.values(texts)) {
    if (value !== null && (typeof value !== "string" || value.length > MAX_TEXT)) {
      return send(res, 400, { error: "text values must be strings under 5000 characters" });
    }
  }

  try {
    const content = await readContent();

    for (const [key, value] of Object.entries(texts)) {
      if (value === null) delete content.texts[key];
      else content.texts[key] = value;
    }
    for (const [key, value] of Object.entries(styles)) {
      /* An empty object means "back to the design default", so drop the key
         rather than emitting a rule with no declarations. */
      const clean = cleanStyle(value);
      if (!clean) delete content.styles[key];
      else content.styles[key] = clean;
    }
    for (const [key, value] of Object.entries(notes)) {
      if (value === null) {
        delete content.notes[key];
        continue;
      }
      const note = cleanNote(value);
      if (!note) return send(res, 400, { error: "a note needs text" });
      if (!(key in content.notes) && Object.keys(content.notes).length >= MAX_NOTES) {
        return send(res, 400, { error: "note limit reached" });
      }
      content.notes[key] = note;
    }
    for (const [key, value] of Object.entries(slots)) {
      const slot = cleanSlot(value);
      if (!slot) delete content.slots[key];
      else content.slots[key] = slot;
    }
    if ("fonts" in theme) {
      if (FONT_SETS.includes(theme.fonts) && theme.fonts !== "original") {
        content.theme.fonts = theme.fonts;
      } else {
        delete content.theme.fonts;   /* "original" is the design's own */
      }
    }
    /* Corner rounding, in three groups. null puts a group back to the
       design's own values rather than storing a number that happens to
       match. */
    for (const key of ["radiusButton", "radiusCard", "radiusField"]) {
      if (!(key in theme)) continue;
      const value = theme[key];
      if (value === null || value === "") delete content.theme[key];
      else content.theme[key] = Math.min(40, Math.max(0, Number(value) || 0));
    }

    await writeContent(content);
    send(res, 200, {
      ok: true, texts: content.texts, styles: content.styles,
      notes: content.notes, slots: content.slots, theme: content.theme
    });
  } catch (error) {
    console.error("content save failed", error);
    send(res, 500, { error: String(error && error.message ? error.message : error) });
  }
}
