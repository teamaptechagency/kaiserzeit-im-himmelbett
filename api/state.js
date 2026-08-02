/*
 * GET /api/state — everything the client has changed.
 *
 * Public on purpose: the preview has to render the client's photos and copy
 * for anyone holding the link. Only writing is key-protected.
 */
import { readContent, readImages, send } from "./_store.js";

export default async function handler(req, res) {
  try {
    const [images, content] = await Promise.all([readImages(), readContent()]);
    send(res, 200, {
      ok: true, images,
      texts: content.texts, styles: content.styles, notes: content.notes
    });
  } catch (error) {
    /* Before the blob store is connected there is nothing to serve, and an
       empty state is the correct answer — the pages then fall back to their
       bundled assets and original copy. */
    console.error("state failed", error);
    send(res, 200, {
      ok: false, images: {}, texts: {}, styles: {}, notes: {},
      error: String(error && error.message ? error.message : error)
    });
  }
}
