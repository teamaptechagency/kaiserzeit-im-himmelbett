/*
 * editor.js — client-facing editing, loaded only when the URL has ?edit=1.
 *
 * Three things the client can change without touching code:
 *
 *   photos       drag onto any placeholder (handled by image-slot.js)
 *   copy         double-click any text, type, press Enter
 *   backgrounds  pick a section, set a colour or drop in an image
 *
 * Every change is saved server-side immediately and is visible to anyone
 * else opening the link. `npm run pull` brings the whole lot into the repo.
 */
(function () {
  "use strict";

  var KZ = window.KZ;
  var DC = window.DC;
  if (!KZ || !DC) return;

  var GOLD = "#d9a868";
  var mode = "text";           /* "text" | "background" */
  var panel = null;

  /* ---------------------------------------------------------------- chrome */

  var css = document.createElement("style");
  css.textContent = [
    "#kz-bar{position:fixed;left:0;right:0;bottom:0;z-index:99999;background:#161310;",
    "color:#f2ecdf;border-top:1px solid rgba(185,128,63,.4);padding:8px 14px;",
    "font:13px/1.45 'EB Garamond',Georgia,serif;display:flex;gap:10px;align-items:center;",
    "flex-wrap:wrap;justify-content:center;}",
    "#kz-bar button{font:inherit;cursor:pointer;border-radius:999px;padding:6px 14px;",
    "border:1px solid rgba(185,128,63,.45);background:transparent;color:#f2ecdf;}",
    "#kz-bar button[aria-pressed=true]{background:linear-gradient(135deg,#e6c184,#b9803f);",
    "color:#201d1a;border-color:transparent;}",
    "#kz-bar .kz-hint{opacity:.65;}",
    "#kz-bar .kz-pages{font:inherit;background:#29241f;color:#f2ecdf;cursor:pointer;",
    "border:1px solid rgba(185,128,63,.45);border-radius:999px;padding:6px 12px;}",
    /* Links stay editable but stop behaving as links. */
    "#dc-root a{cursor:text;}",
    "#kz-bar .kz-status{min-width:90px;text-align:right;color:" + GOLD + ";}",
    /* Text editing */
    "[data-kz-text]:hover{outline:1px dashed rgba(217,168,104,.65);outline-offset:2px;cursor:text;}",
    "[contenteditable=true]{outline:2px solid " + GOLD + " !important;outline-offset:2px;",
    "cursor:text;min-width:12px;}",
    /* Background picking */
    "body.kz-bg [data-kz-el]{cursor:crosshair;}",
    "body.kz-bg .kz-target{outline:2px solid " + GOLD + " !important;outline-offset:-2px;}",
    "#kz-panel{position:fixed;z-index:100000;right:14px;bottom:58px;width:250px;",
    "background:#1e1a17;border:1px solid rgba(185,128,63,.4);border-radius:12px;",
    "padding:14px;color:#f2ecdf;font:13px/1.5 'EB Garamond',Georgia,serif;",
    "box-shadow:0 16px 36px rgba(0,0,0,.45);}",
    "#kz-panel h4{margin:0 0 10px;font:600 14px 'Playfair Display',serif;color:" + GOLD + ";}",
    "#kz-panel label{display:block;margin:10px 0 4px;font-size:11px;letter-spacing:.14em;",
    "text-transform:uppercase;color:rgba(242,236,224,.55);}",
    "#kz-panel input[type=color]{width:100%;height:34px;padding:0;border:1px solid ",
    "rgba(185,128,63,.35);border-radius:8px;background:transparent;cursor:pointer;}",
    "#kz-panel .kz-val,#kz-panel .kz-val2{float:right;letter-spacing:0;color:" + GOLD + ";}",
    "#kz-panel .kz-seg{display:flex;gap:6px;}",
    "#kz-panel .kz-seg button{flex:1;font:inherit;cursor:pointer;border-radius:999px;",
    "padding:6px 4px;font-size:12px;border:1px solid rgba(185,128,63,.4);",
    "background:transparent;color:#f2ecdf;}",
    "#kz-panel .kz-seg button[aria-pressed=true]{background:linear-gradient(135deg,#e6c184,#b9803f);",
    "color:#201d1a;border-color:transparent;}",
    "#kz-panel input[type=range]{width:100%;accent-color:" + GOLD + ";margin:2px 0 0;}",
    "#kz-panel .kz-row{display:flex;gap:8px;margin-top:12px;}",
    "#kz-panel button{flex:1;font:inherit;cursor:pointer;border-radius:999px;padding:7px 10px;",
    "border:1px solid rgba(185,128,63,.45);background:transparent;color:#f2ecdf;}",
    "#kz-panel .kz-drop{margin-top:8px;border:1px dashed rgba(185,128,63,.45);border-radius:8px;",
    "padding:14px 8px;text-align:center;font-size:12px;cursor:pointer;}",
    "#kz-panel .kz-drop.over{background:rgba(185,128,63,.25);}"
  ].join("");
  document.head.appendChild(css);

  var bar = document.createElement("div");
  bar.id = "kz-bar";
  bar.innerHTML =
    "<span style='color:" + GOLD + ";letter-spacing:.12em;text-transform:uppercase;font-size:11px'>" +
    "Bearbeiten</span>" +
    "<button data-mode='text' aria-pressed='true'>Text</button>" +
    "<button data-mode='background' aria-pressed='false'>Hintergrund</button>" +
    "<span class='kz-hint'></span>" +
    "<a href='admin.html' style='color:" + GOLD + "'>Alle Bilder</a>" +
    "<span class='kz-status'></span>";
  document.body.appendChild(bar);
  document.body.style.paddingBottom = "56px";

  var hint = bar.querySelector(".kz-hint");
  var status = bar.querySelector(".kz-status");

  /* Modes are a registry so notes.js can add its own without this file
     knowing anything about comments. */
  var MODES = {
    text: { hint: "Doppelklick auf einen Text zum Ändern. Fotos: einfach darauf ziehen." },
    background: { hint: "Klicken Sie auf einen Abschnitt, um Farbe oder Bild zu setzen." }
  };

  function setMode(next) {
    var previous = mode;
    mode = next;
    hint.textContent = (MODES[next] && MODES[next].hint) || "";
    document.body.classList.toggle("kz-bg", next === "background");
    bar.querySelectorAll("button[data-mode]").forEach(function (b) {
      b.setAttribute("aria-pressed", String(b.dataset.mode === next));
    });
    if (next !== "background") closePanel();
    if (MODES[previous] && MODES[previous].exit) MODES[previous].exit();
    if (MODES[next] && MODES[next].enter) MODES[next].enter();
  }

  bar.addEventListener("click", function (e) {
    var button = e.target.closest("button[data-mode]");
    if (button) setMode(button.dataset.mode);
  });
  setMode("text");

  window.KZEditor = {
    bar: bar,
    say: say,
    setMode: setMode,
    isMode: function (id) { return mode === id; },
    addMode: function (id, label, options) {
      MODES[id] = options || {};
      var button = document.createElement("button");
      button.dataset.mode = id;
      button.setAttribute("aria-pressed", "false");
      button.textContent = label;
      /* Sits with the other mode buttons, before the hint text. */
      bar.insertBefore(button, hint);
      return button;
    }
  };

  var statusTimer;
  function say(message, sticky) {
    status.textContent = message;
    clearTimeout(statusTimer);
    if (!sticky) statusTimer = setTimeout(function () { status.textContent = ""; }, 2500);
  }

  /* Links are inert while editing. Their text is editable like anything
     else, and a stray single click would otherwise navigate away mid-edit —
     or drag the client off to another page when they meant to fix a word.
     The href itself is never changed; only the wording is. Moving between
     pages goes through the picker below instead. */
  document.addEventListener("click", function (e) {
    var link = e.target.closest && e.target.closest("#dc-root a[href]");
    if (!link) return;
    e.preventDefault();
    e.stopPropagation();
  }, true);

  var PAGES = [
    ["Home.dc.html", "Home"],
    ["Apartments.dc.html", "Apartments"],
    ["Apartment.dc.html", "Apartment"],
    ["Booking.dc.html", "Buchung"],
    ["Profile.dc.html", "Profil"],
    ["About Us.dc.html", "Über uns"],
    ["Contact.dc.html", "Kontakt"]
  ];

  var picker = document.createElement("select");
  picker.className = "kz-pages";
  PAGES.forEach(function (entry) {
    var option = document.createElement("option");
    option.value = entry[0];
    option.textContent = entry[1];
    if (entry[0] === DC.page) option.selected = true;
    picker.appendChild(option);
  });
  picker.addEventListener("change", function () {
    /* ?edit=1 is carried only when that is how this session was unlocked; a
       signed-in session is remembered on its own. */
    location.href = picker.value + (KZ.editFromUrl ? "?edit=1" : "");
  });
  bar.insertBefore(picker, bar.querySelector(".kz-hint"));

  /* ------------------------------------------------------------------ text */

  /* An element is editable when it holds exactly one text node that came
     from the template, so headings, paragraphs, buttons and labels qualify
     while wrappers and icon markup do not. */
  function markText() {
    var root = document.getElementById("dc-root");
    if (!root) return;
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
    var el = root;
    while (el) {
      var text = null, count = 0, ok = true;
      for (var i = 0; i < el.childNodes.length; i++) {
        var node = el.childNodes[i];
        if (node.nodeType === 1) { ok = false; break; }
        if (node.nodeType === 3 && node.nodeValue.trim()) { text = node; count++; }
      }
      if (ok && count === 1 && text.__dcSource && text.__dcSource.trim()) {
        el.setAttribute("data-kz-text", "");
      } else {
        el.removeAttribute("data-kz-text");
      }
      el = walker.nextNode();
    }
  }

  var editing = null;

  function beginEdit(el) {
    if (editing) finishEdit(true);
    var text = null;
    for (var i = 0; i < el.childNodes.length; i++) {
      if (el.childNodes[i].nodeType === 3 && el.childNodes[i].nodeValue.trim()) {
        text = el.childNodes[i];
      }
    }
    if (!text || !text.__dcSource) return;

    editing = { el: el, source: text.__dcSource, before: text.nodeValue };
    el.setAttribute("contenteditable", "true");
    el.focus();
    var range = document.createRange();
    range.selectNodeContents(el);
    var selection = getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
  }

  function finishEdit(cancel) {
    if (!editing) return;
    var current = editing;
    editing = null;
    current.el.removeAttribute("contenteditable");

    var value = current.el.textContent;
    if (cancel || value === current.before) {
      current.el.textContent = current.before;
      return;
    }

    /* Keyed on the original wording, so re-editing the same slot overwrites
       rather than accumulating. Clearing it back to the original removes the
       override entirely. */
    var key = DC.page + "|" + DC.lang() + "|" + current.source;
    var trimmed = value.trim();
    say("Speichern…", true);
    KZ.setText(key, trimmed === current.source.trim() ? null : trimmed)
      .then(function () { say("Gespeichert ✓"); },
            function (err) { say(err.message); DC.rerender(); });
  }

  document.addEventListener("dblclick", function (e) {
    if (mode !== "text") return;
    var el = e.target.closest && e.target.closest("[data-kz-text]");
    if (!el) return;
    e.preventDefault();
    beginEdit(el);
  });

  document.addEventListener("keydown", function (e) {
    if (!editing) return;
    if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); finishEdit(false); }
    else if (e.key === "Escape") { e.preventDefault(); finishEdit(true); }
  });

  document.addEventListener("focusout", function (e) {
    if (editing && e.target === editing.el) finishEdit(false);
  });

  /* Links and buttons are editable too, so suppress their normal action
     while a double-click edit is in progress. */
  document.addEventListener("click", function (e) {
    if (editing && editing.el.contains(e.target)) { e.preventDefault(); e.stopPropagation(); }
  }, true);

  /* ------------------------------------------------------------ background */

  function sectionFor(target) {
    var root = document.getElementById("dc-root");
    var el = target;
    while (el && el.parentElement && el.parentElement !== root) el = el.parentElement;
    return el && el.hasAttribute("data-kz-el") ? el : null;
  }

  var hovered = null;

  document.addEventListener("mousemove", function (e) {
    if (mode !== "background") return;
    var section = bar.contains(e.target) || (panel && panel.contains(e.target))
      ? null : sectionFor(e.target);
    if (section === hovered) return;
    if (hovered) hovered.classList.remove("kz-target");
    hovered = section;
    if (hovered) hovered.classList.add("kz-target");
  });

  document.addEventListener("click", function (e) {
    if (mode !== "background") return;
    if (bar.contains(e.target) || (panel && panel.contains(e.target))) return;
    var section = sectionFor(e.target);
    if (!section) return;
    e.preventDefault();
    e.stopPropagation();
    openPanel(section);
  }, true);

  function closePanel() {
    if (panel) { panel.remove(); panel = null; }
    if (hovered) { hovered.classList.remove("kz-target"); hovered = null; }
  }

  function openPanel(section) {
    closePanel();
    var tid = section.getAttribute("data-kz-el");
    var key = DC.page + "|[data-kz-el=\"" + tid + "\"]";
    var current = KZ.styles[key] || {};

    /* Only offered where it does something: the hero and a few other
       sections carry their photo as an <image-slot> child, which a background
       layer cannot reach. */
    var hasPhoto = !!section.querySelector("image-slot");

    panel = document.createElement("div");
    panel.id = "kz-panel";
    panel.innerHTML =
      "<h4>Hintergrund</h4>" +
      "<div style='font-size:11px;opacity:.55'>Abschnitt " + tid + "</div>" +
      "<label>Farbe</label>" +
      "<input type='color' value='" + (current.color || "#201d1a") + "'>" +
      "<label>Überlagerung</label>" +
      "<div class='kz-seg'>" +
      "<button data-ov='none'>Keine</button>" +
      "<button data-ov='dark'>Dunkel</button>" +
      "<button data-ov='light'>Hell</button></div>" +
      "<label>Stärke <span class='kz-val'></span></label>" +
      "<input type='range' class='kz-strength' min='0' max='95' step='5'>" +
      (hasPhoto
        ? "<label>Foto-Deckkraft <span class='kz-val2'></span></label>" +
          "<input type='range' class='kz-photo' min='10' max='100' step='5'>"
        : "") +
      "<div class='kz-drop'>Bild hierher ziehen<br><span style='opacity:.6'>oder klicken</span></div>" +
      "<div class='kz-row'><button data-act='clear'>Zurücksetzen</button>" +
      "<button data-act='close'>Fertig</button></div>";
    document.body.appendChild(panel);

    var color = panel.querySelector("input[type=color]");
    var drop = panel.querySelector(".kz-drop");
    var strength = panel.querySelector(".kz-strength");
    var photo = panel.querySelector(".kz-photo");
    var readout = panel.querySelector(".kz-val");
    var readout2 = panel.querySelector(".kz-val2");

    strength.value = Math.round((current.strength || 0) * 100);
    if (photo) photo.value = Math.round((current.photo == null ? 1 : current.photo) * 100);

    function showValues() {
      readout.textContent = strength.value + "%";
      if (readout2) readout2.textContent = photo.value + "%";
      panel.querySelectorAll("[data-ov]").forEach(function (b) {
        var isActive = (current.overlay || "none") === b.dataset.ov;
        b.setAttribute("aria-pressed", String(isActive));
      });
    }

    var file = document.createElement("input");
    file.type = "file";
    file.accept = "image/*";
    file.style.display = "none";
    panel.appendChild(file);

    function save(patch) {
      current = Object.assign({}, KZ.styles[key] || {}, current, patch);
      say("Speichern…", true);
      KZ.setStyle(key, current).then(function () { say("Gespeichert ✓"); },
                                     function (err) { say(err.message); });
      showValues();
    }

    /* Applied live while dragging, saved once on release, so the slider stays
       smooth instead of firing a request per step. */
    function live(patch) {
      current = Object.assign({}, current, patch);
      KZ.styles[key] = current;
      KZ.applyStyles();
      showValues();
    }

    color.addEventListener("input", function () { live({ color: color.value }); });
    color.addEventListener("change", function () { save({ color: color.value }); });

    strength.addEventListener("input", function () {
      live({ strength: Number(strength.value) / 100, overlay: current.overlay || "dark" });
    });
    strength.addEventListener("change", function () {
      save({ strength: Number(strength.value) / 100, overlay: current.overlay || "dark" });
    });

    if (photo) {
      photo.addEventListener("input", function () { live({ photo: Number(photo.value) / 100 }); });
      photo.addEventListener("change", function () { save({ photo: Number(photo.value) / 100 }); });
    }

    panel.addEventListener("click", function (e) {
      var choice = e.target.dataset && e.target.dataset.ov;
      if (!choice) return;
      if (choice === "none") save({ overlay: null, strength: 0 });
      else save({ overlay: choice, strength: Number(strength.value) / 100 || 0.35 });
      if (choice !== "none" && !Number(strength.value)) strength.value = 35;
      showValues();
    });

    showValues();

    function send(chosen) {
      if (!chosen) return;
      drop.textContent = "Wird hochgeladen…";
      KZ.upload("bg-" + DC.page.replace(/[^a-z0-9]+/gi, "-") + "-" + tid, chosen)
        .then(function (data) {
          drop.textContent = "Bild gesetzt ✓";
          save({ image: data.url });
        }, function (err) { drop.textContent = err.message; });
    }

    drop.addEventListener("click", function () { file.click(); });
    file.addEventListener("change", function () { send(file.files[0]); file.value = ""; });
    ["dragenter", "dragover"].forEach(function (type) {
      drop.addEventListener(type, function (e) { e.preventDefault(); drop.classList.add("over"); });
    });
    ["dragleave", "dragend"].forEach(function (type) {
      drop.addEventListener(type, function () { drop.classList.remove("over"); });
    });
    drop.addEventListener("drop", function (e) {
      e.preventDefault();
      drop.classList.remove("over");
      send(e.dataTransfer.files && e.dataTransfer.files[0]);
    });

    panel.addEventListener("click", function (e) {
      var act = e.target.dataset && e.target.dataset.act;
      if (act === "close") closePanel();
      if (act === "clear") {
        say("Speichern…", true);
        KZ.setStyle(key, {}).then(function () { say("Zurückgesetzt ✓"); closePanel(); },
                                  function (err) { say(err.message); });
      }
    });
  }

  /* --------------------------------------------------------------- wiring */

  window.addEventListener("dc:render", markText);
  markText();

  /* Loaded last so KZEditor.addMode exists by the time it registers. */
  var notes = document.createElement("script");
  notes.src = "notes.js";
  document.head.appendChild(notes);
})();
