/*
 * Pulls the CONTENT object out of each .dc.html page.
 *
 * The design handoff put the whole site's copy — German and English — inside
 * each page as a JS object the client-side runtime read. That object is the
 * content model this CMS has to hold, so it is lifted out here rather than
 * retyped by hand.
 */
import { readdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import vm from "node:vm";

const PAGES = process.argv[2];
const OUT = process.argv[3];

/* Walks from the opening brace to its match, respecting strings, template
   literals and comments — a regex would stop at the first nested }. */
function objectAt(source, start) {
  let depth = 0;
  let quote = null;
  for (let i = start; i < source.length; i++) {
    const c = source[i];
    const prev = source[i - 1];
    if (quote) {
      if (c === quote && prev !== "\\") quote = null;
      continue;
    }
    if (c === '"' || c === "'" || c === "`") { quote = c; continue; }
    if (c === "/" && source[i + 1] === "/") { i = source.indexOf("\n", i); if (i < 0) break; continue; }
    if (c === "/" && source[i + 1] === "*") { i = source.indexOf("*/", i) + 1; continue; }
    if (c === "{") depth++;
    if (c === "}") { depth--; if (depth === 0) return source.slice(start, i + 1); }
  }
  return null;
}

const files = (await readdir(PAGES)).filter((f) => f.endsWith(".dc.html"));
const out = {};

for (const file of files) {
  const html = await readFile(path.join(PAGES, file), "utf8");
  const marker = html.indexOf("const APARTMENTS");
  if (marker < 0) { out[file] = null; continue; }

  const brace = html.indexOf("{", marker);
  const literal = objectAt(html, brace);
  if (!literal) { out[file] = null; continue; }

  try {
    out[file] = vm.runInNewContext("(" + literal + ")", {}, { timeout: 5000 });
  } catch (error) {
    out[file] = { __error: error.message };
  }
}

await writeFile(OUT, JSON.stringify(out, null, 2));

for (const [file, content] of Object.entries(out)) {
  if (!content) { console.log(`${file.padEnd(22)} — no CONTENT`); continue; }
  if (content.__error) { console.log(`${file.padEnd(22)} — FAILED: ${content.__error}`); continue; }
  const langs = Object.keys(content);
  const keys = Object.keys(content[langs[0]] || {});
  console.log(`${file.padEnd(22)} ${langs.join("/")} · ${keys.length} sections: ${keys.join(", ")}`);
}
