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
 * an admin is signed in, which is also what pulls in editor.js.
 *
 * <image-slot> exposes ::part(frame) and ::part(empty); the pages style both.
 */
(function () {
  "use strict";

  /* Editing follows the admin session: sign in and every page is editable,
     sign out and the site is a read-only preview with no editing chrome on it
     at all. There is no key to type and no button offering to take one.

     The session cookie is httpOnly, so this cannot read it. The server sets a
     second cookie carrying one bit — "you are signed in" — because the slots
     have to know whether to attach their drop targets at the moment they are
     built, and that decision cannot wait for /api/state to answer. The bit is
     a hint for the interface, never a credential: forging it produces an
     editor whose every save the server refuses, and the real answer in
     state.canEdit replaces it as soon as it arrives. */
  var EDITOR_COOKIE = "kz_editor";

  function stored(name) {
    try { return localStorage.getItem(name); } catch (err) { return null; }
  }
  function remember(name, value) {
    try {
      if (value == null) localStorage.removeItem(name);
      else localStorage.setItem(name, value);
    } catch (err) { /* private browsing */ }
  }

  function signedIn() {
    return document.cookie.split(";").some(function (part) {
      return part.trim() === EDITOR_COOKIE + "=1";
    });
  }

  var EDIT = signedIn();
  var MAX_BYTES = 12 * 1024 * 1024;

  var state = { images: {}, texts: {}, styles: {}, notes: {}, slots: {}, theme: {} };
  var slots = new Set();
  var serverError = null;
  /* Set when the server has answered 401 — the session expired, or another
     tab signed out. The chrome then offers the way back in. */
  var sessionEnded = false;

  /* ----------------------------------------------------------- wording ---
     Every label in the editing chrome lives here, so the toolbar, the
     background panel, the notes and the sign-in card all follow the DE/EN
     switch in the site's own nav. Modules read through t() on each render
     rather than caching a language at load, and re-label on "kz:lang". */

  var STRINGS = {
    de: {
      edit: "Bearbeiten", comment: "Kommentar",
      guideTitle: "Bearbeitungsmodus",
      guideBody: "Text: Doppelklicken Sie auf einen Text, um ihn zu ändern. Bilder: Ziehen Sie ein Foto auf einen Platzhalter. Hintergrund: Abschnitt anklicken.",
      allImages: "Alle Bilder", stop: "Abmelden", gotIt: "Verstanden",
      signedOut: "Sitzung abgelaufen — bitte erneut anmelden",
      signIn: "Erneut anmelden",
      notSaving: "Änderungen werden nicht gespeichert",
      tooBig: "Bild zu groß, bitte kleiner als 12 MB",
      noServer: "Server nicht erreichbar -- bitte Verbindung prüfen und erneut versuchen",

      slotReplace: "Ersetzen", slotAdd: "Foto hinzufügen",
      uploading: "Wird hochgeladen…", dropHere: "Hier ablegen", failed: "Fehler",

      modeText: "Text", modeBackground: "Hintergrund", modeNotes: "Notizen",
      hintText: "Doppelklick auf einen Text zum Ändern. Fotos: einfach darauf ziehen.",
      hintBackground: "Klicken Sie auf einen Abschnitt, um Farbe oder Bild zu setzen.",
      hintNotes: "Klicken Sie irgendwo, um eine Notiz zu hinterlassen.",
      saving: "Speichern…", saved: "Gespeichert ✓", wasReset: "Zurückgesetzt ✓",

      bgTitle: "Hintergrund", bgSection: "Abschnitt", bgColor: "Farbe",
      bgOverlay: "Überlagerung", ovNone: "Keine", ovDark: "Dunkel", ovLight: "Hell",
      bgStrength: "Stärke", bgPhoto: "Foto-Deckkraft",
      bgDrop: "Bild hierher ziehen", bgDropSub: "oder klicken",
      ovCustom: "Eigene", ovColor: "Überlagerungsfarbe",
      bgText: "Textfarbe", txtAuto: "Auto", txtDark: "Dunkel", txtLight: "Hell",
      reset: "Zurücksetzen", done: "Fertig",

      modeStyle: "Stil", hintStyle: "Schrift und Rundungen für die ganze Website.",
      radiusTitle: "Ecken", radButtons: "Schaltflächen", radCards: "Karten", radFields: "Felder",
      fontsTitle: "Typografie", fontOriginal: "Original", fontModern: "Modern",
      fontClassic: "Klassisch",
      fontOriginalNote: "Playfair Display + EB Garamond",
      fontModernNote: "Cormorant Garamond + Jost",
      fontClassicNote: "Libre Baskerville + Lato",

      yourName: "Ihr Name", notePlaceholder: "Notiz schreiben…",
      post: "Senden", cancel: "Abbrechen", reply: "Antworten", replyBox: "Antwort…",
      resolve: "Erledigt", reopen: "Wieder öffnen", remove: "Löschen",
      confirmDelete: "Wirklich löschen?", confirmYes: "Ja, löschen",

      pgHome: "Home", pgApartments: "Apartments", pgApartment: "Apartment",
      pgBooking: "Buchung", pgProfile: "Profil", pgAbout: "Über uns", pgContact: "Kontakt"
    },
    en: {
      edit: "Edit", comment: "Comment",
      guideTitle: "Editing Guide",
      guideBody: "Text: double-click any text to change it. Images: drag a photo onto any placeholder. Background: click a section.",
      allImages: "All images", stop: "Sign out", gotIt: "Got it",
      signedOut: "Session expired — please sign in again",
      signIn: "Sign in again",
      notSaving: "Changes are not being saved",
      tooBig: "Image too large, please use one under 12 MB",
      noServer: "Could not reach the server -- check the connection and try again",

      slotReplace: "Replace", slotAdd: "Add photo",
      uploading: "Uploading…", dropHere: "Drop here", failed: "Failed",

      modeText: "Text", modeBackground: "Background", modeNotes: "Notes",
      hintText: "Double-click any text to change it. Photos: just drag one on.",
      hintBackground: "Click a section to set its colour or image.",
      hintNotes: "Click anywhere to leave a note.",
      saving: "Saving…", saved: "Saved ✓", wasReset: "Reset ✓",

      bgTitle: "Background", bgSection: "Section", bgColor: "Colour",
      bgOverlay: "Overlay", ovNone: "None", ovDark: "Dark", ovLight: "Light",
      bgStrength: "Strength", bgPhoto: "Photo opacity",
      bgDrop: "Drag an image here", bgDropSub: "or click",
      ovCustom: "Custom", ovColor: "Overlay colour",
      bgText: "Text colour", txtAuto: "Auto", txtDark: "Dark", txtLight: "Light",
      reset: "Reset", done: "Done",

      modeStyle: "Style", hintStyle: "Fonts and corner rounding for the whole site.",
      radiusTitle: "Corners", radButtons: "Buttons", radCards: "Cards", radFields: "Fields",
      fontsTitle: "Typography", fontOriginal: "Original", fontModern: "Modern",
      fontClassic: "Classic",
      fontOriginalNote: "Playfair Display + EB Garamond",
      fontModernNote: "Cormorant Garamond + Jost",
      fontClassicNote: "Libre Baskerville + Lato",

      yourName: "Your name", notePlaceholder: "Write a note…",
      post: "Post", cancel: "Cancel", reply: "Reply", replyBox: "Reply…",
      resolve: "Resolve", reopen: "Reopen", remove: "Delete",
      confirmDelete: "Delete this note?", confirmYes: "Yes, delete",

      pgHome: "Home", pgApartments: "Apartments", pgApartment: "Apartment",
      pgBooking: "Booking", pgProfile: "Profile", pgAbout: "About Us", pgContact: "Contact"
    }
  };

  function currentLang() {
    return stored("kz-lang") === "en" ? "en" : "de";
  }

  function t(key) {
    var pack = STRINGS[currentLang()];
    return (pack && pack[key]) || STRINGS.de[key] || key;
  }

  /* -------------------------------------------------------------- state */

  function loadFrom(url) {
    return fetch(url, { cache: "no-store" })
      .then(function (res) { return res.ok ? res.json() : null; })
      .catch(function () { return null; });
  }

  var apiReachable = true;

  var ready = loadFrom("/api/state")
    .then(function (data) {
      /* The cookie said the visitor was signed in and the server disagrees:
         it expired, or someone set it by hand. The chrome has already been
         built by then, so rather than leaving a dead editor on screen the
         cookie is cleared and the page reloaded once — the guard keeps that
         from becoming a loop if the two never agree. */
      if (data && data.canEdit === false && EDIT) {
        document.cookie = EDITOR_COOKIE + "=; Max-Age=0; path=/";
        try {
          if (!sessionStorage.getItem("kz-recheck")) {
            sessionStorage.setItem("kz-recheck", "1");
            location.reload();
          }
        } catch (err) { /* private browsing: leave the page as it is */ }
      } else if (data && data.canEdit) {
        try { sessionStorage.removeItem("kz-recheck"); } catch (err) { /* ignore */ }
      }

      if (data) return data;
      /* Either there is no backend — a plain clone, where the checked-in
         state is the right answer — or the deployment is broken. Which one
         it is only becomes clear when the fallback is missing too. */
      apiReachable = false;
      return loadFrom("assets/uploads/state.json");
    })
    .then(function (data) {
      if (data) {
        state.images = data.images || {};
        state.texts = data.texts || {};
        state.styles = data.styles || {};
        state.notes = data.notes || {};
        state.slots = data.slots || {};
        state.theme = data.theme || {};
      }
      /* A server that cannot reach its storage still answers, with the reason.
         Kept so the editing chrome can say so plainly rather than letting the
         first upload fail with an SDK message. */
      serverError = data && data.ok === false ? data.error
        : (!apiReachable && !data) ? t("noServer")
        : null;
      applyStyles();
      applyFonts();
      applyRadius();
      slots.forEach(function (el) { el.refresh(); });
      return state;
    });

  /* Background overrides are injected as a stylesheet rather than written
     onto the elements. Inline styles are owned by the template diff, and a
     re-render would wipe anything written there. */
  var sheet = null;

  /* The tint needs an alpha channel, so a picked #rrggbb becomes rgba(). */
  function hexToRgb(hex) {
    if (typeof hex !== "string") return null;
    var m = hex.trim().replace("#", "");
    if (m.length === 3) m = m[0] + m[0] + m[1] + m[1] + m[2] + m[2];
    if (!/^[0-9a-f]{6}$/i.test(m)) return null;
    return parseInt(m.slice(0, 2), 16) + "," +
           parseInt(m.slice(2, 4), 16) + "," +
           parseInt(m.slice(4, 6), 16);
  }

  /* Alternative pairings for the whole site. "original" is the design's own
     and stores nothing. Each is a Google font already suited to the brand's
     heritage feel. */
  /* Only the weights the design actually uses — a heading weight with its
     italic, and two body weights. Asking for more just makes the switch feel
     slow while extra files download. */
  var FONTS = {
    modern: {
      display: "'Cormorant Garamond',Georgia,serif",
      body: "'Jost',system-ui,sans-serif",
      url: "https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,600;0,700;1,600&family=Jost:wght@400;500&display=swap"
    },
    classic: {
      display: "'Libre Baskerville',Georgia,serif",
      body: "'Lato',system-ui,sans-serif",
      url: "https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Lato:wght@400;700&display=swap"
    }
  };

  var fontLink = null;

  /* The pages preconnect to fonts.googleapis.com but not to fonts.gstatic.com,
     where the files themselves are served — so the first switch paid for a
     fresh DNS lookup and TLS handshake before a byte of font arrived. */
  function preconnectFonts() {
    if (document.getElementById("kz-font-preconnect")) return;
    var link = document.createElement("link");
    link.id = "kz-font-preconnect";
    link.rel = "preconnect";
    link.href = "https://fonts.gstatic.com";
    link.crossOrigin = "anonymous";
    document.head.appendChild(link);
  }

  /* The design writes its fonts inline on hundreds of elements, so they are
     re-pointed by matching the normalised style attribute rather than by
     touching the markup. support.js serialises declarations without spaces,
     which makes these selectors stable. */
  function applyFonts() {
    var choice = state.theme && state.theme.fonts;
    var set = FONTS[choice];

    if (!set) {
      if (fontLink) { fontLink.remove(); fontLink = null; }
      var old = document.getElementById("kz-font-overrides");
      if (old) old.remove();
      return;
    }

    if (!fontLink) {
      preconnectFonts();
      fontLink = document.createElement("link");
      fontLink.rel = "stylesheet";
      document.head.appendChild(fontLink);
    }
    if (fontLink.href !== set.url) fontLink.href = set.url;

    var rules = document.getElementById("kz-font-overrides");
    if (!rules) {
      rules = document.createElement("style");
      rules.id = "kz-font-overrides";
      document.head.appendChild(rules);
    }
    rules.textContent = [
      "#dc-root [style*=\"font-family:'Playfair Display'\"]{font-family:" + set.display + " !important;}",
      "#dc-root [style*=\"font-family:'EB Garamond'\"]{font-family:" + set.body + " !important;}",
      "#dc-root{font-family:" + set.body + " !important;}"
    ].join("\n");
  }

  /* Corner rounding, grouped the way the design uses it. 50% is deliberately
     left out: those are avatars and circular icons, which should stay round.
     Matching on the normalised style attribute avoids touching the markup,
     the same way the fonts do. */
  var RADIUS_GROUPS = {
    radiusButton: ["999px"],
    radiusCard: ["2px", "10px", "12px", "14px", "16px"],
    radiusField: ["4px", "6px", "8px"]
  };

  function applyRadius() {
    var rules = document.getElementById("kz-radius-overrides");
    var css = "";

    for (var group in RADIUS_GROUPS) {
      var value = state.theme && state.theme[group];
      if (typeof value !== "number") continue;
      var selectors = RADIUS_GROUPS[group].map(function (px) {
        return "#dc-root [style*=\"border-radius:" + px + "\"]";
      });
      css += selectors.join(",") + "{border-radius:" + value + "px !important;}\n";
    }

    if (!css) { if (rules) rules.remove(); return; }
    if (!rules) {
      rules = document.createElement("style");
      rules.id = "kz-radius-overrides";
      document.head.appendChild(rules);
    }
    rules.textContent = css;
  }

  /* Making a section's text readable on a changed background. Rather than
     forcing one colour on everything — which would flatten the gold accents
     and the gradient headings — the design's own text tokens are swapped for
     their opposite number. Anything not listed keeps its colour. */
  var TEXT_MAP = {
    light: [                       /* for a background made dark */
      ["#2b241d", "#f7f1e6"],
      ["#4a4038", "#f2ecdf"],
      ["#8a5a2f", "#d9a868"]
    ],
    dark: [                        /* for a background made light */
      ["#f7f1e6", "#2b241d"],
      ["#f2ecdf", "#4a4038"],
      ["#e9e0d0", "#4a4038"],
      ["rgba(242,236,224,", "rgba(74,64,56,0.8)"],
      ["rgba(233,224,208,", "rgba(74,64,56,0.8)"],
      ["#d9a868", "#8a5a2f"]
    ]
  };

  /* "background-color:#f2ecdf" contains "color:#f2ecdf", so a plain substring
     match would recolour text on elements that merely have that background.
     A declaration only ever starts the attribute or follows a semicolon. */
  function colourSelectors(scope, value) {
    return scope + ' [style^="color:' + value + '"],' +
           scope + ' [style*=";color:' + value + '"]';
  }

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

      /* The tint is a background layer rather than an overlay element, so it
         sits behind the text instead of dimming it. Layers paint front to
         back, so the tint has to come first to land on top of the photo. */
      var layers = [];
      if (value.overlay && value.strength > 0) {
        var rgb = value.overlay === "light" ? "255,255,255"
          : value.overlay === "custom" ? hexToRgb(value.overlayColor) || "0,0,0"
          : "0,0,0";
        var tint = "rgba(" + rgb + "," + value.strength + ")";
        layers.push("linear-gradient(" + tint + "," + tint + ")");
      }
      if (value.image) layers.push("url('" + value.image.replace(/'/g, "%27") + "')");

      if (layers.length) {
        decls += "background-image:" + layers.join(",") + " !important;";
        if (value.image) {
          decls += "background-size:cover !important;background-position:center !important;" +
                   "background-repeat:no-repeat !important;";
        }
      } else if (value.image === "") {
        decls += "background-image:none !important;";
      }

      if (decls) css += selector + "{" + decls + "}\n";

      /* Sections whose photo is an <image-slot> child, like the hero, are not
         reached by a background layer — dim the element itself instead. */
      if (typeof value.photo === "number") {
        css += selector + " image-slot{opacity:" + value.photo + " !important;}\n";
      }

      var swaps = TEXT_MAP[value.text];
      if (swaps) {
        for (var i = 0; i < swaps.length; i++) {
          css += colourSelectors(selector, swaps[i][0]) +
                 "{color:" + swaps[i][1] + " !important;}\n";
        }
      }
    }
    sheet.textContent = css;
  }

  /* ------------------------------------------------------------ writing */

  function failed(res, data) {
    /* 401 now means the session has gone — it expired, or it was signed out
       in another tab. Nothing local can fix that, so say so plainly and let
       signOut() offer the way back rather than failing silently over and
       over. */
    if (res.status === 401) {
      sessionEnded = true;
      window.dispatchEvent(new CustomEvent("kz:signed-out"));
      return new Error(t("signedOut"));
    }
    /* 503 means the server is fine but its storage is not wired up; keep that
       message, it names the variable to add. */
    if (res.status === 503 && data.error) serverError = data.error;
    return new Error(data.error || "Fehler / failed (" + res.status + ")");
  }

  /* Serverless functions reject a request body over about 4.5 MB before it
     ever reaches the handler, and the browser reports that as a bare "Failed
     to fetch". Photos straight off a phone are routinely larger, so they are
     resized here first. A preview never needs more than this, and it makes
     uploads quick as well as possible. */
  var MAX_EDGE = 2200;
  var SHRINK_OVER = 3 * 1024 * 1024;

  function shrink(file) {
    if (file.size <= SHRINK_OVER || file.type === "image/svg+xml") {
      return Promise.resolve(file);
    }
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        URL.revokeObjectURL(url);
        var scale = Math.min(1, MAX_EDGE / Math.max(img.width, img.height));
        var canvas = document.createElement("canvas");
        canvas.width = Math.round(img.width * scale);
        canvas.height = Math.round(img.height * scale);
        canvas.getContext("2d").drawImage(img, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function (blob) {
          /* Keep the original if re-encoding somehow made it bigger. */
          resolve(blob && blob.size < file.size ? blob : file);
        }, "image/jpeg", 0.85);
      };
      /* A format the browser cannot decode goes up untouched and is caught by
         the size check below. */
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  function upload(slot, file) {
    if (!EDIT) return Promise.reject(new Error(t("signedOut")));
    if (!/^image\//.test(file.type)) return Promise.reject(new Error("Nur Bilder / images only"));

    return shrink(file).then(function (payload) {
      if (payload.size > MAX_BYTES) throw new Error(t("tooBig"));
      var type = payload.type || file.type;

      /* The session cookie is what authorises this. same-origin is fetch's
         default, but it is spelled out because the whole editor depends on
         the cookie riding along. */
      return fetch("/api/upload?slot=" + encodeURIComponent(slot), {
        method: "POST",
        credentials: "same-origin",
        headers: { "content-type": type },
        body: payload
      }).catch(function () {
        /* fetch only rejects on a network-level failure; the server never
           answered, so say something more useful than "Failed to fetch". */
        throw new Error(t("noServer"));
      }).then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
          if (!res.ok) throw failed(res, data);
          return data;
        });
      });
    });
  }

  /* Saves are queued so two quick edits cannot interleave their
     read-modify-write on the server and lose one another. */
  var queue = Promise.resolve();

  function saveContent(patch) {
    if (!EDIT) return Promise.reject(new Error(t("signedOut")));

    queue = queue.then(function () {
      return fetch("/api/content", {
        method: "POST",
        credentials: "same-origin",
        headers: { "content-type": "application/json" },
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

  /* Notes are keyed by their own id, so two people commenting in different
     places merge instead of overwriting each other. */
  function setNote(id, value) {
    if (value == null) delete state.notes[id];
    else state.notes[id] = value;
    window.dispatchEvent(new CustomEvent("kz:notes"));
    var patch = { notes: {} };
    patch.notes[id] = value == null ? null : value;
    return saveContent(patch);
  }

  /* How a photo sits in its frame. Keyed by slot id rather than by page,
     because the same slot shows on more than one page and should look the
     same in both. */
  function setSlot(id, value) {
    if (value == null) delete state.slots[id];
    else state.slots[id] = value;
    slots.forEach(function (el) { if (el.slotId === id) el.refresh(); });
    var patch = { slots: {} };
    patch.slots[id] = value == null ? null : value;
    return saveContent(patch);
  }

  /* null for a group means "back to the design's own values". */
  function setRadius(group, value) {
    if (value == null) delete state.theme[group];
    else state.theme[group] = value;
    applyRadius();
    var patch = { theme: {} };
    patch.theme[group] = value == null ? null : value;
    return saveContent(patch);
  }

  /* Site-wide, so unlike the others it is not keyed by page or slot. */
  function setFonts(choice) {
    if (!choice || choice === "original") delete state.theme.fonts;
    else state.theme.fonts = choice;
    applyFonts();
    return saveContent({ theme: { fonts: choice || "original" } });
  }

  function publishImage(slot, url) {
    state.images[slot] = url;
    slots.forEach(function (el) { if (el.slotId === slot) el.refresh(); });
    window.dispatchEvent(new CustomEvent("kz:image", { detail: { slot: slot, url: url } }));
  }

  /* -------------------------------------------------------- <image-slot> */

  var STYLE = [
    /* Author rules beat the browser's own [hidden]{display:none}, so the
       rules below would otherwise keep both the <img> and the placeholder
       painted at once — an uploaded photo ended up sitting underneath the
       placeholder box, looking like it had not arrived. */
    "[hidden]{display:none!important;}",
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
    ".err{color:#e8a598;font-size:10px;}",
    /* Fit controls, shown only on a slot that already holds a photo and is
       big enough for them to be usable. */
    ".tools{display:none;gap:4px;flex-wrap:wrap;justify-content:center;margin-top:6px;}",
    ".edit.tunable .tools{display:flex;}",
    ".tools button{border:1px solid rgba(242,236,224,0.35);background:rgba(0,0,0,0.35);",
    "color:#f2ecdf;border-radius:999px;padding:3px 8px;font:inherit;font-size:10px;",
    "cursor:pointer;line-height:1.4;}",
    ".tools button[aria-pressed=true]{background:linear-gradient(135deg,#e6c184,#b9803f);",
    "color:#201d1a;border-color:transparent;}",
    ".tools input[type=range]{width:82px;accent-color:#d9a868;margin:0;}"
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

        /* The design picks a default per slot with fit="…"; the client can
           override it, zoom in, and choose which edge survives the crop. */
        var cfg = state.slots[this.slotId] || {};
        this.img.style.objectFit = cfg.fit || this.getAttribute("fit") || "cover";
        this.img.style.objectPosition = "center " + (cfg.position || "center");
        this.img.style.transform = cfg.zoom && cfg.zoom !== 1 ? "scale(" + cfg.zoom + ")" : "";
      } else {
        this.img.hidden = true;
        this.img.removeAttribute("src");
        this.empty.hidden = false;
        this.empty.textContent = this.getAttribute("placeholder") || "Foto";
      }
      if (this.editor) {
        this.editor.label.textContent = url ? t("slotReplace") : t("slotAdd");
        this.editor.sync(url);
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

      /* Fit, zoom and crop edge. Clicks are stopped here so they adjust the
         photo instead of reopening the file picker underneath. */
      var tools = document.createElement("div");
      tools.className = "tools";
      tools.addEventListener("click", function (e) { e.stopPropagation(); });
      box.appendChild(tools);

      function config() { return state.slots[self.slotId] || {}; }

      function apply(patch) {
        var next = Object.assign({}, config(), patch);
        /* Back to the design's own default rather than storing a no-op. */
        if (next.fit === (self.getAttribute("fit") || "cover")) delete next.fit;
        if (next.zoom === 1) delete next.zoom;
        if (next.position === "center") delete next.position;
        setSlot(self.slotId, Object.keys(next).length ? next : null);
      }

      function pill(label, isOn, onPick) {
        var b = document.createElement("button");
        b.type = "button";
        b.textContent = label;
        b.setAttribute("aria-pressed", String(isOn()));
        b.addEventListener("click", function (e) { e.stopPropagation(); onPick(); });
        return b;
      }

      var fitCover = pill("Cover", function () { return (config().fit || self.getAttribute("fit") || "cover") === "cover"; },
        function () { apply({ fit: "cover" }); });
      var fitContain = pill("Fit", function () { return config().fit === "contain"; },
        function () { apply({ fit: "contain" }); });
      var posTop = pill("↑", function () { return config().position === "top"; },
        function () { apply({ position: config().position === "top" ? "center" : "top" }); });
      var posBottom = pill("↓", function () { return config().position === "bottom"; },
        function () { apply({ position: config().position === "bottom" ? "center" : "bottom" }); });

      var zoom = document.createElement("input");
      zoom.type = "range";
      zoom.min = "100";
      zoom.max = "300";
      zoom.step = "5";

      /* Saving anything triggers a refresh, which re-reads the stored value.
         Without this the slider would snap back under the user's finger if a
         save landed mid-drag. */
      var tuning = false;

      zoom.addEventListener("click", function (e) { e.stopPropagation(); });
      zoom.addEventListener("input", function (e) {
        e.stopPropagation();
        tuning = true;
        /* Live while dragging, saved on release, so a drag is one request. */
        self.img.style.transform = "scale(" + (Number(zoom.value) / 100) + ")";
      });
      zoom.addEventListener("change", function (e) {
        e.stopPropagation();
        tuning = false;
        apply({ zoom: Number(zoom.value) / 100 });
      });

      [fitCover, fitContain, posTop, posBottom, zoom].forEach(function (n) { tools.appendChild(n); });

      this.editor = {
        box: box, label: label, tag: tag,
        sync: function (url) {
          /* Too small to be worth it on a 36px avatar. */
          box.classList.toggle("tunable", !!url && self.clientWidth >= 110 && self.clientHeight >= 70);
          var cfg = config();
          if (!tuning) zoom.value = String(Math.round((cfg.zoom || 1) * 100));
          fitCover.setAttribute("aria-pressed", String((cfg.fit || self.getAttribute("fit") || "cover") === "cover"));
          fitContain.setAttribute("aria-pressed", String(cfg.fit === "contain"));
          posTop.setAttribute("aria-pressed", String(cfg.position === "top"));
          posBottom.setAttribute("aria-pressed", String(cfg.position === "bottom"));
        }
      };
      this.frame.appendChild(box);

      var input = document.createElement("input");
      input.type = "file";
      input.accept = "image/*";
      input.style.display = "none";
      this.shadowRoot.appendChild(input);

      function send(file) {
        if (!file) return;
        box.classList.add("busy");
        box.classList.remove("over");
        label.textContent = t("uploading");
        tag.textContent = self.slotId;
        upload(self.slotId, file).then(function (data) {
          box.classList.remove("busy");
          publishImage(self.slotId, data.url);
        }, function (err) {
          box.classList.remove("busy");
          label.textContent = t("failed");
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
          label.textContent = t("dropHere");
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

  /* Their drop-target captions are localised too. */
  window.addEventListener("kz:lang", function () {
    slots.forEach(function (el) { el.refresh(); });
  });

  window.KZ = {
    ready: ready,
    edit: EDIT,
    signOut: signOut,
    state: state,
    get images() { return state.images; },
    get texts() { return state.texts; },
    get styles() { return state.styles; },
    get notes() { return state.notes; },
    get slots() { return state.slots; },
    get theme() { return state.theme; },
    get serverError() { return serverError; },
    t: t,
    lang: currentLang,
    upload: upload,
    setText: setText,
    setStyle: setStyle,
    setNote: setNote,
    setSlot: setSlot,
    setFonts: setFonts,
    stored: stored,
    remember: remember,
    publishImage: publishImage,
    applyStyles: applyStyles,
    applyFonts: applyFonts,
    applyRadius: applyRadius,
    setRadius: setRadius
  };

  /* ---------------------------------------------------------- editor dock --
     Built only for someone who is signed in. A visitor sees the design and
     nothing else: no Edit button, no panel, no invitation to type a key —
     there is no key to type. Signing in at /login is the only entrance, and
     it turns editing on everywhere at once.

     The design's own "Edit" button in the bottom-left corner was a demo
     explainer that did nothing; it stays hidden either way, so a visitor is
     never offered a control that leads nowhere. */

  function buildDock() {

    var style = document.createElement("style");
    style.textContent = [
      /* Clears the editor toolbar, which is always on screen here. */
      "#kz-signin{position:fixed;bottom:74px;left:26px;",
      "z-index:61;font-family:'EB Garamond',Georgia,serif;}",
      "#kz-signin .row{display:flex;gap:8px;align-items:center;}",
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

    /* Where the "Bearbeiten" button used to sit. It no longer offers to start
       editing — editing has already started — so all it carries now is the
       guide to how, and the way back out. A question mark rather than a word,
       because it is the smaller of the two controls here and the one reached
       least often. */
    var trigger = document.createElement("button");
    trigger.className = "trigger";
    trigger.setAttribute("aria-label", t("guideTitle"));
    trigger.innerHTML =
      "<svg width='14' height='14' viewBox='0 0 24 24' fill='none'>" +
      "<circle cx='12' cy='12' r='9' stroke='#d9a868' stroke-width='1.6'/>" +
      "<path d='M9.6 9.2a2.4 2.4 0 1 1 3.2 2.3c-.6.2-.8.7-.8 1.3v.4' " +
      "stroke='#d9a868' stroke-width='1.6' stroke-linecap='round'/>" +
      "<circle cx='12' cy='16.4' r='.9' fill='#d9a868'/></svg>";

    /* Commenting is the thing the client does most, so it gets its own
       button rather than being buried a mode-click deep in the toolbar. */
    var comment = document.createElement("button");
    comment.className = "trigger";
    comment.innerHTML =
      "<svg width='14' height='14' viewBox='0 0 24 24' fill='none'>" +
      "<path d='M21 12a8 8 0 0 1-8 8H7l-4 3v-4.5A8 8 0 0 1 11 4h2a8 8 0 0 1 8 8z' " +
      "stroke='#d9a868' stroke-width='1.6' stroke-linejoin='round'/></svg><span></span>";
    comment.querySelector("span").textContent = t("comment");

    comment.addEventListener("click", function (e) {
      e.stopPropagation();
      panel.hidden = true;
      if (window.KZEditor) window.KZEditor.setMode("notes");
    });

    var panel = document.createElement("div");
    panel.className = "panel";
    panel.hidden = true;

    var row = document.createElement("div");
    row.className = "row";
    row.appendChild(trigger);
    row.appendChild(comment);

    host.appendChild(panel);
    host.appendChild(row);
    document.body.appendChild(host);

    function render() {
      panel.textContent = "";

      var title = document.createElement("h4");
      title.textContent = t("guideTitle");
      var body = document.createElement("p");
      body.textContent = sessionEnded ? t("signedOut") : t("guideBody");
      panel.appendChild(title);
      panel.appendChild(body);

      /* The session went while the page stayed open. Nothing on this page can
         mend that, so the panel stops being a guide and becomes the way back
         to the sign-in form, returning here afterwards. */
      if (sessionEnded) {
        var again = document.createElement("a");
        again.className = "go";
        again.style.display = "block";
        again.style.textAlign = "center";
        again.style.textDecoration = "none";
        again.href = "/login?next=" + encodeURIComponent(location.pathname + location.search);
        again.textContent = t("signIn");
        panel.appendChild(again);
        return;
      }

      var done = document.createElement("button");
      done.className = "go";
      done.textContent = t("gotIt");
      done.addEventListener("click", function () { panel.hidden = true; });
      panel.appendChild(done);

      var links = document.createElement("div");
      links.className = "links";

      var admin = document.createElement("a");
      admin.href = "/photos";
      admin.textContent = t("allImages");

      /* Signing out is a state change on the server, so it is a POST, not a
         link someone's link-prefetcher can fire by accident. */
      var stop = document.createElement("button");
      stop.textContent = t("stop");
      stop.addEventListener("click", signOut);

      links.appendChild(admin);
      links.appendChild(stop);
      panel.appendChild(links);
    }

    trigger.addEventListener("click", function () {
      if (panel.hidden) render();
      panel.hidden = !panel.hidden;
    });

    window.addEventListener("kz:lang", function () {
      trigger.setAttribute("aria-label", t("guideTitle"));
      comment.querySelector("span").textContent = t("comment");
      if (!panel.hidden) render();
    });

    window.addEventListener("kz:signed-out", function () {
      panel.hidden = false;
      render();
    });

    document.addEventListener("click", function (e) {
      if (!panel.hidden && !host.contains(e.target)) panel.hidden = true;
    });
  }

  /* The prototype shipped an "Edit" button in the bottom-left corner that
     opened a demo explainer and did nothing else. It is hidden for everyone,
     signed in or not: for an admin the dock takes its place, and a visitor
     should never be offered a control that leads nowhere. This runs outside
     buildDock precisely because it has to happen for visitors too. */
  function hidePrototypeButton() {
    var style = document.createElement("style");
    style.textContent =
      '#dc-root [style*="bottom:26px"][style*="left:26px"]{display:none!important;}';
    (document.head || document.documentElement).appendChild(style);
  }

  /* Posted rather than linked, so no prefetcher can sign the client out by
     touching a URL. The form is built and submitted rather than fetched
     because the response is a redirect the browser should follow.

     The token is asked for at the moment of the click rather than kept from
     page load: a tab left open all afternoon would otherwise carry a token
     from a session that has since been rotated, and the sign-out would fail
     with a 419 at the one moment the client is trying to leave. */
  function signOut() {
    loadFrom("/api/state").then(function (data) {
      var form = document.createElement("form");
      form.method = "post";
      form.action = "/logout";
      form.style.display = "none";

      var token = document.createElement("input");
      token.type = "hidden";
      token.name = "_token";
      token.value = (data && data.csrf) || "";
      form.appendChild(token);

      document.body.appendChild(form);
      form.submit();
    });
  }

  function boot() {
    hidePrototypeButton();

    /* A visitor gets the design and nothing else. Every piece of editing
       chrome below this line exists only for a signed-in admin. */
    if (!EDIT) return;

    var editor = document.createElement("script");
    editor.src = "editor.js";
    document.head.appendChild(editor);

    buildDock();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
