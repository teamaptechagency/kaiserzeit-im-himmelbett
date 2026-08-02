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

  var EDIT = new URLSearchParams(location.search).has("edit");
  var KEY_STORE = "kz-edit-key";
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
    var key = sessionStorage.getItem(KEY_STORE);
    if (!key) {
      key = window.prompt("Bearbeitungs-Schlüssel / edit key:");
      if (key) sessionStorage.setItem(KEY_STORE, key);
    }
    return key;
  }

  function failed(res, data) {
    if (res.status === 401) sessionStorage.removeItem(KEY_STORE);
    return new Error(data.error || "Fehler / failed (" + res.status + ")");
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

  if (EDIT) {
    var editor = document.createElement("script");
    editor.src = "editor.js";
    editor.defer = true;
    document.head.appendChild(editor);
  }
})();
