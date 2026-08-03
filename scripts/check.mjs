/*
 * check.mjs — smoke test for the editing chrome.
 *
 *   npm test
 *
 * Loads a real page in jsdom, runs the four front-end scripts against it, and
 * then *drives the buttons*. Booting the modules is not enough: three separate
 * regressions here were inside click handlers, so nothing threw until someone
 * pressed something. Each was a bulk rename that produced valid syntax —
 * `document.body` became `document("guideBody")`, `T.saved` became
 * `T("post")d`, `input.placeholder` became `input("keyPlaceholder")` — and a
 * syntax check happily passed all three.
 *
 * No network and no server: /api/state is stubbed, so this runs anywhere.
 */
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import path from "node:path";
import { JSDOM } from "jsdom";

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const publicDir = path.join(root, "public");

const results = [];
const check = (name, pass, detail = "") => results.push({ name, pass, detail });

const read = (file) => readFile(path.join(publicDir, file), "utf8");

/* One photo already in place, so the slot editor and its fit controls get
   built — that path only runs for a slot that holds an image. */
const STATE = {
  ok: true,
  images: { "intro-photo": "assets/uploads/intro-photo.png" },
  texts: {}, styles: {}, notes: {},
  slots: { "intro-photo": { fit: "contain", position: "top", zoom: 1.4 } }
};

async function boot({ signedIn }) {
  const page = await read("Home.dc.html");

  const dom = new JSDOM(page, {
    url: "http://localhost/Home.dc.html",
    runScripts: "outside-only",
    pretendToBeVisual: true
  });
  const { window } = dom;

  const errors = [];
  window.addEventListener("error", (e) => errors.push(e.message));

  /* jsdom has no layout, so hit-testing is unimplemented. notes.js uses it to
     find the element under the cursor; returning a real anchored element
     exercises the same path. */
  window.document.elementFromPoint = () =>
    window.document.querySelector("#dc-root [data-kz-el]") || window.document.body;

  if (signedIn) {
    window.localStorage.setItem("kz-edit-key", "local");
    window.localStorage.setItem("kz-edit-on", "1");
  }

  /* support.js re-fetches the page source; everything else asks for state. */
  window.fetch = (url) => {
    const target = String(url);
    if (target.includes("/api/state")) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve(STATE) });
    }
    if (target.includes("/api/content")) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: true }) });
    }
    if (target.includes("Home.dc.html") || target.endsWith("/")) {
      return Promise.resolve({ ok: true, text: () => Promise.resolve(page) });
    }
    return Promise.resolve({ ok: false, json: () => Promise.resolve(null) });
  };

  /* The scripts are normally pulled in by <helmet> and by each other; here
     they are run in the order the browser would reach them. */
  for (const file of ["support.js", "image-slot.js"]) {
    window.eval(await read(file));
  }
  window.document.dispatchEvent(new window.Event("DOMContentLoaded"));
  await tick(window, 3);

  if (signedIn) {
    for (const file of ["editor.js", "notes.js"]) window.eval(await read(file));
    await tick(window, 3);
  }

  return { window, errors };
}

/* jsdom's rAF needs real frames to pass; a few macrotasks is enough. */
function tick(window, times = 1) {
  return new Promise((resolve) => {
    let left = times;
    const step = () => (left-- > 0 ? window.setTimeout(step, 16) : resolve());
    step();
  });
}

/* ---------------------------------------------------- signed out: sign-in */
{
  const { window, errors } = await boot({ signedIn: false });
  const doc = window.document;

  check("page renders", !!doc.getElementById("dc-root"));
  check("sign-in button exists", !!doc.querySelector("#kz-signin .trigger"));
  check("comment button exists",
    [...doc.querySelectorAll("#kz-signin .trigger")].length === 2);
  check("toolbar stays hidden when signed out", !doc.getElementById("kz-bar"));

  /* The regression that shipped: clicking did nothing because render() threw
     half way through, so the panel never became visible. */
  const trigger = [...doc.querySelectorAll("#kz-signin .trigger")][0];
  trigger.click();
  await tick(window, 2);

  const panel = doc.querySelector("#kz-signin .panel");
  check("clicking Edit opens the panel", panel && !panel.hidden);
  check("panel offers a key field", !!panel.querySelector("input[type=password]"));
  check("key field has a placeholder", !!panel.querySelector("input")?.placeholder);
  check("panel offers a submit button", !!panel.querySelector(".go"));
  check("no errors while signed out", errors.length === 0, errors.join("; "));
}

