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
import { authorize, json, readContent, writeContent } from "./_store.js";

export const config = { runtime: "edge" };

const MAX_KEYS = 40;
const MAX_TEXT = 5000;

export default async function handler(request) {
  if (request.method !== "POST") return json({ error: "POST only" }, 405);

  const denied = authorize(request);
  if (denied) return json({ error: denied }, 401);

  let patch;
  try {
    patch = await request.json();
  } catch {
    return json({ error: "body must be JSON" }, 400);
  }
  if (!patch || typeof patch !== "object") return json({ error: "body must be an object" }, 400);

  const texts = patch.texts && typeof patch.texts === "object" ? patch.texts : {};
  const styles = patch.styles && typeof patch.styles === "object" ? patch.styles : {};
  if (Object.keys(texts).length + Object.keys(styles).length > MAX_KEYS) {
    return json({ error: "too many keys in one request" }, 400);
  }
  for (const value of Object.values(texts)) {
    if (value !== null && (typeof value !== "string" || value.length > MAX_TEXT)) {
      return json({ error: "text values must be strings under 5000 characters" }, 400);
    }
  }

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
  return json({ ok: true, texts: content.texts, styles: content.styles });
}
