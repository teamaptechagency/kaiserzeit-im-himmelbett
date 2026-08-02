/*
 * POST /api/upload?slot=<id> — replace one photo.
 *
 * Body is the raw image, content-type says which format. Requires the
 * x-kz-key header to match EDIT_KEY.
 */
import { EXTENSIONS, authorize, json, validSlot, writeImage } from "./_store.js";

export const config = { runtime: "edge" };

const MAX_BYTES = 12 * 1024 * 1024;

export default async function handler(request) {
  if (request.method !== "POST") return json({ error: "POST only" }, 405);

  const denied = authorize(request);
  if (denied) return json({ error: denied }, 401);

  const slot = new URL(request.url).searchParams.get("slot");
  if (!validSlot(slot)) return json({ error: "invalid slot id" }, 400);

  const type = (request.headers.get("content-type") || "").split(";")[0].trim();
  const extension = EXTENSIONS[type];
  if (!extension) return json({ error: `unsupported image type: ${type || "none"}` }, 415);

  const body = await request.arrayBuffer();
  if (!body.byteLength) return json({ error: "empty upload" }, 400);
  if (body.byteLength > MAX_BYTES) return json({ error: "image is larger than 12 MB" }, 413);

  const url = await writeImage(slot, extension, type, body);
  return json({ ok: true, slot, url });
}
