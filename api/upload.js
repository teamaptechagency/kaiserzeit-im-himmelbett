/*
 * POST /api/upload?slot=<id> — replace one photo.
 *
 * Body is the raw image, content-type says which format. Requires the
 * x-kz-key header to match EDIT_KEY.
 */
import { EXTENSIONS, authorize, query, readBody, send, validSlot, writeImage } from "./_store.js";

const MAX_BYTES = 12 * 1024 * 1024;

export default async function handler(req, res) {
  if (req.method !== "POST") return send(res, 405, { error: "POST only" });

  const denied = authorize(req);
  if (denied) return send(res, 401, { error: denied });

  const slot = query(req, "slot");
  if (!validSlot(slot)) return send(res, 400, { error: "invalid slot id" });

  const type = String(req.headers["content-type"] || "").split(";")[0].trim();
  const extension = EXTENSIONS[type];
  if (!extension) return send(res, 415, { error: `unsupported image type: ${type || "none"}` });

  const body = await readBody(req);
  if (!body.length) return send(res, 400, { error: "empty upload" });
  if (body.length > MAX_BYTES) return send(res, 413, { error: "image is larger than 12 MB" });

  try {
    const url = await writeImage(slot, extension, type, body);
    send(res, 200, { ok: true, slot, url });
  } catch (error) {
    console.error("upload failed", error);
    send(res, 500, { error: String(error && error.message ? error.message : error) });
  }
}
