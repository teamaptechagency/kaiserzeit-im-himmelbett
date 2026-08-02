/*
 * Storage and request helpers behind the API routes.
 *
 * These run on Vercel's Node runtime, not Edge. Edge cannot load node:fs, and
 * @vercel/blob pulls in undici, which needs net/tls/zlib — so Edge rejects
 * both backends. Nothing here benefits from Edge anyway: it is binary uploads
 * and blob I/O.
 *
 * Photos live at slots/<slotId>.<ext> with a fixed pathname, so the slot id
 * alone identifies a photo and no index has to be maintained. The copy and
 * background overrides live in a single content.json.
 *
 * Two backends sit behind one interface. On Vercel it is Blob. When
 * KZ_LOCAL_STORE points at a directory it is the filesystem, which is what
 * `npm run dev` uses — the same validation, merge and listing code then runs
 * locally, so the routes can be exercised end to end without a deployment.
 */
import { readdir, readFile, writeFile, mkdir, rm, stat } from "node:fs/promises";
import path from "node:path";

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

const LOCAL_ROOT = () => process.env.KZ_LOCAL_STORE || "";

/* Imported lazily so a local run never has to resolve the Vercel SDK, and so
   a missing token surfaces when a route is called rather than at boot. */
const blob = () => import("@vercel/blob");

/* ------------------------------------------------------------- responses */

export function send(res, status, body) {
  res.statusCode = status;
  /* The charset is explicit because the copy is largely German: without it
     some clients fall back to ISO-8859-1 and every ö and ß comes out
     double-encoded. */
  res.setHeader("content-type", "application/json; charset=utf-8");
  res.setHeader("cache-control", "no-store");
  res.end(JSON.stringify(body));
}

export function authorize(req) {
  const expected = process.env.EDIT_KEY;
  if (!expected) return "EDIT_KEY is not configured on the server";
  if (req.headers["x-kz-key"] !== expected) return "unauthorized";
  return null;
}

/* Vercel parses the body for known content types and leaves a Buffer for the
   rest, but that is not guaranteed for every deployment shape — so accept
   whatever is already there and fall back to draining the stream. */
export async function readBody(req) {
  if (req.body !== undefined && req.body !== null) {
    if (Buffer.isBuffer(req.body)) return req.body;
    if (typeof req.body === "string") return Buffer.from(req.body);
    return Buffer.from(JSON.stringify(req.body));
  }
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  return Buffer.concat(chunks);
}

export function query(req, name) {
  return new URL(req.url, "http://localhost").searchParams.get(name);
}

/* Slot ids come from the templates and from background keys; keep them to a
   charset that is safe as a blob pathname and as a filename. */
export function validSlot(slot) {
  return typeof slot === "string" && /^[A-Za-z0-9][A-Za-z0-9._-]{0,80}$/.test(slot);
}

/* Served by the dev server at /local-store/<pathname>. */
function localUrl(pathname) {
  return `/local-store/${pathname}`;
}

/* ------------------------------------------------------------------ reads */

export async function readImages() {
  const root = LOCAL_ROOT();
  if (root) {
    const dir = path.join(root, "slots");
    let names;
    try {
      names = await readdir(dir);
    } catch {
      return {};
    }
    const images = {};
    for (const name of names) {
      const info = await stat(path.join(dir, name));
      const slot = name.replace(/\.[^.]+$/, "");
      images[slot] = `${localUrl(SLOT_PREFIX + name)}?v=${info.mtimeMs.toFixed(0)}`;
    }
    return images;
  }

  const { list } = await blob();
  const { blobs } = await list({ prefix: SLOT_PREFIX });
  const images = {};
  for (const entry of blobs) {
    const slot = entry.pathname.slice(SLOT_PREFIX.length).replace(/\.[^.]+$/, "");
    /* Overwriting a blob keeps its URL, so a cache buster is needed for the
       browser to pick up a replaced photo. */
    images[slot] = `${entry.url}?v=${new Date(entry.uploadedAt).getTime()}`;
  }
  return images;
}

function shape(data) {
  return {
    texts: (data && data.texts) || {},
    styles: (data && data.styles) || {},
    notes: (data && data.notes) || {}
  };
}

export async function readContent() {
  const root = LOCAL_ROOT();

  if (root) {
    try {
      return shape(JSON.parse(await readFile(path.join(root, CONTENT_PATH), "utf8")));
    } catch {
      return shape(null);
    }
  }

  const { list } = await blob();
  const { blobs } = await list({ prefix: CONTENT_PATH });
  const entry = blobs.find((b) => b.pathname === CONTENT_PATH);
  if (!entry) return shape(null);
  try {
    const res = await fetch(entry.url, { cache: "no-store" });
    if (!res.ok) return shape(null);
    return shape(await res.json());
  } catch {
    return shape(null);
  }
}

/* ----------------------------------------------------------------- writes */

export async function writeContent(content) {
  const root = LOCAL_ROOT();
  if (root) {
    await mkdir(root, { recursive: true });
    await writeFile(path.join(root, CONTENT_PATH), JSON.stringify(content, null, 2));
    return;
  }

  const { put } = await blob();
  await put(CONTENT_PATH, JSON.stringify(content), {
    access: "public",
    contentType: "application/json",
    addRandomSuffix: false,
    allowOverwrite: true,
    cacheControlMaxAge: 0
  });
}

/* Writes the photo and clears any copy of the same slot left behind under a
   different extension, so a slot never has two entries in the listing. */
export async function writeImage(slot, extension, contentType, body) {
  const pathname = `${SLOT_PREFIX}${slot}.${extension}`;
  const root = LOCAL_ROOT();

  if (root) {
    const dir = path.join(root, "slots");
    await mkdir(dir, { recursive: true });
    await writeFile(path.join(dir, `${slot}.${extension}`), body);
    for (const name of await readdir(dir)) {
      if (name.startsWith(`${slot}.`) && name !== `${slot}.${extension}`) {
        await rm(path.join(dir, name), { force: true });
      }
    }
    return `${localUrl(pathname)}?v=${Date.now()}`;
  }

  const { put, list, del } = await blob();
  const written = await put(pathname, body, {
    access: "public",
    contentType,
    addRandomSuffix: false,
    allowOverwrite: true,
    cacheControlMaxAge: 60
  });

  try {
    const { blobs } = await list({ prefix: `${SLOT_PREFIX}${slot}.` });
    const stale = blobs.filter((b) => b.pathname !== pathname).map((b) => b.url);
    if (stale.length) await del(stale);
  } catch (error) {
    console.error("could not remove superseded blobs", error);
  }

  return `${written.url}?v=${Date.now()}`;
}
