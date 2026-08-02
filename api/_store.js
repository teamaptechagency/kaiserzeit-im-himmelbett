/*
 * Shared blob helpers.
 *
 * Photos live at slots/<slotId>.<ext> with a fixed pathname, so the slot id
 * alone identifies a photo and no index has to be maintained. The copy and
 * background overrides live in a single content.json.
 */
import { list, put } from "@vercel/blob";

export const SLOT_PREFIX = "slots/";
export const CONTENT_PATH = "content.json";

export const EXTENSIONS = {
  "image/jpeg": "jpg",
  "image/png": "png",
  "image/webp": "webp",
  "image/avif": "avif",
  "image/gif": "gif",
  "image/svg+xml": "svg"
};

export function json(body, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json", "cache-control": "no-store" }
  });
}

export function authorize(request) {
  const expected = process.env.EDIT_KEY;
  if (!expected) return "EDIT_KEY is not configured on the server";
  if (request.headers.get("x-kz-key") !== expected) return "unauthorized";
  return null;
}

/* Slot ids come from the templates and from background keys; keep them to a
   charset that is safe as a blob pathname. */
export function validSlot(slot) {
  return typeof slot === "string" && /^[A-Za-z0-9][A-Za-z0-9._-]{0,80}$/.test(slot);
}

export async function readImages() {
  const { blobs } = await list({ prefix: SLOT_PREFIX });
  const images = {};
  for (const blob of blobs) {
    const slot = blob.pathname.slice(SLOT_PREFIX.length).replace(/\.[^.]+$/, "");
    /* Overwriting a blob keeps its URL, so a cache buster is needed for the
       browser to pick up a replaced photo. */
    images[slot] = `${blob.url}?v=${new Date(blob.uploadedAt).getTime()}`;
  }
  return images;
}

export async function readContent() {
  const { blobs } = await list({ prefix: CONTENT_PATH });
  const entry = blobs.find((b) => b.pathname === CONTENT_PATH);
  if (!entry) return { texts: {}, styles: {} };
  try {
    const res = await fetch(entry.url, { cache: "no-store" });
    if (!res.ok) return { texts: {}, styles: {} };
    const data = await res.json();
    return { texts: data.texts || {}, styles: data.styles || {} };
  } catch {
    return { texts: {}, styles: {} };
  }
}

export async function writeContent(content) {
  await put(CONTENT_PATH, JSON.stringify(content), {
    access: "public",
    contentType: "application/json",
    addRandomSuffix: false,
    allowOverwrite: true,
    cacheControlMaxAge: 0
  });
}
