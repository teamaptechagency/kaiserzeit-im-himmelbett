/*
 * GET /api/state — everything the client has changed.
 *
 * Public on purpose: the preview has to render the client's photos and copy
 * for anyone holding the link. Only writing is key-protected.
 */
import { json, readImages, readContent } from "./_store.js";

export const config = { runtime: "edge" };

export default async function handler() {
  try {
    const [images, content] = await Promise.all([readImages(), readContent()]);
    return json({ ok: true, images, texts: content.texts, styles: content.styles });
  } catch (error) {
    /* Before the blob store is connected there is nothing to serve, and an
       empty state is the correct answer — the pages then fall back to their
       bundled assets and original copy. */
    console.error("state failed", error);
    return json({ ok: false, images: {}, texts: {}, styles: {}, error: String(error.message || error) });
  }
}
