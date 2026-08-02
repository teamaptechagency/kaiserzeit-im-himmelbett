/*
 * notes.js — pinned comments, for the back-and-forth between agency and
 * client while the preview is being reviewed.
 *
 * Switch to "Notizen" and click anywhere: next to a heading, on a photo, in
 * the margin. A pin drops there and everyone else in edit mode sees it, with
 * replies, a resolved state, and delete.
 *
 * A pin is anchored to the nearest element carrying data-kz-el plus a
 * fraction of that element's box, rather than to page coordinates. Page
 * coordinates would drift the moment a photo loads at a different height or
 * the window is resized; anchoring to the element keeps the pin on the thing
 * it is talking about, at any width.
 *
 * Loaded by editor.js, so it only exists in edit mode.
 */
(function () {
  "use strict";

  var KZ = window.KZ;
  var DC = window.DC;
  var UI = window.KZEditor;
  if (!KZ || !DC || !UI) return;

  var GOLD = "#d9a868";
  var AUTHOR_KEY = "kz-author";
  var active = false;
  var open = null;      /* id of the note whose card is open */
  var draft = null;     /* an unsaved pin being written */

  /* Wording comes from the shared dictionary in image-slot.js and is read at
     render time, so the cards follow the site's DE/EN switch rather than
     freezing whatever language the page loaded in. */
  var T = KZ.t;

  /* ---------------------------------------------------------------- chrome */

  var css = document.createElement("style");
  css.textContent = [
    "#kz-notes{position:absolute;top:0;left:0;width:100%;height:0;z-index:99998;}",
    "#kz-notes > *{position:absolute;pointer-events:auto;}",
    "body.kz-notes{cursor:crosshair;}",
    ".kz-pin{width:26px;height:26px;margin:-13px 0 0 -13px;border-radius:50% 50% 50% 2px;",
    "background:linear-gradient(135deg,#e6c184,#b9803f);color:#201d1a;border:none;",
    "font:600 12px/26px 'Playfair Display',serif;text-align:center;cursor:pointer;",
    "box-shadow:0 4px 12px rgba(0,0,0,.45);padding:0;}",
    ".kz-pin.done{background:#4d7a3d;color:#f2ecdf;opacity:.75;}",
    ".kz-pin.open{outline:2px solid #f2ecdf;outline-offset:2px;}",
    ".kz-card{width:min(84vw,280px);margin:16px 0 0 -13px;background:#1e1a17;",
    "border:1px solid rgba(185,128,63,.4);border-radius:12px;padding:14px;",
    "box-shadow:0 16px 36px rgba(0,0,0,.5);color:#f2ecdf;",
    "font:14px/1.5 'EB Garamond',Georgia,serif;}",
    ".kz-card .msg{margin-bottom:10px;}",
    ".kz-card .who{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:" + GOLD + ";}",
    ".kz-card .when{font-size:11px;color:rgba(242,236,224,.4);margin-left:6px;text-transform:none;letter-spacing:0;}",
    ".kz-card .body{white-space:pre-wrap;overflow-wrap:break-word;}",
    ".kz-card textarea,.kz-card input{width:100%;box-sizing:border-box;background:rgba(0,0,0,.3);",
    "border:1px solid rgba(185,128,63,.25);border-radius:8px;padding:8px 10px;color:#f2ecdf;",
    "font:inherit;outline:none;resize:vertical;margin-bottom:8px;}",
    ".kz-card textarea:focus,.kz-card input:focus{border-color:rgba(185,128,63,.6);}",
    ".kz-card .row{display:flex;gap:8px;}",
    ".kz-card button{flex:1;font:inherit;cursor:pointer;border-radius:999px;padding:7px 10px;",
    "border:1px solid rgba(185,128,63,.45);background:transparent;color:#f2ecdf;}",
    ".kz-card button.primary{border:none;background:linear-gradient(135deg,#e6c184,#b9803f);",
    "color:#201d1a;font-family:'Playfair Display',serif;font-weight:600;}",
    ".kz-card .links{display:flex;gap:12px;margin-top:10px;font-size:12px;}",
    ".kz-card .links button{flex:0 0 auto;border:none;padding:0;background:none;color:" + GOLD + ";}",
    ".kz-card .links button.danger{color:#e8a598;}",
    ".kz-card hr{border:none;border-top:1px solid rgba(185,128,63,.2);margin:10px 0;}",
    "#kz-bar .kz-count{display:inline-block;min-width:17px;padding:0 4px;margin-left:6px;",
    "border-radius:999px;background:" + GOLD + ";color:#201d1a;font-size:11px;text-align:center;}"
  ].join("");
  document.head.appendChild(css);

  var layer = document.createElement("div");
  layer.id = "kz-notes";
  document.body.appendChild(layer);

  var button = UI.addMode("notes", "modeNotes", {
    hintKey: "hintNotes",
    enter: function () { active = true; document.body.classList.add("kz-notes"); paint(); },
    exit: function () {
      active = false;
      document.body.classList.remove("kz-notes");
      draft = null; open = null;
      paint();
    }
  });

  /* --------------------------------------------------------------- helpers */

  function author() {
    return KZ.stored(AUTHOR_KEY) || "";
  }

  function mine() {
    return Object.keys(KZ.notes)
      .filter(function (id) { return KZ.notes[id].page === DC.page; })
      .sort(function (a, b) { return (KZ.notes[a].at || 0) - (KZ.notes[b].at || 0); });
  }

  function badge() {
    var list = mine();
    var openCount = list.filter(function (id) { return !KZ.notes[id].resolved; }).length;
    var tag = button.querySelector(".kz-count");
    if (!openCount) { if (tag) tag.remove(); return; }
    if (!tag) {
      tag = document.createElement("span");
      tag.className = "kz-count";
      button.appendChild(tag);
    }
    tag.textContent = String(openCount);
  }

  function anchorOf(note) {
    if (!note.el || note.el === "root") return document.getElementById("dc-root");
    return document.querySelector('[data-kz-el="' + note.el + '"]') ||
           document.getElementById("dc-root");
  }

  /* Document coordinates, so pins do not need repositioning on scroll. */
  function place(el, note) {
    var anchor = anchorOf(note);
    if (!anchor) return false;
    var box = anchor.getBoundingClientRect();
    if (!box.width && !box.height) return false;   /* inside a closed sc-if */
    el.style.left = (box.left + window.scrollX + note.x * box.width) + "px";
    el.style.top = (box.top + window.scrollY + note.y * box.height) + "px";
    return true;
  }

  function when(at) {
    if (!at) return "";
    /* Resolved per call, so timestamps re-format when the language changes. */
    var locale = KZ.lang() === "en" ? "en-GB" : "de-DE";
    var d = new Date(at);
    return d.toLocaleDateString(locale, { day: "numeric", month: "short" }) +
      " " + d.toLocaleTimeString(locale, { hour: "2-digit", minute: "2-digit" });
  }

  function line(who, at, text) {
    var wrap = document.createElement("div");
    wrap.className = "msg";
    var head = document.createElement("div");
    head.className = "who";
    head.textContent = who || "—";
    var stamp = document.createElement("span");
    stamp.className = "when";
    stamp.textContent = when(at);
    head.appendChild(stamp);
    var body = document.createElement("div");
    body.className = "body";
    body.textContent = text;
    wrap.appendChild(head);
    wrap.appendChild(body);
    return wrap;
  }

  function save(id, value, done) {
    UI.say(T("saving"), true);
    KZ.setNote(id, value).then(function () {
      UI.say(T("saved"));
      if (done) done();
      paint();
    }, function (err) {
      UI.say(err.message);
      paint();
    });
  }

  /* ---------------------------------------------------------------- render */

  function paint() {
    layer.textContent = "";
    badge();
    if (!active) return;

    mine().forEach(function (id, index) {
      var note = KZ.notes[id];

      var pin = document.createElement("button");
      pin.className = "kz-pin" + (note.resolved ? " done" : "") + (open === id ? " open" : "");
      pin.textContent = String(index + 1);
      pin.title = (note.author ? note.author + ": " : "") + note.text;
      if (!place(pin, note)) return;
      pin.addEventListener("click", function (e) {
        e.stopPropagation();
        open = open === id ? null : id;
        paint();
      });
      layer.appendChild(pin);

      if (open === id) layer.appendChild(card(id, note, pin));
    });

    if (draft) layer.appendChild(composer());
  }

  function card(id, note, pin) {
    var box = document.createElement("div");
    box.className = "kz-card";
    box.style.left = pin.style.left;
    box.style.top = pin.style.top;
    box.addEventListener("click", function (e) { e.stopPropagation(); });

    box.appendChild(line(note.author, note.at, note.text));
    (note.replies || []).forEach(function (reply) {
      box.appendChild(line(reply.author, reply.at, reply.text));
    });

    box.appendChild(document.createElement("hr"));

    var input = document.createElement("textarea");
    input.rows = 2;
    input.placeholder = T("replyBox");
    box.appendChild(input);

    var row = document.createElement("div");
    row.className = "row";
    var post = document.createElement("button");
    post.className = "primary";
    post.textContent = T("reply");
    post.addEventListener("click", function () {
      var text = input.value.trim();
      if (!text) return input.focus();
      var next = JSON.parse(JSON.stringify(note));
      next.replies = (next.replies || []).concat([
        { author: author(), text: text, at: Date.now() }
      ]);
      save(id, next);
    });
    row.appendChild(post);
    box.appendChild(row);

    var links = document.createElement("div");
    links.className = "links";

    var resolve = document.createElement("button");
    resolve.textContent = note.resolved ? T("reopen") : T("resolve");
    resolve.addEventListener("click", function () {
      var next = JSON.parse(JSON.stringify(note));
      next.resolved = !next.resolved;
      save(id, next);
    });

    var remove = document.createElement("button");
    remove.className = "danger";
    remove.textContent = T("remove");
    remove.addEventListener("click", function () {
      if (!window.confirm(T("confirmDelete"))) return;
      open = null;
      save(id, null);
    });

    links.appendChild(resolve);
    links.appendChild(remove);
    box.appendChild(links);

    setTimeout(function () { input.focus(); }, 0);
    return box;
  }

  function composer() {
    var box = document.createElement("div");
    box.className = "kz-card";
    box.style.left = draft.left + "px";
    box.style.top = draft.top + "px";
    box.addEventListener("click", function (e) { e.stopPropagation(); });

    var name = null;
    if (!author()) {
      name = document.createElement("input");
      name.placeholder = T("yourName");
      box.appendChild(name);
    }

    var input = document.createElement("textarea");
    input.rows = 3;
    input.placeholder = T("notePlaceholder");
    box.appendChild(input);

    var row = document.createElement("div");
    row.className = "row";

    var cancel = document.createElement("button");
    cancel.textContent = T("cancel");
    cancel.addEventListener("click", function () { draft = null; paint(); });

    var post = document.createElement("button");
    post.className = "primary";
    post.textContent = T("post");
    post.addEventListener("click", function () {
      var text = input.value.trim();
      if (!text) return input.focus();
      if (name && name.value.trim()) KZ.remember(AUTHOR_KEY, name.value.trim());

      var id = "n" + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
      var note = {
        page: DC.page, el: draft.el, x: draft.x, y: draft.y,
        author: author(), text: text, at: Date.now(), resolved: false, replies: []
      };
      draft = null;
      open = id;
      save(id, note);
    });

    row.appendChild(cancel);
    row.appendChild(post);
    box.appendChild(row);

    setTimeout(function () { (name || input).focus(); }, 0);
    return box;
  }

  /* ---------------------------------------------------------------- input */

  document.addEventListener("click", function (e) {
    if (!active) return;
    if (layer.contains(e.target) || UI.bar.contains(e.target)) return;
    var signin = document.getElementById("kz-signin");
    if (signin && signin.contains(e.target)) return;

    e.preventDefault();
    e.stopPropagation();

    if (open) { open = null; paint(); return; }
    if (draft) { draft = null; paint(); return; }

    /* Anchor to whatever is under the cursor, so the note travels with that
       element rather than with a point on the page. */
    var target = document.elementFromPoint(e.clientX, e.clientY);
    var anchor = target && target.closest ? target.closest("[data-kz-el]") : null;
    var root = document.getElementById("dc-root");
    var box = (anchor || root).getBoundingClientRect();

    draft = {
      el: anchor ? anchor.getAttribute("data-kz-el") : "root",
      x: box.width ? (e.clientX - box.left) / box.width : 0,
      y: box.height ? (e.clientY - box.top) / box.height : 0,
      left: e.clientX + window.scrollX,
      top: e.clientY + window.scrollY
    };
    paint();
  }, true);

  document.addEventListener("keydown", function (e) {
    if (!active || e.key !== "Escape") return;
    if (draft) { draft = null; paint(); }
    else if (open) { open = null; paint(); }
  });

  /* Re-place after anything that can move the anchors: a re-render, a
     resize, or a photo finishing loading. */
  var queued = false;
  function reflow() {
    if (queued) return;
    queued = true;
    requestAnimationFrame(function () { queued = false; paint(); });
  }

  window.addEventListener("resize", reflow);
  window.addEventListener("dc:render", reflow);
  window.addEventListener("kz:lang", reflow);   /* re-renders cards in the new language */
  window.addEventListener("kz:image", reflow);
  window.addEventListener("kz:notes", reflow);
  window.addEventListener("load", reflow);

  KZ.ready.then(function () { badge(); });
  badge();
})();
