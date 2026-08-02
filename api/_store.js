/*
 * Storage behind the API routes.
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

const LOCAL_ROOT = process.env.KZ_LOCAL_STORE || "";

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
   charset that is safe as a blob pathname and as a filename. */
export function validSlot(slot) {
  return typeof slot === "string" && /^[A-Za-z0-9][A-Za-z0-9._-]{0,80}$/.test(slot);
}

/* --------------------------------------------------------------- backends */

async function blobApi() {
  return import("@vercel/blob");
}

async function localFs() {
  const [fs, path, url] = await Promise.all([
    import("node:fs/promises"), import("node:path"), import("node:url")
  ]);
  return { fs, path, url };
}

/* Served by the dev server at /local-store/<pathname>. */
function localUrl(pathname) {
  return `/local-store/${pathname}`;
}

/* ------------------------------------------------------------------ reads */

export async function readImages() {
  if (LOCAL_ROOT) {
    const { fs, path } = await localFs();
    const dir = path.join(LOCAL_ROOT, "slots");
    let names = [];
    try {
      names = await fs.readdir(dir);
    } catch {
      return {};
    }
    const images = {};
    for (const name of names) {
      const stat = await fs.stat(path.join(dir, name));
      const slot = name.replace(/\.[^.]+$/, "");
      images[slot] = `${localUrl(SLOT_PREFIX + name)}?v=${stat.mtimeMs.toFixed(0)}`;
    }
    return images;
  }

  const { list } = await blobApi();
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
  const empty = { texts: {}, styles: {} };

  if (LOCAL_ROOT) {
    const { fs, path } = await localFs();
    try {
      const raw = await fs.readFile(path.join(LOCAL_ROOT, CONTENT_PATH), "utf8");
      const data = JSON.parse(raw);
      return { texts: data.texts || {}, styles: data.styles || {} };
    } catch {
      return empty;
    }
  }

  const { list } = await blobApi();
  const { blobs } = await list({ prefix: CONTENT_PATH });
  const entry = blobs.find((b) => b.pathname === CONTENT_PATH);
  if (!entry) return empty;
  try {
    const res = await fetch(entry.url, { cache: "no-store" });
    if (!res.ok) return empty;
    const data = await res.json();
    return { texts: data.texts || {}, styles: data.styles || {} };
  } catch {
    return empty;
  }
}

/* ----------------------------------------------------------------- writes */

export async function writeContent(content) {
  if (LOCAL_ROOT) {
    const { fs, path } = await localFs();
    await fs.mkdir(LOCAL_ROOT, { recursive: true });
    await fs.writeFile(path.join(LOCAL_ROOT, CONTENT_PATH), JSON.stringify(content, null, 2));
    return;
  }

  const { put } = await blobApi();
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

  if (LOCAL_ROOT) {
    const { fs, path } = await localFs();
    const dir = path.join(LOCAL_ROOT, "slots");
    await fs.mkdir(dir, { recursive: true });
    await fs.writeFile(path.join(dir, `${slot}.${extension}`), Buffer.from(body));
    for (const name of await fs.readdir(dir)) {
      if (name.startsWith(`${slot}.`) && name !== `${slot}.${extension}`) {
        await fs.rm(path.join(dir, name), { force: true });
      }
    }
    return `${localUrl(pathname)}?v=${Date.now()}`;
  }

  const { put, list, del } = await blobApi();
  const blob = await put(pathname, body, {
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

  return `${blob.url}?v=${Date.now()}`;
}
