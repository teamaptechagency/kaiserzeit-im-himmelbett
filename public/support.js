/*
 * support.js — runtime for the .dc.html design prototypes.
 *
 * The handoff bundle shipped the 7 .dc.html pages but not the runtime they
 * were authored against, so this reimplements it. The pages are kept byte
 * for byte as delivered; everything they rely on is implemented here:
 *
 *   <x-dc>                     root template element
 *   <helmet>                   contents are relocated into <head>
 *   {{ expr }}                 interpolation in text and attribute values
 *   <sc-if value="{{ x }}">    conditional block
 *   <sc-for list="{{ xs }}" as="item">   repeat block, exposes $index
 *
 * The template is re-parsed from the page source rather than read out of the
 * live DOM. Booking.dc.html nests a <sc-for> inside a <select>, and the HTML
 * parser discards unknown elements in that position, which would silently
 * collapse the guest dropdown to a single option. Rewriting the sc-* tags to
 * <template> before parsing sidesteps that: <template> is legal everywhere,
 * and a DOMParser document is inert, so the template never loads images or
 * compiles the inline handler attributes.
 *   onClick / onChange / ...   handler bound to a value from renderVals()
 *   hint-placeholder-*         authoring-tool hints, ignored at runtime
 *   <script type="text/x-dc">  page logic: `class Component extends DCLogic`
 *
 * DCLogic gives the page `state`, `setState()`, `renderVals()` and an
 * optional `componentDidMount()`. Renders are coalesced into one frame and
 * patched onto the existing DOM rather than replacing it, so a focused text
 * input keeps its focus and caret while the client types.
 */
