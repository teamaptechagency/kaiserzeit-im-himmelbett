/*
 * image-slot.js — <image-slot> plus the shared client-edit store.
 *
 * The pages load this from their <helmet>, so it is the one script
 * guaranteed to run on every page and it doubles as the bootstrap for
 * everything the client can change:
 *
 *   images   slot id  -> uploaded photo URL
 *   texts    page|lang|original -> replacement wording
 *   styles   page|selector      -> background colour / background image
 *
 * All three come from /api/state on the live preview, or from the checked-in
 * assets/uploads/state.json in a clone with no backend. Editing is off unless
 * the URL carries ?edit=1, which also pulls in editor.js.
 *
 * <image-slot> exposes ::part(frame) and ::part(empty); the pages style both.
 */
(function () {
  "use strict";

  /* Editing is unlocked either by ?edit=1 or by having signed in through the
     button in the corner. The sign-in is remembered in localStorage, so the
     site can be edited straight from its normal public URL — but only by
     someone who knows EDIT_KEY. Everyone else sees a read-only page. */
  var KEY_STORE = "kz-edit-key";
  var EDIT_FLAG = "kz-edit-on";
  var EDIT_FROM_URL = new URLSearchParams(location.search).has("edit");

  function stored(name) {
    try { return localStorage.getItem(name); } catch (err) { return null; }
  }
  function remember(name, value) {
    try {
      if (value == null) localStorage.removeItem(name);
      else localStorage.setItem(name, value);
    } catch (err) { /* private browsing */ }
  }

  var EDIT = EDIT_FROM_URL || (stored(EDIT_FLAG) === "1" && !!stored(KEY_STORE));
  var MAX_BYTES = 12 * 1024 * 1024;

  var state = { images: {}, texts: {}, styles: {} };
  var slots = new Set();

  /* -------------------------------------------------------------- state */

  function loadFrom(url) {
    return fetch(url, { cache: "no-store" })
      .then(function (res) { return res.ok ? res.json() : null; })
      .catch(function () { return null; });
  }

  var ready = loadFrom("/api/state")
    .then(function (data) { return data || loadFrom("assets/uploads/state.json"); })
    .then(function (data) {
      if (data) {
        state.images = data.images || {};
        state.texts = data.texts || {};
        state.styles = data.styles || {};
      }
      applyStyles();
      slots.forEach(function (el) { el.refresh(); });
      return state;
    });

  /* Background overrides are injected as a stylesheet rather than written
     onto the elements. Inline styles are owned by the template diff, and a
     re-render would wipe anything written there. */
  var sheet = null;

  function applyStyles() {
    if (!sheet) {
      sheet = document.createElement("style");
      sheet.id = "kz-style-overrides";
      document.head.appendChild(sheet);
    }
    var page = decodeURIComponent(location.pathname.split("/").pop() || "Home.dc.html");
    var css = "";
    for (var key in state.styles) {
      var split = key.indexOf("|");
      if (split < 0 || key.slice(0, split) !== page) continue;
      var selector = key.slice(split + 1);
      var value = state.styles[key] || {};
      var decls = "";
      if (value.color) decls += "background-color:" + value.color + " !important;";
      if (value.image) {
        decls += "background-image:url('" + value.image.replace(/'/g, "%27") + "') !important;";
        decls += "background-size:cover !important;background-position:center !important;";
      } else if (value.image === "") {
        decls += "background-image:none !important;";
      }
      if (decls) css += selector + "{" + decls + "}\n";
    }
    sheet.textContent = css;
  }

  /* ------------------------------------------------------------ writing */

  function editKey() {
    return stored(KEY_STORE);
  }

  function failed(res, data) {
    /* A rejected key is a dead key — drop it so the next action asks again
       instead of silently failing over and over. */
    if (res.status === 401) {
      remember(KEY_STORE, null);
      remember(EDIT_FLAG, null);
    }
    return new Error(data.error || "Fehler / failed (" + res.status + ")");
  }

  /* Verified against the server rather than locally, so a wrong key is
     rejected at sign-in instead of at the first upload. An empty patch is
     accepted by /api/content purely as this check. */
  function verifyKey(key) {
    return fetch("/api/content", {
      method: "POST",
      headers: { "content-type": "application/json", "x-kz-key": key },
      body: "{}"
    }).then(function (res) {
      if (res.ok) return true;
      return res.json().catch(function () { return {}; }).then(function (data) {
        throw new Error(res.status === 401
          ? (data.error === "unauthorized" ? "Falscher Schlüssel / wrong key" : data.error)
          : (data.error || "Fehler (" + res.status + ")"));
      });
    });
  }

  function upload(slot, file) {
    var key = editKey();
    if (!key) return Promise.reject(new Error("kein Schlüssel / no key"));
    if (!/^image\//.test(file.type)) return Promise.reject(new Error("Nur Bilder / images only"));
    if (file.size > MAX_BYTES) return Promise.reject(new Error("Max. 12 MB"));

    return fetch("/api/upload?slot=" + encodeURIComponent(slot), {
      method: "POST",
      headers: { "content-type": file.type, "x-kz-key": key },
      body: file
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) throw failed(res, data);
        return data;
      });
    });
  }

  /* Saves are queued so two quick edits cannot interleave their
     read-modify-write on the server and lose one another. */
  var queue = Promise.resolve();

  function saveContent(patch) {
    var key = editKey();
    if (!key) return Promise.reject(new Error("kein Schlüssel / no key"));

    queue = queue.then(function () {
      return fetch("/api/content", {
        method: "POST",
        headers: { "content-type": "application/json", "x-kz-key": key },
        body: JSON.stringify(patch)
      }).then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
          if (!res.ok) throw failed(res, data);
          return data;
        });
      });
    }, function () { /* keep the queue alive after a failure */ });

    return queue;
  }

  function setText(key, value) {
    if (value == null) delete state.texts[key];
    else state.texts[key] = value;
    if (window.DC) window.DC.rerender();
    var patch = { texts: {} };
    patch.texts[key] = value == null ? null : value;
    return saveContent(patch);
  }

  function setStyle(key, value) {
    state.styles[key] = value;
    applyStyles();
    var patch = { styles: {} };
    patch.styles[key] = value;
    return saveContent(patch);
  }

  function publishImage(slot, url) {
    state.images[slot] = url;
    slots.forEach(function (el) { if (el.slotId === slot) el.refresh(); });
    window.dispatchEvent(new CustomEvent("kz:image", { detail: { slot: slot, url: url } }));
  }

  /* -------------------------------------------------------- <image-slot> */

  var STYLE = [
    ":host{display:block;position:relative;}",
    "[part=frame]{position:relative;width:100%;height:100%;overflow:hidden;",
    "background:#2a2521;border-radius:inherit;}",
    "img{width:100%;height:100%;display:block;object-fit:cover;}",
    ":host([fit=contain]) img{object-fit:contain;}",
    "[part=empty]{position:absolute;inset:0;display:flex;align-items:center;",
    "justify-content:center;text-align:center;padding:10px;box-sizing:border-box;",
    "font-family:inherit;font-size:inherit;line-height:1.35;",
    "color:rgba(242,236,224,0.45);border:1px dashed rgba(185,128,63,0.3);",
    "border-radius:inherit;}",
    ".edit{position:absolute;inset:0;display:flex;flex-direction:column;gap:2px;",
    "align-items:center;justify-content:center;text-align:center;padding:6px;",
    "box-sizing:border-box;cursor:pointer;opacity:0;transition:opacity .15s;",
    "background:rgba(26,24,21,0.72);color:#f2ecdf;font-family:inherit;",
    "font-size:11px;letter-spacing:0.04em;border-radius:inherit;}",
    ":host(:hover) .edit,.edit.busy,.edit.over{opacity:1;}",
    ".edit.over{background:rgba(185,128,63,0.75);color:#201d1a;}",
    ".tag{font-size:9px;opacity:0.7;word-break:break-all;}",
    ".err{color:#e8a598;font-size:10px;}"
  ].join("");

  var TEMPLATE = document.createElement("template");
  TEMPLATE.innerHTML = "<div part='frame'><img part='img' alt='' hidden><div part='empty'></div></div>";

  var ImageSlot = class extends HTMLElement {
    static get observedAttributes() { return ["id", "src", "shape", "radius", "placeholder"]; }

    constructor() {
      super();
      var root = this.attachShadow({ mode: "open" });
      var style = document.createElement("style");
      style.textContent = STYLE;
      root.appendChild(style);
      root.appendChild(TEMPLATE.content.cloneNode(true));
      this.frame = root.querySelector("[part=frame]");
      this.img = root.querySelector("img");
      this.empty = root.querySelector("[part=empty]");
    }

    get slotId() { return this.getAttribute("id") || ""; }

    connectedCallback() {
      slots.add(this);
      if (EDIT && !this.editor) this.attachEditor();
      this.refresh();
    }

    disconnectedCallback() { slots.delete(this); }

    attributeChangedCallback() { if (this.isConnected) this.refresh(); }

    refresh() {
      var shape = this.getAttribute("shape") || "rect";
      this.frame.style.borderRadius = shape === "circle" ? "50%"
        : shape === "rounded" ? (this.getAttribute("radius") || "8") + "px"
        : "";

      /* An uploaded photo wins; src= is the bundled fallback, which is how
         the hero texture and the supplied map render before any upload. */
      var url = state.images[this.slotId] || this.getAttribute("src") || "";
      if (url) {
        this.img.src = url;
        this.img.hidden = false;
        this.empty.hidden = true;
      } else {
        this.img.hidden = true;
        this.img.removeAttribute("src");
        this.empty.hidden = false;
        this.empty.textContent = this.getAttribute("placeholder") || "Foto";
      }
      if (this.editor) {
        this.editor.label.textContent = url ? "Ersetzen / Replace" : "Foto hinzufügen / Add";
      }
    }

    attachEditor() {
      var self = this;
      var box = document.createElement("div");
      box.className = "edit";
      var label = document.createElement("div");
      var tag = document.createElement("div");
      tag.className = "tag";
      box.appendChild(label);
      box.appendChild(tag);
      this.frame.appendChild(box);
      this.editor = { box: box, label: label, tag: tag };

      var input = document.createElement("input");
      input.type = "file";
      input.accept = "image/*";
      input.style.display = "none";
      this.shadowRoot.appendChild(input);

      function send(file) {
        if (!file) return;
        box.classList.add("busy");
        box.classList.remove("over");
        label.textContent = "Wird hochgeladen…";
        tag.textContent = self.slotId;
        upload(self.slotId, file).then(function (data) {
          box.classList.remove("busy");
          publishImage(self.slotId, data.url);
        }, function (err) {
          box.classList.remove("busy");
          label.textContent = "Fehler";
          tag.className = "err";
          tag.textContent = err.message;
          setTimeout(function () { tag.className = "tag"; self.refresh(); }, 4000);
        });
      }

      box.addEventListener("click", function (e) {
        e.preventDefault(); e.stopPropagation(); input.click();
      });
      input.addEventListener("change", function () { send(input.files[0]); input.value = ""; });

      ["dragenter", "dragover"].forEach(function (type) {
        box.addEventListener(type, function (e) {
          e.preventDefault(); e.stopPropagation();
          box.classList.add("over");
          label.textContent = "Hier ablegen / Drop here";
          tag.textContent = self.slotId;
        });
      });
      ["dragleave", "dragend"].forEach(function (type) {
        box.addEventListener(type, function () { box.classList.remove("over"); self.refresh(); });
      });
      box.addEventListener("drop", function (e) {
        e.preventDefault(); e.stopPropagation();
        send(e.dataTransfer.files && e.dataTransfer.files[0]);
      });
      this.addEventListener("mouseenter", function () { tag.textContent = self.slotId; });
    }
  };

  if (!customElements.get("image-slot")) customElements.define("image-slot", ImageSlot);

  window.KZ = {
    ready: ready,
    edit: EDIT,
    editFromUrl: EDIT_FROM_URL,
    state: state,
    get images() { return state.images; },
    get texts() { return state.texts; },
    get styles() { return state.styles; },
    upload: upload,
    setText: setText,
    setStyle: setStyle,
    publishImage: publishImage,
    applyStyles: applyStyles
  };

  /* ------------------------------------------------------- sign-in button --
     The design already has an "Edit" button in the bottom-left corner that
     opens an Editing Guide, but it was a demo explainer that did nothing.
     This turns it into the real entry point: same styling, same position, on
     every page rather than only on Home, with the key prompt inside the panel
     it already showed. The prototype's inert copy is hidden so there is only
     one. */

  var COPY = {
    de: {
      trigger: "Bearbeiten",
      title: "Bearbeitungsmodus",
      body: "Text: Doppelklicken Sie auf einen Text, um ihn zu ändern. Bilder: Ziehen Sie ein Foto auf einen Platzhalter. Hintergrund: Abschnitt anklicken.",
      locked: "Geben Sie den Schlüssel ein, um Änderungen vorzunehmen.",
      placeholder: "Schlüssel",
      start: "Bearbeiten starten",
      checking: "Wird geprüft…",
      active: "Bearbeitung ist aktiv.",
      images: "Alle Bilder",
      stop: "Bearbeiten beenden",
      close: "Verstanden"
    },
    en: {
      trigger: "Edit",
      title: "Editing Guide",
      body: "Text: double-click any text to change it. Images: drag a photo onto any placeholder. Background: click a section.",
      locked: "Enter the key to make changes.",
      placeholder: "Key",
      start: "Start editing",
      checking: "Checking…",
      active: "Editing is on.",
      images: "All images",
      stop: "Stop editing",
      close: "Got it"
    }
  };

  function buildSignIn() {
    /* Read from the same stored choice support.js uses, rather than from
       <html lang>, because the first render may not have run yet. */
    var t = COPY[stored("kz-lang") === "en" ? "en" : "de"];

    var style = document.createElement("style");
    style.textContent = [
      /* The prototype's demo button sat here and did nothing. */
      '#dc-root [style*="bottom:26px"][style*="left:26px"]{display:none!important;}',
      /* Clears the editor toolbar when it is on screen. */
      "#kz-signin{position:fixed;bottom:" + (EDIT ? "74px" : "26px") + ";left:26px;",
      "z-index:61;font-family:'EB Garamond',Georgia,serif;}",
      "#kz-signin .trigger{display:flex;align-items:center;gap:8px;",
      "border:1px solid rgba(185,128,63,0.4);border-radius:999px;padding:10px 16px;",
      "background:#29241f;color:#f2ecdf;font-family:'Playfair Display',serif;",
      "font-weight:600;font-size:13px;cursor:pointer;box-shadow:0 10px 24px rgba(0,0,0,0.3);}",
      "#kz-signin .panel{position:absolute;bottom:52px;left:0;width:min(78vw,300px);",
      "background:#29241f;border:1px solid rgba(185,128,63,0.3);border-radius:14px;",
      "padding:20px;box-shadow:0 16px 36px rgba(0,0,0,0.4);color:#f2ecdf;font-size:14px;",
      "line-height:1.55;}",
      "#kz-signin .panel[hidden]{display:none;}",
      "#kz-signin h4{margin:0 0 8px;font-family:'Playfair Display',serif;font-weight:600;",
      "font-size:17px;color:#f7f1e6;}",
      "#kz-signin p{margin:0 0 14px;color:rgba(242,236,224,0.72);}",
      "#kz-signin input{width:100%;box-sizing:border-box;background:rgba(0,0,0,0.3);",
      "border:1px solid rgba(185,128,63,0.25);border-radius:8px;padding:10px 12px;",
      "color:#f2ecdf;font-family:inherit;font-size:14px;margin-bottom:10px;outline:none;}",
      "#kz-signin input:focus{border-color:rgba(185,128,63,0.6);}",
      "#kz-signin .go{width:100%;border:none;border-radius:999px;padding:10px;",
      "background:linear-gradient(135deg,#e6c184,#b9803f);color:#201d1a;",
      "font-family:'Playfair Display',serif;font-weight:600;font-size:13px;cursor:pointer;}",
      "#kz-signin .go[disabled]{opacity:.6;cursor:default;}",
      "#kz-signin .links{display:flex;gap:14px;margin-top:12px;font-size:13px;}",
      "#kz-signin .links a,#kz-signin .links button{color:#d9a868;background:none;border:none;",
      "padding:0;font:inherit;cursor:pointer;text-decoration:none;}",
      "#kz-signin .err{color:#e8a598;font-size:13px;margin:0 0 10px;}"
    ].join("");
    document.head.appendChild(style);

    var host = document.createElement("div");
    host.id = "kz-signin";

    var trigger = document.createElement("button");
    trigger.className = "trigger";
    trigger.innerHTML =
      "<svg width='14' height='14' viewBox='0 0 24 24' fill='none'>" +
      "<path d='M4 20h4L20 8l-4-4L4 16v4z' stroke='#d9a868' stroke-width='1.6' " +
      "stroke-linejoin='round'/></svg><span></span>";
    trigger.querySelector("span").textContent = t.trigger;

    var panel = document.createElement("div");
    panel.className = "panel";
    panel.hidden = true;

    host.appendChild(panel);
    host.appendChild(trigger);
    document.body.appendChild(host);

    function render() {
      panel.textContent = "";

      var title = document.createElement("h4");
      title.textContent = t.title;
      var body = document.createElement("p");
      body.textContent = EDIT ? t.body : t.locked;
      panel.appendChild(title);
      panel.appendChild(body);

      if (!EDIT) {
        var error = document.createElement("p");
        error.className = "err";
        error.hidden = true;

        var input = document.createElement("input");
        input.type = "password";
        input.placeholder = t.placeholder;
        input.autocomplete = "current-password";

        var go = document.createElement("button");
        go.className = "go";
        go.textContent = t.start;

        function submit() {
          var key = input.value.trim();
          if (!key) return input.focus();
          go.disabled = true;
          go.textContent = t.checking;
          error.hidden = true;
          verifyKey(key).then(function () {
            remember(KEY_STORE, key);
            remember(EDIT_FLAG, "1");
            /* Reloading is the honest way in: the image slots decide whether
               to attach their drop targets when they are created. */
            location.reload();
          }, function (err) {
            go.disabled = false;
            go.textContent = t.start;
            error.textContent = err.message;
            error.hidden = false;
            input.select();
          });
        }

        go.addEventListener("click", submit);
        input.addEventListener("keydown", function (e) {
          if (e.key === "Enter") { e.preventDefault(); submit(); }
        });

        panel.appendChild(error);
        panel.appendChild(input);
        panel.appendChild(go);
        setTimeout(function () { input.focus(); }, 0);
        return;
      }

      var done = document.createElement("button");
      done.className = "go";
      done.textContent = t.close;
      done.addEventListener("click", function () { panel.hidden = true; });
      panel.appendChild(done);

      var links = document.createElement("div");
      links.className = "links";
      var admin = document.createElement("a");
      admin.href = "admin.html";
      admin.textContent = t.images;
      var stop = document.createElement("button");
      stop.textContent = t.stop;
      stop.addEventListener("click", function () {
        remember(KEY_STORE, null);
        remember(EDIT_FLAG, null);
        /* Drop ?edit=1 too, or the reload would land straight back in edit. */
        var url = new URL(location.href);
        url.searchParams.delete("edit");
        location.href = url.pathname + url.search;
      });
      links.appendChild(admin);
      links.appendChild(stop);
      panel.appendChild(links);
    }

    trigger.addEventListener("click", function () {
      if (panel.hidden) render();
      panel.hidden = !panel.hidden;
    });

    document.addEventListener("click", function (e) {
      if (!panel.hidden && !host.contains(e.target)) panel.hidden = true;
    });
  }

  function boot() {
    if (EDIT) {
      var editor = document.createElement("script");
      editor.src = "editor.js";
      document.head.appendChild(editor);
    }
    buildSignIn();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
