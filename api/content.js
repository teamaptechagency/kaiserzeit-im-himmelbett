/*
 * POST /api/content — merge copy and background overrides.
 *
 * Body: { texts?: { key: string|null }, styles?: { key: object|null } }
 * A null value deletes the override and restores the design's original.
 *
 * Read-modify-write on one JSON document. The browser serialises its saves,
 * and only the client edits, so concurrent writers are not a real concern
 * here; two people editing at once could drop one change.
 */
import { authorize, readBody, readContent, send, writeContent } from "./_store.js";

const MAX_KEYS = 40;
const MAX_TEXT = 5000;

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

  const texts = patch.texts && typeof patch.texts === "object" ? patch.texts : {};
  const styles = patch.styles && typeof patch.styles === "object" ? patch.styles : {};

  /* An empty patch is the sign-in check: it has already passed authorize(),
     so answering here lets the login button verify a key without a fourth
     function and without a pointless write. */
  if (!Object.keys(texts).length && !Object.keys(styles).length) {
    return send(res, 200, { ok: true, verified: true });
  }

  if (Object.keys(texts).length + Object.keys(styles).length > MAX_KEYS) {
    return send(res, 400, { error: "too many keys in one request" });
  }
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
      if (value === null || !value || Object.keys(value).length === 0) delete content.styles[key];
      else content.styles[key] = value;
    }

    await writeContent(content);
    send(res, 200, { ok: true, texts: content.texts, styles: content.styles });
  } catch (error) {
    console.error("content save failed", error);
    send(res, 500, { error: String(error && error.message ? error.message : error) });
  }
}