/* ------------------------------------------------------ signed in: tools */
{
  const { window, errors } = await boot({ signedIn: true });
  const doc = window.document;

  check("toolbar appears when signed in", !!doc.getElementById("kz-bar"));
  const modes = [...doc.querySelectorAll("#kz-bar button[data-mode]")].map((b) => b.dataset.mode);
  check("four modes registered", modes.join(",") === "text,background,style,notes", modes.join(","));
  check("page picker present", !!doc.querySelector("#kz-bar .kz-pages"));
  check("text is marked editable", doc.querySelectorAll("[data-kz-text]").length > 20,
    String(doc.querySelectorAll("[data-kz-text]").length));

  /* Every mode's enter/exit path, and the panels they open. */
  for (const mode of ["background", "style", "notes", "text"]) {
    doc.querySelector(`#kz-bar button[data-mode=${mode}]`).click();
    await tick(window, 2);
  }
  check("switching every mode throws nothing", errors.length === 0, errors.join("; "));

  /* The background panel builds a lot of markup from the dictionary. */
  doc.querySelector("#kz-bar button[data-mode=background]").click();
  await tick(window);
  const section = doc.querySelector("#dc-root > [data-kz-el]");
  section.dispatchEvent(new window.MouseEvent("click", { bubbles: true }));
  await tick(window, 2);
  const bg = doc.getElementById("kz-panel");
  check("background panel opens", !!bg);
  check("background panel has its controls",
    !!bg?.querySelector("input[type=color]") && bg?.querySelectorAll("[data-ov]").length === 4,
    String(bg?.querySelectorAll("[data-ov]").length));
  check("custom overlay colour is offered", !!bg?.querySelector(".kz-ovcolor"));

  /* The font panel — site-wide, so its own mode rather than per section. */
  doc.querySelector("#kz-bar button[data-mode=style]").click();
  await tick(window, 2);
  const fonts = doc.getElementById("kz-panel");
  check("font panel offers three pairings",
    fonts?.querySelectorAll(".kz-font").length === 3,
    String(fonts?.querySelectorAll(".kz-font").length));

  /* The notes composer, which is where T.saved broke. */
  doc.querySelector("#kz-bar button[data-mode=notes]").click();
  await tick(window);
  doc.dispatchEvent(new window.MouseEvent("click", { bubbles: true, clientX: 40, clientY: 40 }));
  await tick(window, 2);
  const composer = doc.querySelector("#kz-notes .kz-card");
  check("note composer opens", !!composer);
  check("composer has a text field and buttons",
    !!composer?.querySelector("textarea") && composer?.querySelectorAll("button").length >= 2);

  /* Both languages, since every label is resolved through the dictionary. */
  window.localStorage.setItem("kz-lang", "en");
  window.dispatchEvent(new window.CustomEvent("kz:lang", { detail: { lang: "en" } }));
  await tick(window, 2);
  const label = doc.querySelector("#kz-bar .kz-label")?.textContent;
  check("toolbar follows the language switch", label === "Edit", String(label));

  /* The photo slot: its editor, its fit controls, and that a stored fit is
     actually applied rather than just stored. */
  const slot = doc.querySelector("image-slot#intro-photo");
  check("photo slot exists", !!slot);
  check("slot with a photo gets an editor", !!slot?.editor);
  const shadow = slot?.shadowRoot;
  check("fit controls are built",
    shadow?.querySelectorAll(".tools button").length === 4 &&
    !!shadow?.querySelector(".tools input[type=range]"),
    String(shadow?.querySelectorAll(".tools button").length));
  const photo = shadow?.querySelector("img");
  check("stored fit is applied to the photo",
    photo?.style.objectFit === "contain" &&
    photo?.style.objectPosition === "center top" &&
    photo?.style.transform === "scale(1.4)",
    `${photo?.style.objectFit} / ${photo?.style.objectPosition} / ${photo?.style.transform}`);
  check("the placeholder is hidden behind a photo, not over it",
    shadow?.querySelector("[part=empty]")?.hidden === true && photo?.hidden === false);

  check("no errors while signed in", errors.length === 0, errors.join("; "));
}

/* ---------------------------------------------------------------- report */
let failed = 0;
for (const r of results) {
  if (!r.pass) failed++;
  console.log(`${r.pass ? "PASS" : "FAIL"}  ${r.name}${r.pass || !r.detail ? "" : "   <- " + r.detail}`);
}
console.log(`\n${results.length - failed}/${results.length} passed`);
process.exit(failed ? 1 : 0);