(function () {
  "use strict";

  var SVG_NS = "http://www.w3.org/2000/svg";

  /* Hide the raw template immediately. This file is loaded from <head>, so
     the rule lands before the body is parsed and nothing flashes. */
  var hide = document.createElement("style");
  hide.textContent = "x-dc{display:none!important}";
  (document.head || document.documentElement).appendChild(hide);

  /* Loaded from here rather than from each page so the .dc.html files stay
     exactly as delivered, and so the stylesheet is in flight before the body
     renders. */
  var responsive = document.createElement("link");
  responsive.rel = "stylesheet";
  responsive.href = "responsive.css";
  (document.head || document.documentElement).appendChild(responsive);

  /* Started here rather than on DOMContentLoaded so the round trip overlaps
     with parsing. The response is already in the HTTP cache. */
  var sourcePromise = null;
  try {
    sourcePromise = fetch(location.href, { credentials: "same-origin" })
      .then(function (res) { return res.ok ? res.text() : null; })
      .catch(function () { return null; });
  } catch (err) {
    sourcePromise = Promise.resolve(null);
  }

  /* ---------------------------------------------------------------- css --
     Style values arrive two ways: as a JS object from a {{ binding }}, or as
     a literal attribute string. Both use camelCase property names in these
     files (borderRadius, fontFamily), which browsers ignore, so both have to
     be normalised to kebab-case before they reach the DOM. */

  var UNITLESS = {
    animationIterationCount: 1, borderImageOutset: 1, borderImageSlice: 1,
    borderImageWidth: 1, boxFlex: 1, boxFlexGroup: 1, boxOrdinalGroup: 1,
    columnCount: 1, columns: 1, flex: 1, flexGrow: 1, flexShrink: 1,
    flexNegative: 1, flexPositive: 1, flexOrder: 1, gridArea: 1, gridRow: 1,
    gridRowEnd: 1, gridRowSpan: 1, gridRowStart: 1, gridColumn: 1,
    gridColumnEnd: 1, gridColumnSpan: 1, gridColumnStart: 1, fontWeight: 1,
    lineClamp: 1, lineHeight: 1, opacity: 1, order: 1, orphans: 1, tabSize: 1,
    widows: 1, zIndex: 1, zoom: 1, fillOpacity: 1, floodOpacity: 1,
    stopOpacity: 1, strokeDasharray: 1, strokeDashoffset: 1,
    strokeMiterlimit: 1, strokeOpacity: 1, strokeWidth: 1
  };

  function kebab(name) {
    if (name.indexOf("--") === 0) return name;
    var out = name.replace(/([a-z0-9])([A-Z])/g, "$1-$2").toLowerCase();
    /* WebkitBackgroundClip -> -webkit-background-clip */
    if (/^[A-Z]/.test(name)) out = "-" + out;
    return out;
  }

  /* Split on `sep`, ignoring separators nested in brackets or quotes, so
     values like linear-gradient(135deg,#e6c184,#b9803f) survive intact. */
  function splitTop(str, sep) {
    var parts = [], depth = 0, quote = 0, start = 0;
    for (var i = 0; i < str.length; i++) {
      var c = str.charAt(i);
      if (quote) { if (c === quote && str.charAt(i - 1) !== "\\") quote = 0; continue; }
      if (c === '"' || c === "'") { quote = c; continue; }
      if (c === "(" || c === "[") depth++;
      else if (c === ")" || c === "]") depth--;
      else if (c === sep && depth === 0) { parts.push(str.slice(start, i)); start = i + 1; }
    }
    parts.push(str.slice(start));
    return parts;
  }

  function firstTopColon(str) {
    var depth = 0, quote = 0;
    for (var i = 0; i < str.length; i++) {
      var c = str.charAt(i);
      if (quote) { if (c === quote && str.charAt(i - 1) !== "\\") quote = 0; continue; }
      if (c === '"' || c === "'") { quote = c; continue; }
      if (c === "(" || c === "[") depth++;
      else if (c === ")" || c === "]") depth--;
      else if (c === ":" && depth === 0) return i;
    }
    return -1;
  }

  function styleObjectToCss(obj) {
    var out = "";
    for (var key in obj) {
      if (!Object.prototype.hasOwnProperty.call(obj, key)) continue;
      var val = obj[key];
      if (val == null || val === false || val === "") continue;
      if (typeof val === "number" && val !== 0 && !UNITLESS[key]) val = val + "px";
      out += kebab(key) + ":" + val + ";";
    }
    return out;
  }

  /* The design lays every section out as
     repeat(auto-fit, minmax(<n>px, 1fr)). That floor is a hard minimum, so on
     a phone narrower than <n> the track overflows the viewport instead of
     collapsing — the property cards (380px) run off the edge of a 375px
     screen. Clamping the floor with min(<n>px, 100%) is the standard fix and
     leaves the desktop layout identical, so it is applied to every grid
     rather than patched section by section. */
  function clampGridTracks(value) {
    return value.replace(/minmax\(\s*(\d+(?:\.\d+)?)(px|rem|em)\s*,/g, "minmax(min($1$2,100%),");
  }

  function normaliseCssText(css) {
    var decls = splitTop(css, ";"), out = "";
    for (var i = 0; i < decls.length; i++) {
      var decl = decls[i].trim();
      if (!decl) continue;
      var at = firstTopColon(decl);
      if (at < 0) continue;
      var prop = kebab(decl.slice(0, at).trim());
      var value = decl.slice(at + 1).trim();
      if (prop === "grid-template-columns") value = clampGridTracks(value);
      out += prop + ":" + value + ";";
    }
    return out;
  }

  function toCss(value) {
    if (value == null || value === false) return "";
    if (typeof value === "object") return styleObjectToCss(value);
    return normaliseCssText(String(value));
  }

  /* --------------------------------------------------------- expressions --
     Compiled once per source string and cached. `with` resolves names
     against the scope chain, so a <sc-for> can shadow an outer binding by
     handing down an Object.create(parentScope). */

  var compiled = Object.create(null);

  function compile(expr) {
    var fn = compiled[expr];
    if (!fn) {
      try {
        fn = new Function("$s", "with($s){return (" + expr + ");}");
      } catch (err) {
        console.error("[dc] bad expression: {{" + expr + "}}", err);
        fn = function () { return ""; };
      }
      compiled[expr] = fn;
    }
    return fn;
  }

  function evaluate(expr, scope) {
    try {
      return compile(expr)(scope);
    } catch (err) {
      console.error("[dc] failed to evaluate {{" + expr + "}}", err);
      return undefined;
    }
  }

  var BINDING = /\{\{([\s\S]*?)\}\}/g;
  var SOLE_BINDING = /^\s*\{\{([\s\S]*?)\}\}\s*$/;

  /* ---------------------------------------------------------- overrides --
     Copy the client edits on the live preview is stored per page, per
     language, keyed by the original string rather than by binding path. The
     same wording repeated in the desktop nav and the mobile menu therefore
     changes together, and a {{ item }} inside a sc-for needs no index in its
     key. The key is always derived from the *template* value, so an edit
     stays attached to its slot no matter how often it is re-edited. */

  var PAGE = decodeURIComponent(location.pathname.split("/").pop() || "Home.dc.html");
  var currentLang = "de";

  function textKey(value) {
    return PAGE + "|" + currentLang + "|" + value;
  }

  function overrideText(value) {
    var store = window.KZ && window.KZ.texts;
    if (!store || !value || !value.trim()) return value;
    var key = textKey(value);
    return Object.prototype.hasOwnProperty.call(store, key) ? store[key] : value;
  }

  /* A value the template uses on its own (style, onClick, list, disabled)
     keeps its real type; anything interleaved with literal text is
     stringified, so id="popup-{{ apt.slotId }}" still works. */
  function resolve(raw, scope) {
    var sole = raw.match(SOLE_BINDING);
    if (sole) return evaluate(sole[1], scope);
    BINDING.lastIndex = 0;
    return raw.replace(BINDING, function (_, expr) {
      var v = evaluate(expr, scope);
      return v == null || v === false ? "" : String(v);
    });
  }

  function asText(value) {
    return value == null || value === false ? "" : String(value);
  }

  /* --------------------------------------------------------------- vdom --
     A plain-object tree is rebuilt from the template on every render and
     diffed positionally against the previous one. The structure is stable
     apart from sc-if / sc-for, so positional matching reuses nearly every
     node and form state survives untouched. */

  var PROP_ATTRS = { value: 1, checked: 1, disabled: 1, selected: 1 };

  function buildList(templateNodes, scope, out) {
    for (var i = 0; i < templateNodes.length; i++) build(templateNodes[i], scope, out);
  }

  function build(node, scope, out) {
    if (node.nodeType === 3) {                       /* text */
      var raw = node.nodeValue;
      var original = raw.indexOf("{{") < 0 ? raw : asText(resolve(raw, scope));
      /* `source` is the un-overridden string; the editor reads it back off
         the DOM node to know which key an edit belongs to. */
      out.push({ text: overrideText(original), source: original });
      return;
    }
    if (node.nodeType !== 1) return;                 /* drop comments etc. */

    var tag = node.tagName.toLowerCase();

    /* sc-if / sc-for reach us as <template> after the source rewrite, and as
       their original tags when we fell back to the live DOM. */
    var isIf = tag === "sc-if" || node.hasAttribute("data-dc-if");
    var isFor = tag === "sc-for" || node.hasAttribute("data-dc-for");
    var body = tag === "template" ? node.content.childNodes : node.childNodes;

    if (isIf) {
      if (resolve(node.getAttribute("value") || "", scope)) {
        buildList(body, scope, out);
      }
      return;
    }

    if (isFor) {
      var list = resolve(node.getAttribute("list") || "", scope);
      if (!list) return;
      var alias = node.getAttribute("as") || "item";
      for (var i = 0; i < list.length; i++) {
        var child = Object.create(scope);
        child[alias] = list[i];
        child.$index = i;
        buildList(body, child, out);
      }
      return;
    }

    var vnode = {
      tag: tag,
      svg: node.namespaceURI === SVG_NS,
      attrs: node.__dcTid ? { "data-kz-el": node.__dcTid } : {},
      props: {},
      events: {},
      style: null,
      children: []
    };

    var attrs = node.attributes;
    for (var a = 0; a < attrs.length; a++) {
      var name = attrs[a].name;
      var raw2 = attrs[a].value;

      if (name.indexOf("hint-") === 0) continue;     /* authoring-tool only */

      /* The HTML parser lowercases attribute names, so onClick arrives as
         onclick and the remainder is already the DOM event name. */
      if (/^on[a-z]/.test(name) && SOLE_BINDING.test(raw2)) {
        var handler = resolve(raw2, scope);
        if (typeof handler === "function") vnode.events[name.slice(2)] = handler;
        continue;
      }

      var value = raw2.indexOf("{{") < 0 ? raw2 : resolve(raw2, scope);

      if (name === "style") { vnode.style = toCss(value); continue; }
      if (PROP_ATTRS[name]) { vnode.props[name] = value; continue; }
      if (value == null || value === false) continue;
      vnode.attrs[name] = asText(value);
    }

    buildList(node.childNodes, scope, vnode.children);
    out.push(vnode);
  }

  /* ------------------------------------------------------------ patching */

  function bindEvents(el, vnode) {
    el.__dcEvents = vnode.events;
    var bound = el.__dcBound || (el.__dcBound = {});
    for (var type in vnode.events) {
      if (bound[type]) continue;
      bound[type] = true;
      /* Bound once and dispatched through __dcEvents, so re-renders never
         detach and reattach listeners. */
      el.addEventListener(type, function (event) {
        var map = el.__dcEvents;
        var fn = map && map[event.type];
        if (fn) fn(event);
      });
      /* React-style onChange means "as the user types". */
      if (type === "change" && !bound.__inputAlias) {
        bound.__inputAlias = true;
        el.addEventListener("input", function (event) {
          var map = el.__dcEvents;
          var fn = map && map.change;
          if (fn) fn(event);
        });
      }
    }
  }

  function applyProps(el, oldVNode, vnode) {
    var name;

    if (vnode.style !== (oldVNode ? oldVNode.style : null)) {
      if (vnode.style) el.setAttribute("style", vnode.style);
      else el.removeAttribute("style");
    }

    for (name in vnode.attrs) {
      if (!oldVNode || oldVNode.attrs[name] !== vnode.attrs[name]) {
        el.setAttribute(name, vnode.attrs[name]);
      }
    }
    if (oldVNode) {
      for (name in oldVNode.attrs) {
        if (!(name in vnode.attrs)) el.removeAttribute(name);
      }
    }

    for (name in vnode.props) {
      var value = vnode.props[name];
      if (name === "value") {
        /* Writing an unchanged value would reset the caret mid-word. */
        var next = asText(value);
        if (el.value !== next) el.value = next;
      } else {
        var flag = !!value && value !== "false";
        if (el[name] !== flag) el[name] = flag;
      }
    }

    bindEvents(el, vnode);
  }

  function createNode(vnode) {
    if (vnode.text !== undefined) {
      vnode.el = document.createTextNode(vnode.text);
      vnode.el.__dcSource = vnode.source;
      return vnode.el;
    }
    var el = vnode.svg
      ? document.createElementNS(SVG_NS, vnode.tag)
      : document.createElement(vnode.tag);
    vnode.el = el;
    /* Children first: setting `value` on a <select> only takes effect once
       its <option> elements exist. */
    for (var i = 0; i < vnode.children.length; i++) {
      el.appendChild(createNode(vnode.children[i]));
    }
    applyProps(el, null, vnode);
    return el;
  }

  function patchNode(parent, oldVNode, vnode) {
    var wasText = oldVNode.text !== undefined;
    var isText = vnode.text !== undefined;

    if (wasText !== isText || (!isText && oldVNode.tag !== vnode.tag)) {
      parent.replaceChild(createNode(vnode), oldVNode.el);
      return;
    }
    if (isText) {
      vnode.el = oldVNode.el;
      vnode.el.__dcSource = vnode.source;
      if (oldVNode.text !== vnode.text) vnode.el.nodeValue = vnode.text;
      return;
    }

    var el = (vnode.el = oldVNode.el);
    patchChildren(el, oldVNode.children, vnode.children);
    applyProps(el, oldVNode, vnode);
  }

  function patchChildren(el, oldChildren, children) {
    var shared = Math.min(oldChildren.length, children.length), i;
    for (i = 0; i < shared; i++) patchNode(el, oldChildren[i], children[i]);
    for (i = shared; i < children.length; i++) el.appendChild(createNode(children[i]));
    for (i = oldChildren.length - 1; i >= shared; i--) el.removeChild(oldChildren[i].el);
  }

  /* --------------------------------------------------------- page driver */

  var instance = null;
  var template = null;
  var root = null;
  var previous = null;
  var pending = false;
  var mounted = false;

  function render() {
    pending = false;
    if (!instance || !template) return;

    currentLang = (instance.state && instance.state.lang) || "de";

    var scope;
    try {
      scope = instance.renderVals() || {};
    } catch (err) {
      console.error("[dc] renderVals() threw", err);
      return;
    }

    var next = [];
    buildList(template, scope, next);

    if (previous) patchChildren(root, previous, next);
    else for (var i = 0; i < next.length; i++) root.appendChild(createNode(next[i]));
    previous = next;

    if (!mounted) {
      mounted = true;
      if (typeof instance.componentDidMount === "function") {
        try { instance.componentDidMount(); } catch (err) { console.error("[dc] componentDidMount() threw", err); }
      }
    }

    window.dispatchEvent(new CustomEvent("dc:render"));
  }

  function scheduleRender() {
    if (pending) return;
    pending = true;
    requestAnimationFrame(render);
  }

  function DCLogic() {
    if (!this.state) this.state = {};
  }
  DCLogic.prototype.setState = function (update) {
    var patch = typeof update === "function" ? update(this.state) : update;
    if (!patch) return;
    var merged = {};
    for (var k in this.state) merged[k] = this.state[k];
    for (var j in patch) merged[j] = patch[j];
    this.state = merged;
    scheduleRender();
  };
  DCLogic.prototype.renderVals = function () { return {}; };
  window.DCLogic = DCLogic;

  /* Pull <x-dc>…</x-dc> out of the raw source, swap the sc-* tags for
     <template>, and parse it in an inert document. Returns null if the
     source is unusable, in which case the caller falls back to the live DOM. */
  function parseTemplate(source) {
    if (!source) return null;
    var open = source.indexOf("<x-dc>");
    var close = source.lastIndexOf("</x-dc>");
    if (open < 0 || close < 0 || close < open) return null;

    var markup = source.slice(open + "<x-dc>".length, close)
      .replace(/<sc-if(\s|>)/g, "<template data-dc-if$1")
      .replace(/<sc-for(\s|>)/g, "<template data-dc-for$1")
      .replace(/<\/sc-(if|for)>/g, "</template>");

    try {
      var doc = new DOMParser().parseFromString(
        "<!doctype html><body>" + markup + "</body>", "text/html"
      );
      /* <helmet> is handled from the live document, where its styles and
         scripts have already taken effect. */
      var stale = doc.querySelector("helmet");
      if (stale) stale.parentNode.removeChild(stale);
      return Array.prototype.slice.call(doc.body.childNodes);
    } catch (err) {
      console.error("[dc] could not parse page source", err);
      return null;
    }
  }

  /* Number every element in the template up front, so the rendered DOM can
     carry a data-kz-el that means the same thing on every load. Background
     overrides are keyed on it. Numbering has to cover the whole tree, not
     just the branches that happen to render, or a hidden <sc-if> would
     renumber everything after it the moment it opened. */
  function numberTemplate(nodes, counter) {
    for (var i = 0; i < nodes.length; i++) {
      var node = nodes[i];
      if (node.nodeType !== 1) continue;
      node.__dcTid = ++counter.n;
      var kids = node.tagName.toLowerCase() === "template" ? node.content.childNodes : node.childNodes;
      counter = numberTemplate(kids, counter);
    }
    return counter;
  }

  function start(source) {
    var xdc = document.querySelector("x-dc");
    if (!xdc) { console.error("[dc] no <x-dc> element on this page"); return; }

    /* <helmet> holds the real document head: fonts, page styles, the
       image-slot script. Relocate it before the template is captured so it
       is never part of the re-rendered tree. */
    var helmet = xdc.querySelector("helmet");
    if (helmet) {
      while (helmet.firstChild) document.head.appendChild(helmet.firstChild);
      helmet.parentNode.removeChild(helmet);
    }

    template = parseTemplate(source);
    if (!template) {
      console.warn("[dc] using the live DOM as template; a <sc-for> inside a <select> will not expand");
      template = Array.prototype.slice.call(xdc.childNodes);
    }
    numberTemplate(template, { n: 0 });

    root = document.createElement("div");
    root.id = "dc-root";
    xdc.parentNode.insertBefore(root, xdc);
    xdc.parentNode.removeChild(xdc);

    var source = document.querySelector("script[data-dc-script]");
    if (!source) { console.error("[dc] no page script found"); return; }

    var Component;
    try {
      Component = new Function("DCLogic", source.textContent + "\n;return Component;")(DCLogic);
    } catch (err) {
      console.error("[dc] page script failed to load", err);
      return;
    }

    instance = new Component();
    render();
  }

  /* Exposed so the editor can force a repaint after saving an edit, and read
     which page/language a given string belongs to. */
  window.DC = {
    rerender: scheduleRender,
    page: PAGE,
    lang: function () { return currentLang; },
    textKey: textKey
  };

  function boot() {
    /* Saved copy edits have to be in hand before the first paint, otherwise
       the original wording flashes and then swaps. */
    var state = (window.KZ && window.KZ.ready) || Promise.resolve();
    Promise.all([sourcePromise, state.catch(function () {})])
      .then(function (results) { start(results[0]); },
            function () { start(null); });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
