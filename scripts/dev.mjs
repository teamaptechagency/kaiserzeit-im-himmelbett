/*
 * Local dev server.
 *
 * Serves public/ and routes /api/* to the real handlers in api/, backed by a
 * directory on disk instead of Vercel Blob (see api/_store.js). That means
 * uploading a photo, editing copy and setting a background all work exactly
 * as they do in production, without a deployment or a Vercel account.
 *
 *   npm run dev
 *
 * Uploaded files land in .local-store/ (gitignored) and are served back from
 * /local-store/. Set EDIT_KEY to change the key; it defaults to "local".
 */
import { createServer } from "node:http";
import { readFile, stat } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.dirname(here);
const publicDir = path.join(root, "public");
const storeDir = path.join(root, ".local-store");

process.env.KZ_LOCAL_STORE = storeDir;
process.env.EDIT_KEY = process.env.EDIT_KEY || "local";

const port = Number(process.env.PORT || 8788);

const MIME = {
  ".html": "text/html; charset=utf-8",
  ".js": "text/javascript; charset=utf-8",
  ".mjs": "text/javascript; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".png": "image/png",
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
  ".webp": "image/webp",
  ".avif": "image/avif",
  ".gif": "image/gif",
  ".svg": "image/svg+xml"
};

const routes = {
  "/api/state": () => import("../api/state.js"),
  "/api/upload": () => import("../api/upload.js"),
  "/api/content": () => import("../api/content.js")
};

/* Node's req/res translated to the Web Request/Response the edge handlers
   are written against. */
async function toRequest(req) {
  const url = new URL(req.url, `http://localhost:${port}`);
  const init = { method: req.method, headers: req.headers };
  if (req.method !== "GET" && req.method !== "HEAD") {
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    init.body = Buffer.concat(chunks);
  }
  return new Request(url, init);
}

async function send(res, response) {
  res.statusCode = response.status;
  response.headers.forEach((value, key) => res.setHeader(key, value));
  res.end(Buffer.from(await response.arrayBuffer()));
}

async function serveFile(res, filePath, { store = false } = {}) {
  const info = await stat(filePath).catch(() => null);
  if (!info || !info.isFile()) return false;
  const body = await readFile(filePath);
  res.statusCode = 200;
  res.setHeader("content-type", MIME[path.extname(filePath).toLowerCase()] || "application/octet-stream");
  res.setHeader("cache-control", store ? "no-cache" : "no-store");
  res.end(body);
  return true;
}

const server = createServer(async (req, res) => {
  try {
    const url = new URL(req.url, `http://localhost:${port}`);
    let pathname = decodeURIComponent(url.pathname);

    const route = routes[pathname];
    if (route) {
      const module = await route();
      return await send(res, await module.default(await toRequest(req)));
    }

    /* Files the local store has written back to the browser. */
    if (pathname.startsWith("/local-store/")) {
      const target = path.join(storeDir, pathname.slice("/local-store/".length));
      if (!target.startsWith(storeDir)) { res.statusCode = 403; return res.end("forbidden"); }
      if (await serveFile(res, target, { store: true })) return;
      res.statusCode = 404;
      return res.end("not found");
    }

    if (pathname === "/") pathname = "/Home.dc.html";
    if (pathname === "/admin") pathname = "/admin.html";

    const target = path.join(publicDir, pathname);
    if (!target.startsWith(publicDir)) { res.statusCode = 403; return res.end("forbidden"); }
    if (await serveFile(res, target)) return;

    res.statusCode = 404;
    res.end(`not found: ${pathname}`);
  } catch (error) {
    console.error(error);
    res.statusCode = 500;
    res.end(String(error && error.stack ? error.stack : error));
  }
});

server.listen(port, () => {
  console.log(`kaiserzeit preview  http://localhost:${port}/`);
  console.log(`  edit mode         http://localhost:${port}/?edit=1   (key: ${process.env.EDIT_KEY})`);
  console.log(`  image grid        http://localhost:${port}/admin`);
  console.log(`  store             ${storeDir}`);
});
